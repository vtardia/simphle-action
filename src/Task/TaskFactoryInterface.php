<?php

declare(strict_types=1);

namespace Simphle\Action\Task;

interface TaskFactoryInterface
{
    public function get(string $action): TaskInterface;
}
