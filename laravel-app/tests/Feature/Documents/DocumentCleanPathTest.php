<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentSecurityScanStatus;
use App\Enums\ProcessingProfile;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class DocumentCleanPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_document_is_promoted_from_quarantine_without_creating_another_document(): void
    {
        Queue::fake();

        Storage::fake('documents');
        Storage::fake('document_quarantine');

        $user = User::factory()->create();

        $uploadService = app(DocumentUploadService::class);

        $document = $uploadService->store(
            $user,
            UploadedFile::fake()->createWithContent(
                'notes.txt',
                "Clean document content.\n",
            ),
            ProcessingProfile::Cloud,
        );

        $originalDocumentId = $document->id;
        $originalPath = $document->file_path;

        Storage::disk('document_quarantine')
            ->assertExists($originalPath);

        $uploadService->promoteAfterCleanScan(
            $document,
            DocumentSecurityScanStatus::Clean,
        );

        Storage::disk('documents')
            ->assertExists($originalPath);

        Storage::disk('document_quarantine')
            ->assertMissing($originalPath);

        $this->assertSame(1, Document::query()->count());
        $this->assertSame(
            $originalDocumentId,
            Document::query()->sole()->id,
        );
        $this->assertSame(
            $originalPath,
            Document::query()->sole()->file_path,
        );
    }

    public function test_document_is_not_promoted_without_clean_scan_result(): void
    {
        Queue::fake();

        Storage::fake('documents');
        Storage::fake('document_quarantine');

        $user = User::factory()->create();

        $uploadService = app(DocumentUploadService::class);

        $document = $uploadService->store(
            $user,
            UploadedFile::fake()->createWithContent(
                'notes.txt',
                "Quarantined document content.\n",
            ),
            ProcessingProfile::Cloud,
        );

        try {
            $uploadService->promoteAfterCleanScan(
                $document,
                DocumentSecurityScanStatus::ScanFailed,
            );

            $this->fail('Expected promotion to be rejected.');
        } catch (LogicException) {
            //
        }

        Storage::disk('document_quarantine')
            ->assertExists($document->file_path);

        Storage::disk('documents')
            ->assertMissing($document->file_path);
    }
}
