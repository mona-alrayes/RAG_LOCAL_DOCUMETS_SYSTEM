<?php

namespace App\Services\Ai;

use App\Enums\ProcessingProfile;
use App\Exceptions\AiServiceException;

/**
 * Provides typed access to the processing profiles currently available
 * from the AI service.
 *
 * يحول available_profiles القادمة من FastAPI إلى ProcessingProfile enums
 * ويعامل أي response غير موثوق كحالة فشل.
 */
final class ProcessingCapabilityService
{
    public function __construct(
        private readonly AiServiceClient $aiServiceClient,
    ) {}

    /**
     * Return the processing profiles currently available.
     *
     * @return list<ProcessingProfile>
     */
    public function availableProfiles(): array
    {
        $payload = $this->aiServiceClient->capabilities();

        $values = $payload['available_profiles'] ?? null;

        if (! is_array($values)) {
            throw $this->invalidCapabilitiesResponse();
        }

        $profiles = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw $this->invalidCapabilitiesResponse();
            }

            $profile = ProcessingProfile::tryFrom($value);

            if (! $profile instanceof ProcessingProfile) {
                throw $this->invalidCapabilitiesResponse();
            }

            $profiles[$profile->value] = $profile;
        }

        return array_values($profiles);
    }

    /**
     * Determine whether a processing profile can be started now.
     */
    public function isAvailable(ProcessingProfile $profile): bool
    {
        foreach ($this->availableProfiles() as $availableProfile) {
            if ($availableProfile === $profile) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fail closed when a requested processing profile is unavailable.
     *
     * إذا فشل capability lookup أو لم يكن الـprofile متاحًا،
     * لا يسمح ببدء processing جديد.
     */
    public function assertAvailable(ProcessingProfile $profile): void
    {
        if ($this->isAvailable($profile)) {
            return;
        }

        throw new AiServiceException(
            message: sprintf(
                "Processing profile '%s' is currently unavailable.",
                $profile->value,
            ),
            errorCode: 'processing_profile_unavailable',
        );
    }

    private function invalidCapabilitiesResponse(): AiServiceException
    {
        return new AiServiceException(
            message: 'AI service returned an invalid capabilities response.',
            errorCode: 'invalid_capabilities_response',
        );
    }
}
