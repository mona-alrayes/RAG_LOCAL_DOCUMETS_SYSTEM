<?php

namespace App\Services\Documents\Presentation;

use App\Enums\DocumentAvailability;
use App\Enums\DocumentStatus;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Services\Documents\Presentation\Data\DocumentSummaryData;
use App\Services\Documents\Presentation\Data\ProcessingRunSummaryData;
use LogicException;

/**
 * Maps a Document model into a presentation-ready summary DTO.
 *
 * هذا الـMapper مسؤول عن تحويل Document Eloquent model
 * إلى DocumentSummaryData جاهزة للـpresentation layer.
 *
 * لا يقوم بأي database queries بنفسه، لذلك يجب تحميل العلاقات المطلوبة
 * مسبقًا باستخدام eager loading قبل استدعاء map().
 *
 * Responsibilities:
 * - تحديد document availability.
 * - تجهيز active processing run.
 * - تجهيز latest processing attempt.
 * - تحديد وجود reprocessing جاري.
 * - تحديد الحاجة إلى polling.
 * - تجهيز failure information.
 * - تحديد actions المسموحة للواجهة.
 *
 * ملاحظة مهمة:
 * جاهزية الوثيقة لا تعتمد على latest attempt فقط.
 * وجود active indexed run صالح يعني أن الوثيقة تبقى Ready حتى لو كانت
 * هناك محاولة reprocessing جديدة أو حتى لو فشلت تلك المحاولة.
 */
final class DocumentSummaryMapper
{
    /**
     * Create the document summary mapper.
     *
     * يتم تفويض حساب الحالة الفعلية القابلة للاستخدام للوثيقة إلى
     * DocumentAvailabilityResolver بدل إعادة كتابة lifecycle rules هنا.
     */
    public function __construct(
        private readonly DocumentAvailabilityResolver $availabilityResolver,
    ) {}

    /**
     * Convert a Document model into DocumentSummaryData.
     *
     * قبل تنفيذ التحويل نتأكد أن العلاقات المطلوبة محملة مسبقًا،
     * حتى لا يؤدي الـMapper إلى N+1 queries بشكل غير مباشر.
     *
     * يتم فصل:
     * - activeRun: النسخة الحالية الصالحة للاستخدام.
     * - latestAttempt: أحدث محاولة processing مهما كانت نتيجتها.
     *
     * لذلك يمكن مثلًا أن تكون الوثيقة Ready باستخدام active run قديمة،
     * بينما latest attempt هي إعادة معالجة جديدة ما زالت Processing
     * أو حتى Failed.
     *
     * @throws LogicException
     *                        إذا لم تكن العلاقات المطلوبة eager loaded.
     */
    public function map(Document $document): DocumentSummaryData
    {
        $this->ensureRequiredRelationsAreLoaded($document);

        $availability = $this->availabilityResolver->resolve($document);
        $latestAttempt = $document->latestAttempt;

        $latestAttemptInProgress = $this->isRunInProgress($latestAttempt);

        $reprocessingInProgress = $latestAttempt !== null
            && $latestAttempt->kind === ProcessingRunKind::Reprocessing
            && $latestAttemptInProgress;

        $pollRequired = $this->documentRequiresPolling($document)
            || $latestAttemptInProgress;

        return new DocumentSummaryData(
            id: $document->id,
            title: $document->title,
            originalName: $document->original_name,
            fileType: $document->file_type,
            fileSize: $document->file_size,
            availability: $availability,
            activeRun: $this->mapRun($document->activeProcessingRun),
            latestAttempt: $this->mapRun($latestAttempt),
            reprocessingInProgress: $reprocessingInProgress,
            pollRequired: $pollRequired,
            safeFailure: $this->safeFailure($latestAttempt),

            /*
             * هذه القيم تصف availability من منظور العرض حاليًا.
             * الـauthorization الحقيقي يبقى Server-side عبر Policies
             * وapplication commands، ولا يجب الاعتماد على هذه القيم
             * كحاجز أمني بحد ذاتها.
             */
            canDownload: true,
            canReprocess: $availability === DocumentAvailability::Ready
                && ! $latestAttemptInProgress,
            canDelete: ! $pollRequired,

            createdAt: $document->created_at,
            updatedAt: $document->updated_at,
        );
    }

