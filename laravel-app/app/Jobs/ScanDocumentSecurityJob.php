<?php

namespace App\Jobs;

use App\Enums\DocumentSecurityScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Models\Document;
use App\Services\Documents\DocumentProcessingDispatcher;
use App\Services\Documents\DocumentSecurityService;
use App\Services\Documents\DocumentUploadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ScanDocumentSecurityJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Document $document,
        public ProcessingProfile $processingProfile,
    ) {
        $this->onQueue(
            (string) config('security.clamav.queue', 'security-scan'),
        );
    }

    public function handle(
        DocumentSecurityService $securityService,
        DocumentUploadService $uploadService,
        DocumentProcessingDispatcher $processingDispatcher,
    ): void {
        $this->document->status = DocumentStatus::Scanning;
        $this->document->save();

        try {
            $filePath = Storage::disk('document_quarantine')
                ->path($this->document->file_path);

            $scanStatus = $securityService->scan($filePath);

            if ($scanStatus !== DocumentSecurityScanStatus::Clean) {
                $uploadService->rejectAfterUnsafeScan(
                    $this->document,
                    $scanStatus,
                );

                return;
            }

            $uploadService->promoteAfterCleanScan(
                $this->document,
                $scanStatus,
            );

            $processingDispatcher->dispatchInitial(
                $this->document,
                $this->processingProfile,
            );
        } catch (Throwable $exception) {
            $this->document->status = DocumentStatus::Failed;
            $this->document->save();

            throw $exception;
        }
    }
}
