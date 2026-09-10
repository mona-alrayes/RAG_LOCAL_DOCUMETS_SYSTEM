<?php

namespace App\Services\Documents\Presentation;

use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Services\Documents\Presentation\Data\DocumentDetailData;
use App\Services\Documents\Presentation\Data\DocumentSummaryData;
use App\Services\Documents\Presentation\Data\ProcessingRunDetailData;
use App\Services\Documents\Presentation\Query\DocumentListCriteria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Provides read-only document data prepared for the presentation layer.
 *
 * هذا الـService مسؤول فقط عن قراءة بيانات الوثائق وتجهيزها للعرض.
 * لا يقوم بتعديل حالة الوثيقة أو تشغيل processing أو تنفيذ commands.
 *
 * Responsibilities:
 * - Paginated document listing.
 * - Search, filtering, and trusted sorting.
 * - User-scoped document details.
 * - Processing timeline construction.
 * - Dashboard document statistics.
 * - Mapping database models into presentation DTOs.
 *
 * كل الاستعلامات تبدأ من المستخدم نفسه لضمان عزل بيانات المستخدمين
 * وعدم إظهار وثائق مستخدم لمستخدم آخر.
 */
final class DocumentReadService
{
    /**
     * Create the document read service.
     *
     * يعتمد الـService على DocumentSummaryMapper لتحويل Eloquent models
     * إلى DTOs جاهزة للاستخدام في presentation layer.
     */
    public function __construct(
        private readonly DocumentSummaryMapper $summaryMapper,
    ) {}

    /**
     * Get a paginated presentation-ready document list for the given user.
     *
     * يقوم بجلب وثائق المستخدم فقط، ثم تطبيق:
     * - البحث.
     * - الفلاتر.
     * - الترتيب.
     * - Pagination.
     *
     * كما يتم eager loading للـactive run والـlatest attempt
     * لتجنب مشكلة N+1 عند بناء DocumentSummaryData.
     *
     * @return LengthAwarePaginator<int, DocumentSummaryData>
     */
    public function paginateForUser(
        User $user,
        DocumentListCriteria $criteria,
    ): LengthAwarePaginator {
        $query = $user->documents()
            ->getQuery()
            ->with([
                'activeProcessingRun',
                'latestAttempt',
            ]);

        $this->applySearch($query, $criteria);
        $this->applyFilters($query, $criteria);

        $documents = $query
            ->orderBy(
                $criteria->sortBy,
                $criteria->sortDirection,
            )
            ->orderBy(
                'id',
                $criteria->sortDirection,
            )
            ->paginate($criteria->perPage);

        return $documents->through(
            fn (Document $document): DocumentSummaryData => $this
                ->summaryMapper
                ->map($document),
        );
    }

    /**
     * Determine whether the user owns at least one document.
     */
    public function hasAnyForUser(User $user): bool
    {
        return $user->documents()->exists();
    }

    /**
     * Get presentation-ready details for one document owned by the user.
     *
     * هذا الاستعلام scoped بالمستخدم من البداية، لذلك document غير تابع
     * للمستخدم لن يتم إرجاعه.
     *
     * بالإضافة إلى summary، يتم تحميل جميع processing runs
     * وترتيبها زمنيًا لبناء processing timeline كامل.
     *
     * Eager loaded relations:
     * - activeProcessingRun
     * - latestAttempt
     * - processingRuns
     *
     * @throws ModelNotFoundException
     */
    public function detailForUser(
        User $user,
        int $documentId,
    ): DocumentDetailData {
        $document = $user->documents()
            ->with([
                'activeProcessingRun',
                'latestAttempt',
                'processingRuns' => fn ($query) => $query
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ])
            ->findOrFail($documentId);

        return new DocumentDetailData(
            summary: $this->summaryMapper->map($document),
            timeline: $document->processingRuns
                ->map(
                    fn (ProcessingRun $run): ProcessingRunDetailData => $this
                        ->mapRunDetail($document, $run),
                )
                ->values()
                ->all(),
        );
    }

