<?php

declare(strict_types=1);

namespace Simphle\Action\Runner\Command;

use Exception;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Simphle\Action\Queue\Exception\QueueRuntimeException;
use Simphle\Action\Queue\QueueMessage;
use Simphle\Action\Queue\QueueServiceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Simphle\Action\Task\ScheduledTaskServiceInterface;
use Simphle\Action\Task\ScheduledTaskStatus;

class Scheduled extends Command
{
    protected static string $defaultName = 'scheduled';

    protected static string $defaultDescription = 'Runs scheduled tasks';

    protected LoggerInterface $logger;

    protected QueueServiceInterface $queue;

    public function __construct(
        protected ContainerInterface $container,
        protected ScheduledTaskServiceInterface $scheduledTaskService
    ) {
        parent::__construct();
        $this->logger = $container->get('logger');
        $this->queue = $this->container->get('queue');
    }

    protected function configure(): void
    {
        $this->setHelp('Runs tasks scheduled for delayed execution');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->logger->info('Running scheduled tasks...');

            // Loads a list of tasks ready to run
            $tasks = $this->scheduledTaskService->getRunnableTasks();
            $this->logger->info(
                sprintf('Found %d tasks to process', count($tasks))
            );
            foreach ($tasks as $task) {
                $this->logger->info('Processing task', [
                    'id' => $task->id,
                    'name' => $task->name
                ]);
                try {
                    // Transform the scheduled task into a Queue item
                    // with an action type (e.g. orders.sendConfirmation)
                    // and parameters from the DB record
                    $message = new QueueMessage(
                        action: $task->action,
                        params: $task->params,
                        id: $task->id
                    );
                    $this->queue->post([$message], $task->queue ?? 'default');
                    $this->logger->info('Task successfully enqueued', $task->toArray());

                    // Once the task is added to a Queue is marked as processed and completed
                    $this->scheduledTaskService->update($task, [
                        'status' => ScheduledTaskStatus::Complete,
                    ]);
                } catch (QueueRuntimeException $e) {
                    // Will be retried next round
                    $this->logger->error(
                        'Unable to enqueue task: ' . $e->getMessage(),
                        $task->toArray()
                    );
                } catch (Exception $e) {
                    // catch DatabaseException: unable to update
                    // (it may need manual intervention in order to prevent duplicate execution)
                    $this->logger->error(
                        'Unable to process task: ' . $e->getMessage(),
                        $task->toArray()
                    );
                }
            }
            $this->scheduledTaskService->cleanup();
        } catch (RuntimeException $e) {
            $output->writeln($e->getMessage());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
