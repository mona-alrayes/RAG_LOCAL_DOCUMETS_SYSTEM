<?php

namespace App\Filament\Pages;

use App\Models\EvaluationRun;
use App\Services\Admin\AdminAccess;
use App\Services\Admin\AdminAudit;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class EvaluationResults extends Page
{
    protected static ?string $slug = 'evaluation-results/{run}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.evaluation-results';

    #[Locked]
    public int $run;

    public function mount(int $run): void
    {
        AdminAccess::authorize(auth()->user());
        $this->run = $run;
        EvaluationRun::findOrFail($run);
        AdminAudit::record(auth()->id(), 'evaluation.view', 'evaluation_run', $run);
    }

    protected function getViewData(): array
    {
        AdminAccess::authorize(auth()->user());

        return ['evaluation' => EvaluationRun::findOrFail($this->run)];
    }
}
