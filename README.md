# Simphle Action

Simphle Action is a task-oriented library for PHP applications that provides:

 - a task runner, to run tasks directly from the command line;
 - a task scheduler, to run scheduled tasks within a cron facility;
 - a background worker to run tasks from a message queue.

A task is a self-contained unit of work that can be as simple as "send a welcome email to use 123" or as complicated as "generate that super complex report for the management". You can also call it an action or an operation.

## Install

```console
composer install vtardia/simphle-action
```

## Usage

### Creating tasks

A task is represented by a PHP object that implements the `Simphle\Action\Task\TaskInterface` which requires the single method `run(array $params = []): void`. Task runs don't have a return value, they either succeed silently or fail by throwing exceptions.

An example task can be written like this:

```php
use Simphle\Action\Task\TaskInterface;
use Simphle\Action\Task\Exception\PermanentFailure;
use Simphle\Action\Task\Exception\TemporaryFailure;

class SendWelcomeEmail implements TaskInterface
{
    public function __construct(
        protected EmailService $emailService,
        protected UserService $userService
    ) {
    }
    
    public function run(array $params = []): void
    {
        try {
            if (!isset($params['user_id']) {
                throw new InvalidArgumentException('Missing user id');
            }
            $user = $this->userService->find((int) $params['user_id'])
                ?? throw new PermanentFailure('User not found');
            $message = $this->emailService->build('path/to/welcomeEmailTemplate', [/* user vars */]);
            $this->emailService->send($message);
        } catch (InvalidArgumentException $e) {
            // An invalid message cannot be sent
            throw new PermanentFailure($e->getMessage();
        } catch (EmailTransportException $e) {
            // e.g. connection refused, can retry later
            throw new TemporaryFailure($e->getMessage());
        }
    }
}
```

The easy way to create tasks in your application is to create a task factory by implementing the `Simphle\Action\Task\TaskFactoryInterface`. The factory can map between arbitrary action names and your task objects.

```php
use Simphle\Action\Task\TaskFactoryInterface;
use Simphle\Action\Task\TaskInterface;

class TaskFactory implements TaskFactoryInterface
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    public function get(string $action): TaskInterface
    {
        return match ($action) {
            'user.sendWelcomeEmail' => new SendWelcomeEmail(
                $this->container->get('emailService'),
                $this->container->get('userService')
             ),
            // Other tasks here...
            default => throw new InvalidArgumentException(
                'Invalid action: ' . $action
            )
        };
    }
}
```

### Running tasks in code

Tasks can be run within your application code by simply calling `$task->run()`:

```php
$taskFactory = /* get task factory from DI container or injected */
$task = $taskFactory->get('user.sendWelcomeEmail');
$task->run(['user_id' => 1234]);
```

### Using the task Runner

Simphle Action provides a Runner command for Symphony Console to be used in your interactive CLI scripts. If your application already has a console-like facility, you can hook the runner to it and call it like

```console
$ bin/your-app run:task user.sendWelcomeEmail --params='{"user_id":1234}'
```

### Using the task Scheduler

Simphle Scheduler consists of a special Runner command to fetch and run a series of scheduled tasks. You can add the runner directly to your crontab using something like:

```
* * * * * /path/to/your-app run:scheduled 1>> /path/to/scheduler.log 2>&1
```

Or if you have more than a few other cron jobs you can wrap it into a script that uses [`php-cron-scheduler`](https://github.com/peppeocchi/php-cron-scheduler).

The mechanism for adding and fetching scheduled jobs is left to your application. Simphle Action provides a `ScheduledTaskServiceInterface` to be implemented by your application, and a `ScheduledTask` object to represent a single delayed job.

### Running tasks in background with the Worker

You may want to run tasks like email or long-running operations within a background worker. Simphle Action provides a Worker console command, a `QueueServiceInterface` and queue drivers for Redis and AMQP (e.g. RabbitMQ).

Just like the runner, you can hook the worker command to your Symfony Console application, or you can create a new one. You can run an instance of the worker for each specific queue you have/need.

```console
$ bin/your-app worker:run --queue=some-queue
```

You can post queue messages from any parts of your application like this:

```php
$queue = /* fetch  QueueServiceInterface from DI container */
$queue->post([new QueueMessage(
    action: 'user.sendWelcomeEmail',
    params: ['user_id' => 1234]
)]);
```

The worker will use a provided `TaskFactoryInterface` to fetch the tasks to run based on the action tag. The (very simplified) loop looks something like:

```php
// Within Worker\Run::execute() infinite loop

while(/* ... */) {
    $message = $this->queueService->get($queueName);
    // some checks...
    $task = $this->taskFactory->get($message->action);
    // some more checks...
    $task->run($message->params);
    // catch exceptions, etc...
    
    // sleep, cleanup, etc...
}
```
