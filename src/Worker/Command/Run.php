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

namespace Simphle\Action\Worker\Command;

use Exception;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Simphle\Action\Queue\QueueServiceInterface;
use Simphle\Action\Task\Exception\PermanentFailure;
use Simphle\Action\Task\Exception\TemporaryFailure;
use Simphle\Action\Task\TaskFactoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Run extends Command
{
    protected ?OutputInterface $output = null;

    protected LoggerInterface $logger;

    protected bool $terminate = false;

    protected bool $debug = false;

    protected static string $defaultName = 'run';

    protected static string $defaultDescription = 'Start worker and run jobs';

    protected QueueServiceInterface $queue;

    protected int $jobsPerMinute = 300;

    protected int $minimumJobTime; // milliseconds

    public function __construct(
        protected ContainerInterface $container,
        protected TaskFactoryInterface $taskFactory,
        protected string $user
    ) {
        parent::__construct();
        $this->logger = $container->get('logger');
        $this->queue = $this->container->get('queue');

        // To keep our rate, at least how long must each job take in ms?
        // default is 0.2s (200ms)
        $this->minimumJobTime = (int) (60 / $this->jobsPerMinute) * 1000;

        $onSignal = function (int $signal, mixed $sigInfo): void {
            $signals = [
                SIGTERM => 'SIGTERM',
                SIGINT => 'SIGINT',
                SIGQUIT => 'SIGQUIT'
            ];
            switch ($signal) {
                case SIGTERM:
                    // shutdown tasks, or 'kill <pid>'
                case SIGINT:
                    // ctrl+c pressed
                case SIGQUIT:
                    $message = sprintf(
                        'Received %s signal, will exit after current job finishes',
                        $signals[$signal]
                    );
                    $this->logger->info($message, ['sigInfo' => $sigInfo]);
                    $this->output?->writeln($message);
                    $this->terminate = true;
                    return;
            }
        };
        pcntl_signal(SIGTERM, $onSignal);
        pcntl_signal(SIGINT, $onSignal);
        pcntl_signal(SIGQUIT, $onSignal);
    }

    protected function configure(): void
    {
        $this->setHelp('Start the queue worker and process queue tasks');
        $this->addOption(
            'debug',
            null,
            InputOption::VALUE_NONE,
            'Dry run with verbose logging'
        );
        $this->addOption(
            name: 'queue',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Target queue',
            default: 'default'
        );
    }

    protected function time(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Sleeps for a variable amount of time
     * depending on the last job duration
     * @param int $jobTime Last job duration in milliseconds
     */
    protected function sleep(int $jobTime): void
    {
        // Using the defaults, if a job lasted less than 200ms
        // sleep for (200 - jobDuration)ms
        if ($jobTime < $this->minimumJobTime) {
            /** @psalm-suppress ArgumentTypeCoercion */
            usleep(($this->minimumJobTime - $jobTime) * 1000);
        }
    }

    protected function updateRequestId(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = bin2hex(random_bytes(16));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = $input->getOption('queue');
        $this->setProcessTitle('worker-' . $queue);

        // Capture output interface to be used by signal processing handler
        $this->output = $output;

        // Set custom options
        $this->debug = $input->getOption('debug');

        if ($this->debug) {
            $this->logger->info('Running in DEBUG mode');
        }
        $this->logger->info('Starting Worker loop', [
            'user' => $this->user,
            'queue' => $queue
        ]);

        while (!$this->terminate) {
            // Update request ID for better log tracking
            $this->updateRequestId();

            $start = $this->time();

            $message = null;
            try {
                // Wait for messages (can be blocking, with ~10sec timeout)
                $message = $this->queue->get($queue);
                if (!is_null($message)) {
                    // Convert message into a runnable task
                    $task = $this->taskFactory->get($message->action);

                    if ($this->debug) {
                        $this->logger->debug('Debugging queue message', [
                            'message' => $message
                        ]);
                        // Bounce message back in queue
                        $this->queue->nack($message, $queue);
                    } else {
                        $this->logger->info('Processing queue message', [
                            'message' => $message
                        ]);

                        // Run the task
                        try {
                            $task->run($message->params);
                            // ACK as success
                            $this->queue->ack($message, $queue);
                            $this->logger->info('Task completed successfully');
                        } catch (TemporaryFailure $e) {
                            // NACK and retry
                            $this->logger->warning('Temporary failure: ' . $e->getMessage());
                            $this->queue->nack($message, $queue);
                        } catch (PermanentFailure $e) {
                            // ACK as failure and log
                            $this->logger->error('Permanent failure: ' . $e->getMessage());
                            $this->queue->ack($message, $queue);
                        }
                    }
                }
            } catch (InvalidArgumentException $e) {
                // ACK as failure and log
                $this->logger->error('Permanent failure - ' . $e->getMessage());
                if (!is_null($message)) {
                    $this->queue->ack($message, $queue);
                }
            } catch (Exception $e) {
                // We don't want the whole worker crashing on a task-related exception
                $this->logger->error($e->getMessage());
            }
            gc_collect_cycles();
            pcntl_signal_dispatch();

            // Sleep for the remaining time or start another job
            $this->sleep($this->time() - $start);
        }
        return Command::SUCCESS;
    }
}
