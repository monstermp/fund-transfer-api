<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client as RedisClient;

/**
 * Stores idempotent responses in Redis keyed by the client's Idempotency-Key.
 *
 * Strategy:
 *  - reserve(): Atomically claims the key with SET NX. Returns false if it
 *    already exists (i.e. another request with the same key is in flight
 *    or has already completed).
 *  - store():   Persists the final JSON response under the key.
 *  - get():     Retrieves a previously stored response so we can replay it.
 *
 * TTL defaults to 24h which matches typical payment-API behaviour.
 */
class IdempotencyService
{
    private const KEY_PREFIX = 'idem:';
    private const DEFAULT_TTL = 86400; // 24h

    public function __construct(private readonly RedisClient $redis) {}

    public function reserve(string $key, int $ttl = self::DEFAULT_TTL): bool
    {
        $result = $this->redis->set(
            self::KEY_PREFIX . $key,
            json_encode(['status' => 'pending'], JSON_THROW_ON_ERROR),
            'EX',
            $ttl,
            'NX'
        );

        return $result !== null && (string) $result === 'OK';
    }

    public function store(string $key, array $payload, int $ttl = self::DEFAULT_TTL): void
    {
        $this->redis->setex(
            self::KEY_PREFIX . $key,
            $ttl,
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    public function get(string $key): ?array
    {
        $raw = $this->redis->get(self::KEY_PREFIX . $key);
        if ($raw === null) {
            return null;
        }

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    public function release(string $key): void
    {
        $this->redis->del(self::KEY_PREFIX . $key);
    }
}
