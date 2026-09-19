<?php

namespace App\Services\Documents\Presentation\Data;

use App\Enums\DocumentAvailability;
use App\Enums\FileType;
use Carbon\CarbonInterface;

/**
 * Presentation-ready summary for a document.
 *
 * هذا الـDTO يحتوي البيانات الأساسية المطلوبة لعرض الوثيقة
 * في القوائم، الـDashboard، وصفحة التفاصيل.
 *
 * الفرق المهم:
 * - activeRun: النسخة الفعالة حاليًا.
 * - latestAttempt: أحدث محاولة معالجة.
 *
 * الكلاس readonly لأنه يمثل snapshot للقراءة فقط.
 */
final readonly class DocumentSummaryData
{
    /**
     * Create the document summary data.
     *
     * allowed actions هنا مجرد hints للواجهة،
     * وليست بديلًا عن Policies أو server-side authorization.
     */
    public function __construct(
        public int $id,

        /** Optional document title. */
        public ?string $title,

        /** Original uploaded filename. */
        public string $originalName,

        /** Document file type. */
        public FileType $fileType,

        /** File size in bytes. */
        public int $fileSize,

        /**
         * الحالة الفعلية للوثيقة من منظور العرض.
         */
        public DocumentAvailability $availability,

        /**
         * الـprocessing run الفعالة حاليًا.
         */
        public ?ProcessingRunSummaryData $activeRun,

        /**
         * أحدث processing attempt، وقد تختلف عن activeRun.
         */
        public ?ProcessingRunSummaryData $latestAttempt,

        /** Indicates whether reprocessing is currently running. */
        public bool $reprocessingInProgress,

        /**
         * تحدد إن كانت الواجهة بحاجة للاستمرار في polling.
         */
        public bool $pollRequired,

        /**
         * Failure information for the latest failed attempt.
         * لا يتم عرض القيمة الخام مباشرة للمستخدم.
         */
        public ?string $safeFailure,

        /** Presentation hint for download availability. */
        public bool $canDownload,

        /** Presentation hint for reprocess availability. */
        public bool $canReprocess,

        /** Presentation hint for delete availability. */
        public bool $canDelete,

        /** Document creation timestamp. */
        public CarbonInterface $createdAt,

        /** Document last update timestamp. */
        public CarbonInterface $updatedAt,
        public ?string $failureCode = null,
    ) {}

    public function failureMessage(): string
    {
        $key = in_array($this->failureCode, [
            'local_resource_exhausted', 'local_resource_telemetry_unavailable',
            'document_parsing_outcome_unknown', 'document_parsing_checkpoint_invalid',
            'document_parsing_failed', 'document_parsing_empty', 'dense_embedding_failed',
        ], true) ? $this->failureCode : 'processing_failed';

        return __('documents.failure.'.$key);
    }
}
