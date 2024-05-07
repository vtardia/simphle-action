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

use Exception;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use RuntimeException;

final readonly class QueueMessage implements JsonSerializable
{
    public string $id;

    /**
     * Creates a new QueueMessage
     *
     * Params are data relative to the action to be performed,
     * while attributes are metadata, normally populated by the
     * underlying provider platform (e.g. delivery_tag for AMQP)
     * @param array<string,mixed> $params
     * @param array<string,mixed> $attributes
     * @throws Exception
     */
    public function __construct(
        public string $action, /* This can also be an enum */
        public array $params = [],
        public array $attributes = [],
        ?string $id = null
    ) {
        $this->id = $id ?? bin2hex(random_bytes(16));
    }

    /**
     * Creates a new message from a payload JSON string
     * @throws Exception
     */
    public static function decode(string $payload, array $attributes = []): QueueMessage
    {
        try {
            $data = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'Invalid message payload: ' . $e->getMessage()
            );
        }
        if (!isset($data['action'])) {
            throw new InvalidArgumentException('Missing action argument');
        }
        return new QueueMessage(
            action: $data['action'],
            params: $data['params'] ?? [],
            attributes: $attributes,
            id: $data['id'] ?? null
        );
    }

    public function encode(): string
    {
        try {
            return json_encode($this, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Unable to encode queue message: ' . $e->getMessage()
            );
        }
    }

    public function jsonSerialize(): array
    {
        $data = [
            'id' => $this->id,
            'action' => $this->action
        ];
        if (!empty($this->params)) {
            $data['params'] = $this->params;
        }
        if (!empty($this->attributes)) {
            $data['attributes'] = $this->attributes;
        }
        return $data;
    }
}
