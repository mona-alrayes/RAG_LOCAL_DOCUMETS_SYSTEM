<?php

namespace Tests\Feature\Documents;

use App\Jobs\ProcessDocumentJob;
use App\Services\Documents\DocumentSecurityService;
use App\Services\Infrastructure\LocalHeavyResourceLock;
use Illuminate\Support\Facades\Redis;
use ReflectionMethod;
use Tests\TestCase;

class LocalHeavyResourceLockContractTest extends TestCase
{
    public function test_global_lock_uses_one_fixed_redis_key(): void
    {
        config()->set(
            'security.local_heavy_resource_lock.key',
            'rag:local:heavy-resource',
        );

        config()->set(
            'security.local_heavy_resource_lock.timeout',
            600,
        );

        Redis::shouldReceive('eval')
            ->once()
            ->withArgs(
                function (
                    string $script,
                    int $numberOfKeys,
                    string $key,
                    string $token,
                    int $timeout,
                ): bool {
                    $this->assertSame(
                        1,
                        $numberOfKeys,
                    );

                    $this->assertSame(
                        'rag:local:heavy-resource',
                        $key,
                    );

                    $this->assertNotSame(
                        '',
                        $token,
                    );

                    $this->assertSame(
                        600,
                        $timeout,
                    );

                    return true;
                },
            )
            ->andReturn(1);

        $token = app(
            LocalHeavyResourceLock::class,
        )->acquire();

        $this->assertNotNull($token);
    }

    public function test_lock_can_be_released_and_acquired_again(): void
    {
        config()->set(
            'security.local_heavy_resource_lock.key',
            'rag:local:heavy-resource',
        );

        $lock = app(LocalHeavyResourceLock::class);

        Redis::shouldReceive('eval')
            ->times(3)
            ->andReturn(
                1,
                1,
                1,
            );

        $firstToken = $lock->acquire();

        $this->assertNotNull(
            $firstToken,
        );

        $this->assertTrue(
            $lock->release($firstToken),
        );

        $secondToken = $lock->acquire();

        $this->assertNotNull(
            $secondToken,
        );

        $this->assertNotSame(
            $firstToken,
            $secondToken,
        );
    }

    public function test_clamav_and_local_processing_share_same_lock_abstraction(): void
    {
        $securityConstructor = new ReflectionMethod(
            DocumentSecurityService::class,
            '__construct',
        );

        $securityLockType = $securityConstructor
            ->getParameters()[0]
            ->getType();

        $this->assertNotNull(
            $securityLockType,
        );

        $this->assertSame(
            LocalHeavyResourceLock::class,
            $securityLockType->getName(),
        );

        $processingHandle = new ReflectionMethod(
            ProcessDocumentJob::class,
            'handle',
        );

        $processingLockParameter = collect(
            $processingHandle->getParameters(),
        )->first(
            fn ($parameter) => $parameter->getName()
                === 'localHeavyResourceLock',
        );

        $this->assertNotNull(
            $processingLockParameter,
        );

        $this->assertSame(
            LocalHeavyResourceLock::class,
            $processingLockParameter
                ->getType()
                ->getName(),
        );

        $this->assertSame(
            'rag:local:heavy-resource',
            config(
                'security.local_heavy_resource_lock.key',
            ),
        );
    }
}
