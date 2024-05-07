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

namespace Simphle\Action\Task;

use DateTimeImmutable;

interface ScheduledTaskServiceInterface
{
    /**
     * Fetches tasks that are ready to run
     * e.g. where 'processAt', '<=', date('Y-m-d H:i:s') and status = scheduled
     * @return ScheduledTask[]
     */
    public function getRunnableTasks(): array;

    /**
     * Update the given task in the persistent storage
     */
    public function update(ScheduledTask $task, array $data): void;

    /**
     * Deletes non-scheduled tasks with last update older than the given date
     */
    public function cleanup(
        DateTimeImmutable $since = new DateTimeImmutable('1 month ago')
    ): void;
}
