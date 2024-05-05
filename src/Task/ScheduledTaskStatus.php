<?php

declare(strict_types=1);

namespace Simphle\Action\Task;

enum ScheduledTaskStatus: string
{
    case Scheduled = 'scheduled';
    case Complete = 'complete';
    case Error = 'error';
}
