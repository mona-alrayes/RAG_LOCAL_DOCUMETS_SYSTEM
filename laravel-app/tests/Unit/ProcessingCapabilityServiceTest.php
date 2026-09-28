<?php

namespace Tests\Unit;

use App\Enums\ProcessingProfile;
use App\Exceptions\AiServiceException;
use App\Services\Ai\AiServiceClient;
use App\Services\Ai\ProcessingCapabilityService;
use Mockery;
use Tests\TestCase;

class ProcessingCapabilityServiceTest extends TestCase
{
    public function test_it_returns_typed_available_profiles(): void
    {
        $client = Mockery::mock(AiServiceClient::class);

        $client
            ->shouldReceive('capabilities')
            ->once()
            ->andReturn([
                'available_profiles' => [
                    'cloud',
                    'hybrid_local',
                ],
            ]);

        $service = new ProcessingCapabilityService($client);

        $this->assertSame(
            [
                ProcessingProfile::Cloud,
                ProcessingProfile::HybridLocal,
            ],
            $service->availableProfiles(),
        );
    }

    public function test_it_rejects_an_unavailable_profile(): void
    {
        $client = Mockery::mock(AiServiceClient::class);

        $client
            ->shouldReceive('capabilities')
            ->once()
            ->andReturn([
                'available_profiles' => [
                    ProcessingProfile::Cloud->value,
                ],
            ]);

        $service = new ProcessingCapabilityService($client);

        try {
            $service->assertAvailable(
                ProcessingProfile::HybridLocal,
            );

            $this->fail(
                'Expected unavailable processing profile to be rejected.',
            );
        } catch (AiServiceException $exception) {
            $this->assertSame(
                'processing_profile_unavailable',
                $exception->errorCode,
            );
        }
    }

    public function test_it_fails_closed_for_invalid_capabilities_response(): void
    {
        $client = Mockery::mock(AiServiceClient::class);

        $client
            ->shouldReceive('capabilities')
            ->once()
            ->andReturn([
                'available_profiles' => [
                    'unknown_profile',
                ],
            ]);

        $service = new ProcessingCapabilityService($client);

        try {
            $service->availableProfiles();

            $this->fail(
                'Expected invalid capabilities response to be rejected.',
            );
        } catch (AiServiceException $exception) {
            $this->assertSame(
                'invalid_capabilities_response',
                $exception->errorCode,
            );
        }
    }
}
