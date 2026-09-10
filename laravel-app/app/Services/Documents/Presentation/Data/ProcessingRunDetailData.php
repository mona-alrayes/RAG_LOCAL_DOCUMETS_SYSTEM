<?php

namespace App\Services\Documents\Presentation\Data;

use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use Carbon\CarbonInterface;

/**
 * Represents detailed presentation data for a processing run.
 *
 * هذا الـDTO يمثل تفاصيل محاولة معالجة واحدة داخل processing timeline،
 * مثل الحالة، نوع المحاولة، الـprofile، عدد الصفحات والـchunks،
 * timings، warnings، والتوقيتات الفعلية لكل مرحلة.
 *
 * isActive تحدد إذا كانت هذه المحاولة هي النسخة الفعالة حاليًا للوثيقة.
 *
 * الكلاس readonly لأنه يمثل snapshot للقراءة فقط.
 */
final readonly class ProcessingRunDetailData
{
    /**
     * @param  array<string, int>  $stageTimingsMs
     * @param  list<array{code: string, stage: ?string}>  $warnings
     */
    public function __construct(
        public int $id,
        public ProcessingProfile $profile,
        public ProcessingRunStatus $status,
        public ProcessingRunKind $kind,
        public bool $isActive,
        public ?int $totalPages,
        public ?int $totalChunks,
        public array $stageTimingsMs,
        public array $warnings,
        public CarbonInterface $queuedAt,
        public ?CarbonInterface $startedAt,
        public ?CarbonInterface $indexingStartedAt,
        public ?CarbonInterface $indexedAt,
        public ?CarbonInterface $failedAt,
    ) {}
}
