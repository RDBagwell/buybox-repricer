<?php

namespace App\Simulator\Delivery;

use App\Simulator\Engine\AnyOfferChanged;
use Illuminate\Redis\Connections\Connection;
use Redis;
use RuntimeException;

/**
 * The simulator's notification destination: a Redis stream read through a consumer group.
 *
 * This plays the role SQS plays for real marketplace notifications: durable, at-least-once,
 * consumers acknowledge (XACK) after handling, and messages left unacknowledged past the
 * visibility timeout are claimed again (XAUTOCLAIM) by the next reader.
 */
final class RedisStreamNotifications implements NotificationPublisher
{
    public function __construct(
        private readonly Connection $redis,
        private readonly string $stream,
        private readonly string $group,
        private readonly int $maxLen,
        private readonly int $visibilityTimeoutMs,
    ) {}

    public function publish(AnyOfferChanged $event): void
    {
        $this->client()->xAdd($this->stream, '*', ['event' => json_encode($event->toPayload(), JSON_THROW_ON_ERROR)], $this->maxLen, true);
    }

    /**
     * Read up to $max messages for $consumer: first any expired, unacknowledged ones, then new ones.
     *
     * @return list<array{id: string, payload: array<string, mixed>}>
     */
    public function read(string $consumer, int $max, int $waitMs): array
    {
        $this->ensureGroup();
        $client = $this->client();
        $messages = [];

        // XAUTOCLAIM via rawCommand (not every phpredis build wraps it); raw commands skip the
        // client's key prefix, so apply it explicitly. Reply: [cursor, [[id, [k, v, ...]], ...], ...].
        $claimed = $client->rawCommand('XAUTOCLAIM', $client->_prefix($this->stream), $this->group, $consumer, (string) $this->visibilityTimeoutMs, '0-0', 'COUNT', (string) $max);
        if (is_array($claimed) && isset($claimed[1]) && is_array($claimed[1])) {
            foreach ($claimed[1] as $entry) {
                if (is_array($entry) && isset($entry[0], $entry[1]) && is_array($entry[1])) {
                    $fields = [];
                    for ($i = 0; $i + 1 < count($entry[1]); $i += 2) {
                        $fields[(string) $entry[1][$i]] = $entry[1][$i + 1];
                    }
                    $messages[(string) $entry[0]] = $fields;
                }
            }
        }

        if (count($messages) < $max) {
            $fresh = $client->xReadGroup($this->group, $consumer, [$this->stream => '>'], $max - count($messages), $waitMs > 0 ? $waitMs : null);
            if (is_array($fresh)) {
                foreach ($fresh as $entries) {
                    foreach ((array) $entries as $id => $fields) {
                        $messages[(string) $id] = $fields;
                    }
                }
            }
        }

        $out = [];
        foreach ($messages as $id => $fields) {
            if (! is_array($fields) || ! isset($fields['event'])) {
                $client->xAck($this->stream, $this->group, [$id]); // poison message: drop it

                continue;
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $fields['event'], true, flags: JSON_THROW_ON_ERROR);
            $out[] = ['id' => $id, 'payload' => $payload];
        }

        return $out;
    }

    public function ack(string $id): void
    {
        $this->client()->xAck($this->stream, $this->group, [$id]);
    }

    public function pendingCount(): int
    {
        $this->ensureGroup();
        $pending = $this->client()->xPending($this->stream, $this->group);

        return is_array($pending) ? (int) ($pending[0] ?? 0) : 0;
    }

    public function purge(): void
    {
        $this->client()->del($this->stream);
    }

    private function ensureGroup(): void
    {
        try {
            $this->client()->xGroup('CREATE', $this->stream, $this->group, '0', true);
        } catch (\RedisException $e) {
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
        // phpredis returns false (not an exception) for BUSYGROUP on some versions; both are fine.
    }

    private function client(): Redis
    {
        $client = $this->redis->client();
        if (! $client instanceof Redis) {
            throw new RuntimeException('Redis stream delivery requires the phpredis client.');
        }

        return $client;
    }
}