    /**
     * Get the most recent presentation-ready documents for the given user.
     *
     * يتم استخدام هذا الـmethod من الأماكن التي تحتاج قائمة صغيرة
     * من أحدث وثائق المستخدم، مثل الـDashboard والـSidebar.
     *
     * يتم تحميل العلاقات المطلوبة مسبقًا لتجنب N+1،
     * ثم تحويل الوثائق إلى DocumentSummaryData جاهزة للعرض.
     *
     * @return list<DocumentSummaryData>
     */
    public function recentForUser(
        User $user,
        int $limit = 5,
    ): array {
        return $user->documents()
            ->with([
                'activeProcessingRun',
                'latestAttempt',
            ])
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(
                fn (Document $document): DocumentSummaryData => $this
                    ->summaryMapper
                    ->map($document),
            )
            ->values()
            ->all();
    }

    /**
     * Build the user-scoped documents dashboard summary.
     *
     * هذا الـmethod يعطي البيانات الأساسية المطلوبة للـDashboard بدون
     * تحميل جميع الوثائق:
     *
     * - عدد الوثائق حسب الحالة.
     * - عدد الوثائق قيد المعالجة.
     * - عدد عمليات إعادة المعالجة الجارية.
     * - أحدث الوثائق.
     * - أحدث الوثائق التي كانت آخر محاولة لمعالجتها Failed.
     *
     * @return array{
     *     counts_by_status: array<string, int>,
     *     active_processing_count: int,
     *     reprocessing_count: int,
     *     recent_documents: list<DocumentSummaryData>,
     *     recent_failures: list<DocumentSummaryData>
     * }
     */
    public function dashboardForUser(User $user): array
    {
        $countsByStatus = $user->documents()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $activeProcessingCount = $user->documents()
            ->whereIn('status', [
                'pending',
                'scanning',
                'queued',
                'processing',
                'indexing',
            ])
            ->count();

        $reprocessingCount = $user->documents()
            ->whereHas('latestAttempt', function (Builder $query): void {
                $query
                    ->where('kind', 'reprocessing')
                    ->whereIn('status', [
                        'pending',
                        'processing',
                        'indexing',
                    ]);
            })
            ->count();

        $recentDocuments = $this->recentForUser($user);

        $recentFailures = $user->documents()
            ->with([
                'activeProcessingRun',
                'latestAttempt',
            ])
            ->whereHas('latestAttempt', function (Builder $query): void {
                $query->where('status', 'failed');
            })
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(
                fn (Document $document): DocumentSummaryData => $this
                    ->summaryMapper
                    ->map($document),
            )
            ->values()
            ->all();

        return [
            'counts_by_status' => $countsByStatus,
            'active_processing_count' => $activeProcessingCount,
            'reprocessing_count' => $reprocessingCount,
            'recent_documents' => $recentDocuments,
            'recent_failures' => $recentFailures,
        ];
    }

    /**
     * Apply free-text search to the document query.
     *
     * البحث حاليًا يتم فقط ضمن:
     * - document title
     * - original uploaded filename
     *
     * إذا لم يرسل المستخدم search value فلا يتم تعديل الاستعلام.
     */
    private function applySearch(
        Builder $query,
        DocumentListCriteria $criteria,
    ): void {
        if ($criteria->search === null || $criteria->search === '') {
            return;
        }

        $search = '%'.$criteria->search.'%';

        $query->where(function (Builder $query) use ($search): void {
            $query
                ->whereLike('title', $search)
                ->orWhereLike('original_name', $search);
        });
    }

    /**
     * Apply document list filters.
     *
     * الفلاتر المدعومة:
     * - Document status.
     * - File type.
     * - Processing profile.
     *
     * القيم تكون typed مسبقًا داخل DocumentListCriteria،
     * وبالتالي لا نتعامل هنا مباشرة مع raw request values.
     */
    private function applyFilters(
        Builder $query,
        DocumentListCriteria $criteria,
    ): void {
        if ($criteria->status !== null) {
            $query->where(
                'status',
                $criteria->status->value,
            );
        }

        if ($criteria->fileType !== null) {
            $query->where(
                'file_type',
                $criteria->fileType->value,
            );
        }

        if ($criteria->profile !== null) {
            $this->applyProfileFilter(
                $query,
                $criteria->profile->value,
            );
        }
    }

