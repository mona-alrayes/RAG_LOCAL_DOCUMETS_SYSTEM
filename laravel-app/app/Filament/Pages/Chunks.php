<?php

namespace App\Filament\Pages;

use App\Enums\ProcessingRunStatus;
use App\Exceptions\AiServiceException;
use App\Models\ProcessingRun;
use App\Services\Admin\AdminAccess;
use App\Services\Admin\AdminAudit;
use App\Services\Ai\AiServiceClient;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class Chunks extends Page
{
    protected static ?string $slug = 'chunks/{run}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.chunks';

    #[Locked]
    public int $run;

    #[Locked]
    public array $data = [];

    #[Locked]
    public ?string $error = null;

    #[Locked]
    public array $cursors = [null];

    public function mount(int $run): void
    {
        $this->run = $run;
        $this->loadChunks();
    }

    public function nextPage(): void
    {
        if ($cursor = $this->data['next_cursor'] ?? null) {
            $this->cursors[] = $cursor;
            $this->loadChunks();
        }
    }

    public function previousPage(): void
    {
        if (count($this->cursors) > 1) {
            array_pop($this->cursors);
            $this->loadChunks();
        }
    }

    public function loadChunks(): void
    {
        AdminAccess::authorize(auth()->user());
        $run = ProcessingRun::with('document')->findOrFail($this->run);
        abort_unless($run->status === ProcessingRunStatus::Indexed && $run->document && (int) $run->document->active_processing_run_id === $run->id, 403);
        $this->error = null;
        $this->data = [];
        AdminAudit::record(auth()->id(), 'chunks.read', 'processing_run', $run->id, 'requested');
        try {
            $this->data = app(AiServiceClient::class)->adminChunks($run->document->user_id, $run->document_id, $run->id, $run->profile, end($this->cursors) ?: null);
            AdminAudit::record(auth()->id(), 'chunks.read', 'processing_run', $run->id);
        } catch (AiServiceException) {
            AdminAudit::record(auth()->id(), 'chunks.read', 'processing_run', $run->id, 'failed');
            $this->error = 'Indexed chunks are temporarily unavailable. Please try again.';
        }
    }
}
