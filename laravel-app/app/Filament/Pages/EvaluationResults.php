<?php

namespace App\Filament\Pages;

use App\Models\EvaluationRun;
use App\Services\Admin\AdminAccess;
use App\Services\Admin\AdminAudit;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

class EvaluationResults extends Page
{
    protected static ?string $slug = 'evaluation-results/{run}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.evaluation-results';

    protected static ?string $title = 'تفاصيل نتائج التقييم';

    #[Locked]
    public int $run;

    public ?int $baselineId = null;

    public string $questionStatus = 'all';

    public string $questionSplit = 'all';

    public string $questionAnswerability = 'all';

    public string $questionCategory = 'all';

    public function mount(int $run): void
    {
        AdminAccess::authorize(auth()->user());

        $this->run = $run;

        EvaluationRun::findOrFail($run);

        AdminAudit::record(
            auth()->id(),
            'evaluation.view',
            'evaluation_run',
            $run,
        );
    }

    public function updatedBaselineId(): void
    {
        AdminAccess::authorize(auth()->user());

        $this->resetErrorBag('baselineId');

        $evaluation = EvaluationRun::findOrFail(
            $this->run
        );

        if (
            $this->baselineId !== null
            && ! $this->compatibleRuns($evaluation)
                ->contains('id', $this->baselineId)
        ) {
            $this->baselineId = null;

            $this->addError(
                'baselineId',
                'اختر تشغيلًا مكتملًا يستخدم نفس مجموعة الاختبار والوثائق وقيمة K وإصدار المقاييس.',
            );
        }
    }

    protected function getViewData(): array
    {
        AdminAccess::authorize(auth()->user());

        $evaluation = EvaluationRun::with(
            'dataset'
        )->findOrFail($this->run);

        $compatibleRuns = $this->compatibleRuns(
            $evaluation
        );

        $baseline = $compatibleRuns->firstWhere(
            'id',
            $this->baselineId,
        );

        $configurationDifferences =
            $this->configurationDifferences(
                $evaluation,
                $baseline,
            );

        $categories = $evaluation
            ->questionResults()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->reorder()
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $questions = $evaluation
            ->questionResults()
            ->when(
                $this->questionStatus !== 'all',
                fn ($query) => $query->where(
                    'status',
                    $this->questionStatus,
                ),
            )
            ->when(
                $this->questionSplit !== 'all',
                fn ($query) => $query->where(
                    'split',
                    $this->questionSplit,
                ),
            )
            ->when(
                $this->questionCategory !== 'all',
                fn ($query) => $query->where(
                    'category',
                    $this->questionCategory,
                ),
            )
            ->when(
                $this->questionAnswerability === 'answerable',
                fn ($query) => $query->where(
                    'is_answerable',
                    true,
                ),
            )
            ->when(
                $this->questionAnswerability === 'unanswerable',
                fn ($query) => $query->where(
                    'is_answerable',
                    false,
                ),
            )
            ->orderBy('sequence')
            ->get();

        return compact(
            'evaluation',
            'compatibleRuns',
            'baseline',
            'configurationDifferences',
            'categories',
            'questions',
        );
    }

    private function compatibleRuns(
        EvaluationRun $evaluation,
    ): Collection {
        $version = $evaluation
            ->config_snapshot['metric_version']
            ?? null;

        if (
            $evaluation->status !== 'completed'
            || ! $version
            || ! $evaluation->metrics
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
            ->where('id', '!=', $evaluation->id)
            ->orderByDesc('id')
            ->get()
            ->filter(
                fn (EvaluationRun $candidate) => $candidate->metrics
                    && (
                        $candidate->config_snapshot[
                            'metric_version'
                        ] ?? null
                    ) === $version
                    && $candidate->targets_snapshot
                        === $evaluation->targets_snapshot,
            );
    }

    private function configurationDifferences(
        EvaluationRun $evaluation,
        ?EvaluationRun $baseline,
    ): array {
        if (! $baseline) {
            return [];
        }

        $differences = [];

        $keys = array_unique(
            array_merge(
                array_keys(
                    $evaluation->config_snapshot ?? []
                ),
                array_keys(
                    $baseline->config_snapshot ?? []
                ),
            )
        );

        foreach ($keys as $key) {
            $current =
                $evaluation->config_snapshot[$key]
                ?? null;

            $previous =
                $baseline->config_snapshot[$key]
                ?? null;

            if ($current !== $previous) {
                $differences[$key] = [
                    'baseline' => $previous,
                    'current' => $current,
                ];
            }
        }

        return $differences;
    }
}
