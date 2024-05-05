<?php

declare(strict_types=1);

namespace Simphle\Action\Task;

interface TaskInterface
{
    public function run(array $params = []): void;
}
