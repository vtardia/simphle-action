<?php

declare(strict_types=1);

namespace Simphle\Action\Queue\Adapter;

use Exception;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Simphle\Action\Queue\Exception\QueueRuntimeException;
use Simphle\Action\Queue\QueueServiceInterface;
use Simphle\Action\Queue\QueueMessage;

/**
 * This service is intended for development, don't use in production!
 * @link( https://github.com/predis/predis, link)
 * @link( https://redis.io/commands, link)
 */
class PRedisAdapter implements QueueServiceInterface
{
    /**
     * Number of retries before discarding a message
     */
    protected int $maxRetries = 5;

    /**
     * Max TTL in seconds (default 48h)
     */
    protected int $maxTTL = 3600 * 48;

    /**
     * Timeout for blocking pop commands (seconds)
     */
    protected int $timeout = 10;

    public function __construct(
        protected RedisClient $client,
        protected LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function post(array $messages, string $queue = 'default'): bool
    {
        try {
            foreach ($messages as $message) {
                // Set a Queue::Message key with the number of retries and a TTL
                $key = $this->getKey($queue, $message);
                $retries = $this->client->get($key);
                if (is_null($retries) || (int) $retries > 0) {
                    // Push message in queue
                    $response = $this->client->rpush($queue, [$message->encode()]);
                    $this->client->set(
                        key: $key,
                        value: is_null($retries) ? $this->maxRetries : $retries
                    );
                    $this->client->expire($key, $this->maxTTL);
                    $this->logger->info(
                        'Redis message posted',
                        [
                            'message' => $message,
                            'payload' => $message->encode(),
                            'queue' => $queue,
                            'response' => $response,
                            'retries' => $retries
                        ]
                    );
                } elseif ((int) $retries === 0) {
                    $this->client->del($key);
                }
            }
        } catch (Exception $e) {
            $this->logger->error(
                'Error pushing message(s) to Redis queue',
                ['messages' => $messages]
            );
            throw new QueueRuntimeException(
                'Unable to push Redis message(s): ' . $e->getMessage()
            );
        }
        return true;
    }

    public function get(string $queue = 'default'): ?QueueMessage
    {
        $response = $this->client->blpop($queue, $this->timeout);
        // Response format is ['queueName', 'MessageContent'] or NULL
        $data = is_array($response) ? $response[1] : null;
        if (!is_null($data)) {
            try {
                $message = QueueMessage::decode($data);
                // Check retries/expiration
                $key = $this->getKey($queue, $message);
                $retries = (int) $this->client->get($key);
                if ($retries > 0) {
                    $this->client->decr($key);
                    return $message;
                }
                // Expired, delete key
                $this->client->del($key);
            } catch (Exception $e) {
                $this->logger->error(
                    'Unable to get Redis message: ' . $e->getMessage()
                );
            }
        }
        return null;
    }

    public function ack(QueueMessage $message, string $queue = 'default'): bool
    {
        // Delete expiration key
        return (bool) $this->client->del($this->getKey($queue, $message));
    }

    public function nack(QueueMessage $message, string $queue = 'default'): bool
    {
        // Pushback the message to the queue (expiration rules still in place)
        return $this->post([$message], $queue);
    }

    private function getKey(string $queue, QueueMessage $message): string
    {
        return sprintf('%s::%s', $queue, $message->id);
    }
}
