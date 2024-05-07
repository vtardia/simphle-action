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

readonly class ScheduledTask
{
    public function __construct(
        public string $id,
        public string $name,
        public string $action,
        public ScheduledTaskStatus $status = ScheduledTaskStatus::Scheduled,
        public array $params = [],
        public DateTimeImmutable $created = new DateTimeImmutable(),
        public DateTimeImmutable $updated = new DateTimeImmutable()
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'action' => $this->action,
            'created' => $this->created->format('Y-m-d H:i:s'),
            'updated' => $this->updated->format('Y-m-d H:i:s'),
        ];
    }
}
