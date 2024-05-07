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

namespace Simphle\Action\Queue;

interface QueueServiceInterface
{
    /**
     * Posts a group of messages to the underlying queue
     * @param QueueMessage[] $messages
     */
    public function post(array $messages, string $queue = 'default'): bool;

    /**
     * Fetches the next message from the given queue
     */
    public function get(string $queue = 'default'): ?QueueMessage;

    /**
     * Deletes a message from a queue, after either success or fatal error
     */
    public function ack(QueueMessage $message, string $queue = 'default'): bool;

    /**
     * Bounces back a message to a queue, after a temporary error
     */
    public function nack(QueueMessage $message, string $queue = 'default'): bool;
}
