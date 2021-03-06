<?php

namespace Swarrot\SwarrotBundle\Command;

use Swarrot\Consumer;
use Swarrot\Processor\ProcessorInterface;
use Swarrot\Processor\Stack\Builder;
use Swarrot\SwarrotBundle\Broker\FactoryInterface;
use Swarrot\SwarrotBundle\Processor\ProcessorConfiguratorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SwarrotCommand extends Command
{
    protected $swarrotFactory;
    protected $name;
    protected $connectionName;
    protected $processor;
    protected $processorConfigurators;
    protected $extras;
    protected $queue;

    public function __construct(
        FactoryInterface $swarrotFactory,
        $name,
        $connectionName,
        ProcessorInterface $processor,
        array $processorConfigurators,
        array $extras,
        $queue = null
    ) {
        $this->swarrotFactory = $swarrotFactory;
        $this->name = $name;
        $this->connectionName = $connectionName;
        $this->processor = $processor;
        $this->processorConfigurators = $processorConfigurators;
        $this->extras = $extras;
        $this->queue = $queue;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $defaultPollInterval = isset($this->extras['poll_interval']) ? $this->extras['poll_interval'] : 500000;

        $this
            ->setName('swarrot:consume:'.$this->name)
            ->setDescription('Consume messages from a given queue')
            ->addArgument('queue', InputArgument::OPTIONAL, 'Queue to consume', $this->queue)
            ->addArgument('connection', InputArgument::OPTIONAL, 'Connection to use', $this->connectionName)
            ->addOption(
                'poll-interval',
                null,
                InputOption::VALUE_REQUIRED,
                'Poll interval (in micro-seconds)',
                $defaultPollInterval
            )
            ->setHelp(<<<EOT
The <info>%command.name%</info> command will consume messages from the queue you gave in argument.

    <info>php %command.full_name%</info>

You can use <info>name</info> & <info>connection</info> arguments to consume any queue on any RabbitMQ cluster.

You can also optionally specify the poll interval to use:

    <info>php %command.full_name% --poll-interval=$defaultPollInterval</info>
EOT
            );

        /** @var ProcessorConfiguratorInterface $processorConfigurator */
        foreach ($this->processorConfigurators as $processorConfigurator) {
            foreach ($processorConfigurator->getCommandOptions() as $args) {
                call_user_func_array([$this, 'addOption'], $args);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $options = $this->getOptions($input);

        $stack = new Builder();
        /** @var ProcessorConfiguratorInterface $processorConfigurator */
        foreach ($this->processorConfigurators as $processorConfigurator) {
            if ($processorConfigurator->isEnabled()) {
                call_user_func_array([$stack, 'push'], $processorConfigurator->getProcessorArguments($options));
            }
        }

        $processor = $stack->resolve($this->processor);
        $optionsResolver = new OptionsResolver();
        if (method_exists($optionsResolver, 'setDefined')) {
            $optionsResolver->setDefined(['queue', 'connection']);
        } else {
            $optionsResolver->setOptional(['queue', 'connection']);
        }

        $messageProvider = $this->swarrotFactory->getMessageProvider($options['queue'], $options['connection']);

        $consumer = new Consumer($messageProvider, $processor, $optionsResolver);

        $consumer->consume($options);

        return 0;
    }

    /**
     * getOptions.
     *
     * @return array
     */
    protected function getOptions(InputInterface $input)
    {
        $options = $this->extras + [
            'queue' => $input->getArgument('queue'),
            'connection' => $input->getArgument('connection'),
            'poll_interval' => (int) $input->getOption('poll-interval'),
        ];

        /** @var ProcessorConfiguratorInterface $processorConfigurator */
        foreach ($this->processorConfigurators as $processorConfigurator) {
            $processorOptions = $processorConfigurator->resolveOptions($input);

            if ($processorConfigurator->isEnabled()) {
                $options += $processorOptions;
            }
        }

        return $options;
    }
}
