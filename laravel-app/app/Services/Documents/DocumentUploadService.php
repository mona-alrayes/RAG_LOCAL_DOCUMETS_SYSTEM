<?php

namespace App\Services\Documents;

use App\Enums\DocumentSecurityScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Jobs\ScanDocumentSecurityJob;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use LogicException;

class DocumentUploadService
{
    public function __construct(
        private readonly DocumentStorageService $storage,
        private readonly DocumentProcessingDispatcher $processingDispatcher,
    ) {}

    public function store(
        User $user,
        UploadedFile $file,
        ProcessingProfile $processingProfile,
    ): Document {
        if (config('security.document_security_scan.enabled', true) === false) {
            $document = $this->storage->storePermanent($user, $file);

            $this->processingDispatcher->dispatchInitial(
                $document,
                $processingProfile,
            );

            return $document;
        }

        $document = $this->storage->storeQuarantined($user, $file);

        ScanDocumentSecurityJob::dispatch(
            $document,
            $processingProfile,
        )->afterCommit();

        return $document;
    }

    public function promoteAfterCleanScan(
        Document $document,
        DocumentSecurityScanStatus $scanStatus,
    ): void {
        if ($scanStatus !== DocumentSecurityScanStatus::Clean) {
            throw new LogicException(
                'Document cannot be promoted without a clean security scan.',
            );
        }

        $this->storage->promoteQuarantined($document);
    }

    public function rejectAfterUnsafeScan(
        Document $document,
        DocumentSecurityScanStatus $scanStatus,
    ): void {
        $document->status = match ($scanStatus) {
            DocumentSecurityScanStatus::Infected => DocumentStatus::Infected,
            DocumentSecurityScanStatus::ScanFailed => DocumentStatus::Failed,
            DocumentSecurityScanStatus::Clean => throw new LogicException(
                'Clean document cannot enter the rejected security scan path.',
            ),
        };

        $document->save();
    }
}
