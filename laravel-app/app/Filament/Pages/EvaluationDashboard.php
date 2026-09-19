<?php

namespace App\Filament\Pages;

use App\Models\EvaluationRun;
use App\Services\Admin\AdminAccess;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class EvaluationDashboard extends Page
{
    protected string $view = 'filament.pages.evaluation-dashboard';

    protected static ?string $navigationLabel = 'لوحة تقييم النظام';

    protected static string|\UnitEnum|null $navigationGroup = 'التقييم';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'لوحة تقييم النظام';

    public ?int $selectedRunId = null;

    public function mount(): void
    {
        AdminAccess::authorize(auth()->user());

        $this->selectedRunId = EvaluationRun::query()
            ->latest('id')
            ->value('id');
    }

    public function updatedSelectedRunId(): void
    {
        AdminAccess::authorize(auth()->user());

        if ($this->selectedRunId !== null) {
            EvaluationRun::findOrFail($this->selectedRunId);
        }
    }

    protected function getViewData(): array
    {
        AdminAccess::authorize(auth()->user());

        $runs = EvaluationRun::query()
            ->latest('id')
            ->limit(50)
            ->get();

        $evaluation = $this->selectedRunId
            ? EvaluationRun::with('dataset')
                ->find($this->selectedRunId)
            : null;

        $questions = $evaluation
            ? $evaluation->questionResults()
                ->orderBy('sequence')
                ->limit(20)
                ->get()
            : collect();

        $pipelineRuns = $evaluation
            ? $this->compatiblePipelineRuns($evaluation)
            : collect();

        $diagnostic = $evaluation
            ? $this->diagnostic($evaluation)
            : null;

        return compact(
            'runs',
            'evaluation',
            'questions',
            'pipelineRuns',
            'diagnostic',
        );
    }

    public static function statusLabel(
        string $status,
    ): string {
        return match ($status) {
            'queued' => 'بانتظار التنفيذ',
            'running' => 'قيد التنفيذ',
            'completed' => 'مكتمل',
            'failed' => 'فشل',
            default => $status,
        };
    }

    public static function pipelineLabel(
        ?string $pipeline,
    ): string {
        return match ($pipeline) {
            'dense_only' =>
                'بحث كثيف فقط',
            'dense_sparse_rrf' =>
                'كثيف + متناثر + دمج RRF',
            'dense_sparse_rrf_reranker' =>
                'كثيف + متناثر + RRF + إعادة ترتيب',
            default => $pipeline ?: 'غير محدد',
        };
    }

    public static function splitLabel(
        ?string $split,
    ): string {
        return match ($split) {
            'development' => 'تطوير',
            'held_out' => 'اختبار محجوز',
            default => $split ?: 'غير محدد',
        };
    }

    private function compatiblePipelineRuns(
        EvaluationRun $evaluation,
    ): Collection {
        $metricVersion =
            $evaluation->config_snapshot[
                'metric_version'
            ] ?? null;

        if (
            $evaluation->status !== 'completed'
            || ! $metricVersion
        ) {
            return collect();
        }

        return EvaluationRun::query()
            ->where('status', 'completed')
            ->where(
                'dataset_sha256',
                $evaluation->dataset_sha256,
            )
            ->where('k', $evaluation->k)
            ->latest('id')
            ->get()
            ->filter(
                fn (EvaluationRun $candidate) =>
                    (
                        $candidate->config_snapshot[
                            'metric_version'
                        ] ?? null
                    ) === $metricVersion
                    && $candidate->targets_snapshot
                        === $evaluation->targets_snapshot,
            )
            ->groupBy(
                fn (EvaluationRun $candidate) =>
                    $candidate->config_snapshot[
                        'pipeline'
                    ] ?? 'unknown',
            )
            ->map(
                fn (Collection $group) =>
                    $group->first(),
            )
            ->values();
    }

    private function diagnostic(
        EvaluationRun $evaluation,
    ): array {
        if ($evaluation->status !== 'completed') {
            return [
                'title' => 'التقييم لم يكتمل بعد',
                'message' =>
                    'ستظهر القراءة التشخيصية بعد اكتمال جميع أسئلة التقييم وحفظ المقاييس النهائية.',
            ];
        }

        $metrics = $evaluation->metrics ?? [];

        $recall = $metrics['recall_at_k']
            ?? null;

        $answerScores = collect([
            $metrics['correctness'] ?? null,
            $metrics['faithfulness'] ?? null,
            $metrics['answer_relevance'] ?? null,
        ])->filter(
            fn ($value) => $value !== null
        );

        if (
            $recall === null
            && $answerScores->isEmpty()
        ) {
            return [
                'title' => 'لا توجد بيانات كافية للتشخيص',
                'message' =>
                    'لا تتوفر مقاييس نهائية كافية لهذا التشغيل بعد.',
            ];
        }

        if (
            $recall !== null
            && $recall < 0.60
        ) {
            return [
                'title' =>
                    'مشكلة محتملة في مرحلة الاسترجاع',
                'message' =>
                    'الاستدعاء منخفض نسبيًا. يُنصح بمراجعة تقسيم الوثائق، نموذج التضمين، البحث المتناثر، دمج RRF وإعادة الترتيب قبل تعديل نموذج توليد الإجابة.',
            ];
        }

        if (
            $recall !== null
            && $recall >= 0.60
            && $answerScores->isNotEmpty()
            && $answerScores->min() < 0.60
        ) {
            return [
                'title' =>
                    'الاسترجاع مقبول لكن جودة الإجابة تحتاج مراجعة',
                'message' =>
                    'المقاطع المناسبة تصل إلى مرحلة الإجابة بدرجة مقبولة، لكن أحد مقاييس صحة الإجابة أو الالتزام بالمصادر أو الارتباط بالسؤال منخفض. راجع نموذج التوليد، صياغة التعليمات وتجميع السياق.',
            ];
        }

        if (
            $recall !== null
            && $recall >= 0.75
            && (
                $answerScores->isEmpty()
                || $answerScores->min() >= 0.75
            )
        ) {
            return [
                'title' => 'الأداء متوازن',
                'message' =>
                    'نتائج الاسترجاع وجودة الإجابة متوازنة في هذا التشغيل. استخدم المقارنة بين المسارات والتحليل حسب الأسئلة قبل اعتماد إعداد نهائي.',
            ];
        }

        return [
            'title' => 'النتائج متوسطة وتحتاج تحليلًا تفصيليًا',
            'message' =>
                'لا يظهر سبب واحد حاسم من الملخص العام. راجع نتائج الأسئلة الفردية وقارن بين مسارات الاسترجاع لتحديد موضع الضعف.',
        ];
    }
}
