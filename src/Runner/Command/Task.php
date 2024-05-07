<?php

/*
 * This file is part of the Simphle Action package.
 *
 * (c) Vito Tardia <vito@tardia.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Simphle\Action\Runner\Command;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Simphle\Action\Task\TaskFactoryInterface;

class Task extends Command
{
    protected static string $defaultName = 'task';

    protected static string $defaultDescription = 'Runs the desired task';

    protected LoggerInterface $logger;

    public function __construct(
        protected ContainerInterface $container,
        protected TaskFactoryInterface $taskFactory,
        protected string $user
    ) {
        parent::__construct();
        $this->logger = $container->get('logger');
    }

    protected function configure(): void
    {
        $this->setHelp('Runs the desired task');
        $this->addArgument(
            'action',
            InputArgument::REQUIRED,
            'The action to run'
        );
        $this->addOption(
            name: 'params',
            mode: InputOption::VALUE_REQUIRED,
            description: 'JSON string of task arguments',
            default: null
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $params = $input->getOption('params');
        $this->setProcessTitle('runner-' . $action);
        try {
            $task = $this->taskFactory->get($action);
            $output->writeln(
                sprintf("Running '%s' (%s)", $action, get_class($task))
            );
            $params = $params ? json_decode($params, associative: true) : [];
            $task->run($params);
        } catch (RuntimeException $e) {
            $output->writeln($e->getMessage());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
