<?php

declare(strict_types=1);

namespace Simphle\Action\Queue\Adapter;

use AMQPChannel;
use AMQPConnection;
use AMQPEnvelope;
use AMQPException;
use AMQPExchange;
use AMQPQueue;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Simphle\Action\Queue\QueueMessage;
use Simphle\Action\Queue\QueueServiceInterface;

/**
 * @link( https://github.com/php-amqp/php-amqp/, link)
 * @link( https://rabbitmq.com/amqp-0-9-1-quickref.html, link)
 */
class AMQPAdapter implements QueueServiceInterface
{
    protected AMQPChannel $channel;
    protected AMQPExchange $exchange;

    /** @var array<string,AMQPQueue> */
    protected array $queues = [];

    public function __construct(
        protected AMQPConnection $client,
        protected LoggerInterface $logger = new NullLogger()
    ) {
        $this->channel = new AMQPChannel($client);
        $this->channel->qos(0, 1);
        $this->exchange = new AMQPExchange($this->channel);
        $this->exchange->setName('worker');
        $this->exchange->setType(AMQP_EX_TYPE_DIRECT);
        $this->exchange->setFlags(AMQP_DURABLE);
        $this->exchange->declareExchange();
    }

    public function __destruct()
    {
        $this->channel->close();
        $this->client->disconnect();
    }

    private function getQueue(string $name): AMQPQueue
    {
        if (!isset($this->queues[$name])) {
            $queue = new AMQPQueue($this->channel);
            $queue->setName($name);
            $queue->setFlags(AMQP_DURABLE);
            $queue->declareQueue();
            // Binding needs to happen after a queue is declared
            $queue->bind($this->exchange->getName(), $name);
            $this->queues[$name] = $queue;
        }
        return $this->queues[$name];
    }

    public function post(array $messages, string $queue = 'default'): bool
    {
        try {
            $this->getQueue($queue);
            foreach ($messages as $message) {
                $this->exchange->publish(
                    $message->encode(),
                    $queue,
                    AMQP_MANDATORY, // Throws if message cannot be enqueued
                    [
                        'message_id' => $message->id,
                        'content_type' => 'application/json',
                        'content_encoding' => 'utf8',
                        'delivery_mode' => 2 // Persistent
                    ]
                );
            }
        } catch (Exception $e) {
            $this->logger->error(
                'Error pushing message(s) to AMQP queue',
                ['messages' => $messages]
            );
            throw new RuntimeException(
                'Unable to push AMQP message(s): ' . $e->getMessage()
            );
        }
        return true;
    }

    public function get(string $queue = 'default'): ?QueueMessage
    {
        $q = $this->getQueue($queue);
        $envelope = $q->get();
        if ($envelope instanceof AMQPEnvelope) {
            return QueueMessage::decode(
                $envelope->getBody(),
                ['delivery_tag' => $envelope->getDeliveryTag()]
            );
        }
        return null;
    }

    public function ack(QueueMessage $message, string $queue = 'default'): bool
    {
        try {
            $q = $this->getQueue($queue);
            $q->ack($message->attributes['delivery_tag']);
            return true;
        } catch (AMQPException $e) {
            $this->logger->error(
                'Unable to ACK message',
                ['message' => $e->getMessage()]
            );
            return false;
        }
    }

    public function nack(QueueMessage $message, string $queue = 'default'): bool
    {
        try {
            $q = $this->getQueue($queue);
            $q->nack($message->attributes['delivery_tag']);
            return true;
        } catch (AMQPException $e) {
            $this->logger->error(
                'Unable to NACK message',
                ['message' => $e->getMessage()]
            );
            return false;
        }
    }
}
