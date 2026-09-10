<?php

namespace App\Services\Documents\Presentation\Data;

use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use Carbon\CarbonInterface;

/**
 * Represents a lightweight summary of a processing run.
 *
 * هذا الـDTO يستخدم لعرض معلومات مختصرة عن:
 * - active processing run
 * - latest processing attempt
 *
 * يحتوي فقط على الحالة، نوع المحاولة، الـprofile،
 * والتوقيتات الأساسية بدون التفاصيل الكبيرة مثل timings أو warnings.
 *
 * الكلاس readonly لأنه يمثل snapshot للقراءة فقط.
 */
final readonly class ProcessingRunSummaryData
{
    public function __construct(
        public int $id,
        public ProcessingProfile $profile,
        public ProcessingRunStatus $status,
        public ProcessingRunKind $kind,
        public CarbonInterface $queuedAt,
        public ?CarbonInterface $startedAt,
        public ?CarbonInterface $indexingStartedAt,
        public ?CarbonInterface $indexedAt,
        public ?CarbonInterface $failedAt,
    ) {}
}