    /**
     * Convert a ProcessingRun model into a lightweight summary DTO.
     *
     * يتم استخدام هذا الشكل المختصر للـactive run والـlatest attempt
     * داخل document cards/list summaries.
     *
     * التفاصيل الأكبر مثل pages/chunks/timings/warnings موجودة
     * في document detail timeline وليست مطلوبة هنا.
     */
    private function mapRun(
        ?ProcessingRun $processingRun,
    ): ?ProcessingRunSummaryData {
        if ($processingRun === null) {
            return null;
        }

        return new ProcessingRunSummaryData(
            id: $processingRun->id,
            profile: $processingRun->profile,
            status: $processingRun->status,
            kind: $processingRun->kind,

            /*
             * created_at يمثل وقت دخول المحاولة إلى queue.
             * أما بقية timestamps فهي timestamps فعلية للمراحل.
             */
            queuedAt: $processingRun->created_at,
            startedAt: $processingRun->started_at,
            indexingStartedAt: $processingRun->indexing_started_at,
            indexedAt: $processingRun->indexed_at,
            failedAt: $processingRun->failed_at,
        );
    }

    /**
     * Determine whether a processing run is still in progress.
     *
     * الحالات التالية تعتبر non-terminal:
     * - Pending
     * - Processing
     * - Indexing
     *
     * Indexed وFailed تعتبر terminal states وبالتالي لا تحتاج
     * متابعة processing لهذه المحاولة.
     */
    private function isRunInProgress(?ProcessingRun $processingRun): bool
    {
        if ($processingRun === null) {
            return false;
        }

        return in_array(
            $processingRun->status,
            [
                ProcessingRunStatus::Pending,
                ProcessingRunStatus::Processing,
                ProcessingRunStatus::Indexing,
            ],
            true,
        );
    }

    /**
     * Determine whether the document aggregate itself requires polling.
     *
     * تستخدم هذه الحالات أثناء أول processing للوثيقة.
     * طالما document status موجود ضمن إحدى الحالات المتحركة،
     * يجب أن تستمر الواجهة في polling على Laravel/MySQL.
     *
     * Ready وFailed حالات مستقرة من منظور document aggregate،
     * إلا إذا كانت هناك latest reprocessing attempt قيد التنفيذ،
     * وهذا يتم التعامل معه بشكل منفصل داخل map().
     */
    private function documentRequiresPolling(Document $document): bool
    {
        return in_array(
            $document->status,
            [
                DocumentStatus::Pending,
                DocumentStatus::Scanning,
                DocumentStatus::Queued,
                DocumentStatus::Processing,
                DocumentStatus::Indexing,
            ],
            true,
        );
    }

    /**
     * Return failure information only when the latest attempt failed.
     *
     * وجود failure في latest attempt لا يعني بالضرورة أن الوثيقة نفسها
     * غير صالحة للاستخدام.
     *
     * مثال:
     * إذا كانت هناك active indexed run صالحة ثم فشلت reprocessing attempt،
     * تبقى الوثيقة Ready باستخدام النسخة السابقة، لكن نظهر أن آخر محاولة فشلت.
     *
     * الـResource مسؤول لاحقًا عن عدم عرض النص الخام للمستخدم مباشرة
     * وعن تحويل الحالة إلى user-safe localized message.
     */
    private function safeFailure(?ProcessingRun $latestAttempt): ?string
    {
        if (
            $latestAttempt === null
            || $latestAttempt->status !== ProcessingRunStatus::Failed
        ) {
            return null;
        }

        return $latestAttempt->failure_reason;
    }

    /**
     * Ensure all relations required by the mapper were eager loaded.
     *
     * هذا check مقصود لمنع استخدام الـMapper بطريقة تسبب N+1 queries.
     *
     * الـMapper لا يقوم باستدعاء load() أو query جديد تلقائيًا.
     * المسؤولية تقع على DocumentReadService لتحميل العلاقات upfront.
     *
     * Required relations:
     * - activeProcessingRun
     * - latestAttempt
     *
     * @throws LogicException
     */
    private function ensureRequiredRelationsAreLoaded(Document $document): void
    {
        foreach (
            ['activeProcessingRun', 'latestAttempt'] as $relation
        ) {
            if (! $document->relationLoaded($relation)) {
                throw new LogicException(
                    "The {$relation} relation must be eager loaded before mapping a document summary."
                );
            }
        }
    }
}