    /**
     * Apply the processing profile filter.
     *
     * قاعدة الفلترة هنا مهمة:
     *
     * إذا كانت الوثيقة تملك active processing run،
     * يتم الاعتماد على profile الخاص بالـactive run.
     *
     * أما إذا لم يوجد active run بعد، مثل أثناء أول معالجة،
     * يتم الاعتماد على profile الخاص بأحدث processing attempt.
     *
     * بهذه الطريقة لا تجعل محاولة reprocessing الجديدة الوثيقة تبدو
     * وكأنها انتقلت إلى profile جديد قبل نجاح المعالجة وتفعيل الـrun.
     */
    private function applyProfileFilter(
        Builder $query,
        string $profile,
    ): void {
        $query->where(
            function (Builder $query) use ($profile): void {
                $query
                    ->whereHas(
                        'activeProcessingRun',
                        fn (Builder $runQuery): Builder => $runQuery->where(
                            'profile',
                            $profile,
                        ),
                    )
                    ->orWhere(
                        function (Builder $query) use ($profile): void {
                            $query
                                ->whereNull('active_processing_run_id')
                                ->whereHas(
                                    'latestAttempt',
                                    fn (Builder $runQuery): Builder => $runQuery
                                        ->where(
                                            'profile',
                                            $profile,
                                        ),
                                );
                        },
                    );
            },
        );
    }

    /**
     * Convert a ProcessingRun model into presentation-safe timeline data.
     *
     * يتم هنا تجهيز محاولة المعالجة لعرضها ضمن timeline.
     *
     * لا يتم تمرير بيانات داخلية حساسة مثل:
     * - qdrant_collection
     * - failure_reason الخام
     * - profile_snapshot الكامل
     *
     * ويتم أيضًا تحديد إن كانت هذه المحاولة هي الـactive run الحالية.
     */
    private function mapRunDetail(
        Document $document,
        ProcessingRun $run,
    ): ProcessingRunDetailData {
        return new ProcessingRunDetailData(
            id: $run->id,
            profile: $run->profile,
            status: $run->status,
            kind: $run->kind,
            isActive: $document->active_processing_run_id === $run->id,
            totalPages: $run->total_pages,
            totalChunks: $run->total_chunks,
            stageTimingsMs: $run->stage_timings_ms ?? [],
            warnings: $this->safeWarnings($run),
            queuedAt: $run->created_at,
            startedAt: $run->started_at,
            indexingStartedAt: $run->indexing_started_at,
            indexedAt: $run->indexed_at,
            failedAt: $run->failed_at,
        );
    }

    /**
     * Extract presentation-safe warnings from a processing run.
     *
     * رسالة warning الخام القادمة من AI service لا يتم تمريرها للواجهة.
     * نحتفظ فقط بـ:
     *
     * - code: معرف آمن يمكن للواجهة أو localization التعامل معه.
     * - stage: المرحلة التي حدث فيها التحذير إن وجدت.
     *
     * أي warning غير مطابق للشكل المتوقع يتم تجاهله بدل كسر read model.
     *
     * @return list<array{code: string, stage: ?string}>
     */
    private function safeWarnings(ProcessingRun $run): array
    {
        $warnings = $run->warnings ?? [];

        $safeWarnings = [];

        foreach ($warnings as $warning) {
            if (
                ! is_array($warning)
                || ! isset($warning['code'])
                || ! is_string($warning['code'])
            ) {
                continue;
            }

            $stage = $warning['stage'] ?? null;

            $safeWarnings[] = [
                'code' => $warning['code'],
                'stage' => is_string($stage)
                    ? $stage
                    : null,
            ];
        }

        return $safeWarnings;
    }
}
