<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class LocalHeavyResourceLock
{
    public function enabled(): bool
    {
        return (bool) config(
            'security.local_heavy_resource_lock.enabled',
            true,
        );
    }

    public function acquire(): ?string
    {
        $token = (string) Str::uuid();

        $acquired = Redis::eval(<<<'LUA'
            if redis.call('exists', KEYS[1]) == 0 then
                redis.call('set', KEYS[1], ARGV[1], 'EX', ARGV[2])
                return 1
            end

            return 0
            LUA,
            1,
            $this->key(),
            $token,
            $this->timeout(),
        );

        return (int) $acquired === 1
            ? $token
            : null;
    }

    public function acquireWithin(
        ?int $waitSeconds = null,
        ?int $retryIntervalMilliseconds = null,
    ): ?string {
        $waitSeconds ??= $this->waitTimeout();

        $retryIntervalMilliseconds ??=
            $this->retryIntervalMilliseconds();

        $waitSeconds = max(0, $waitSeconds);
        $retryIntervalMilliseconds = max(
            1,
            $retryIntervalMilliseconds,
        );

        $deadline = microtime(true) + $waitSeconds;

        do {
            $token = $this->acquire();

            if ($token !== null) {
                return $token;
            }

            $remainingSeconds = $deadline - microtime(true);

            if ($remainingSeconds <= 0) {
                return null;
            }

            $sleepMilliseconds = min(
                $retryIntervalMilliseconds,
                max(
                    1,
                    (int) ceil($remainingSeconds * 1000),
                ),
            );

            usleep($sleepMilliseconds * 1000);
        } while (true);
    }

    public function refresh(string $token): bool
    {
        $refreshed = Redis::eval(<<<'LUA'
            if redis.call('get', KEYS[1]) == ARGV[1] then
                return redis.call('expire', KEYS[1], ARGV[2])
            end

            return 0
            LUA,
            1,
            $this->key(),
            $token,
            $this->timeout(),
        );

        return (int) $refreshed === 1;
    }

    public function release(string $token): bool
    {
        $released = Redis::eval(<<<'LUA'
            if redis.call('get', KEYS[1]) == ARGV[1] then
                return redis.call('del', KEYS[1])
            end

            return 0
            LUA,
            1,
            $this->key(),
            $token,
        );

        return (int) $released === 1;
    }

    private function key(): string
    {
        return (string) config(
            'security.local_heavy_resource_lock.key',
            'rag:local:heavy-resource',
        );
    }

    private function timeout(): int
    {
        return (int) config(
            'security.local_heavy_resource_lock.timeout',
            600,
        );
    }

    private function waitTimeout(): int
    {
        return (int) config(
            'security.local_heavy_resource_lock.wait_timeout',
            10,
        );
    }

    private function retryIntervalMilliseconds(): int
    {
        return (int) config(
            'security.local_heavy_resource_lock.retry_interval_ms',
            250,
        );
    }
}
