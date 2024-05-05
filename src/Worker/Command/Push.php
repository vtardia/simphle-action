<?php

declare(strict_types=1);

namespace Simphle\Action\Worker\Command;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Simphle\Action\Queue\QueueMessage;
use Simphle\Action\Queue\QueueServiceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Push extends Command
{
    protected static string $defaultName = 'push';

    protected static string $defaultDescription = 'Push a message to the given queue';

    protected QueueServiceInterface $queue;

    public function __construct(
        protected ContainerInterface $container
    ) {
        parent::__construct();
        $this->queue = $this->container->get('queue');
    }

    protected function configure(): void
    {
        $this->setHelp('Push one or more messages to a selected queue');
        $this->addOption(
            name: 'queue',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Target queue',
            default: 'default'
        );
        $this->addArgument('messages', InputArgument::IS_ARRAY, 'Messages to enqueue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $queue = $input->getOption('queue');
            $output->writeln(sprintf("Pushing messages to queue '%s'", $queue));
            $messages = $input->getArgument('messages');
            if (!empty($messages)) {
                $this->queue->post(
                    array_map(static function (string $data): QueueMessage {
                        return QueueMessage::decode($data);
                    }, $messages),
                    $queue
                );
            }
        } catch (RuntimeException $e) {
            $output->writeln($e->getMessage());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
