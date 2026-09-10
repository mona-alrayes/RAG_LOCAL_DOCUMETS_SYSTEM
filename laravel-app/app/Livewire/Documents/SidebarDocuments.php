<?php

namespace App\Livewire\Documents;

use App\Enums\ProcessingProfile;
use App\Exceptions\AiServiceException;
use App\Models\User;
use App\Services\Ai\ProcessingCapabilityService;
use App\Services\Documents\Presentation\DocumentReadService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class SidebarDocuments extends Component
{
    #[Locked]
    public ?int $activeDocumentId = null;

    #[Locked]
    public bool $pollEnabled = true;

    /** @var list<string> */
    #[Locked]
    public array $availableProcessingProfileValues = [];

    private DocumentReadService $documentReadService;

    private ProcessingCapabilityService $processingCapabilityService;

    public function boot(
        DocumentReadService $documentReadService,
        ProcessingCapabilityService $processingCapabilityService,
    ): void {
        $this->documentReadService = $documentReadService;
        $this->processingCapabilityService = $processingCapabilityService;
    }

    public function mount(
        ?int $activeDocumentId = null,
        bool $pollEnabled = true,
    ): void {
        $this->activeDocumentId = $activeDocumentId;
        $this->pollEnabled = $pollEnabled;

        try {
            $this->availableProcessingProfileValues = array_map(
                static fn (ProcessingProfile $profile): string => $profile->value,
                $this->processingCapabilityService->availableProfiles(),
            );
        } catch (AiServiceException) {
            $this->availableProcessingProfileValues = [];
        }
    }

    public function refreshDocuments(): void
    {
        //
    }

    public function render()
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        $documents = $this->documentReadService
            ->recentForUser($user);

        $pollRequired = $this->pollEnabled
            && collect($documents)->contains(
                fn ($document): bool => $document->pollRequired,
            );

        $availableProcessingProfiles = array_values(array_filter(
            array_map(
                static fn (string $value): ?ProcessingProfile => ProcessingProfile::tryFrom($value),
                $this->availableProcessingProfileValues,
            ),
        ));

        return view(
            'livewire.documents.sidebar-documents',
            [
                'documents' => $documents,
                'pollRequired' => $pollRequired,
                'availableProcessingProfiles' => $availableProcessingProfiles,
            ],
        );
    }
}
