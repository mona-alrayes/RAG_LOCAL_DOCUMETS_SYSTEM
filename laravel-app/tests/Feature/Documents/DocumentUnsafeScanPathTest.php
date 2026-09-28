<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentSecurityScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUnsafeScanPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_infected_document_is_not_promoted(): void
    {
        Queue::fake();

        Storage::fake('documents');
        Storage::fake('document_quarantine');

        $user = User::factory()->create();
        $uploadService = app(DocumentUploadService::class);

        $document = $uploadService->store(
            $user,
            UploadedFile::fake()->createWithContent(
                'infected.txt',
                "Unsafe document content.\n",
            ),
            ProcessingProfile::Cloud,
        );

        $uploadService->rejectAfterUnsafeScan(
            $document,
            DocumentSecurityScanStatus::Infected,
        );

        $document->refresh();

        $this->assertSame(DocumentStatus::Infected, $document->status);

        Storage::disk('document_quarantine')
            ->assertExists($document->file_path);

        Storage::disk('documents')
            ->assertMissing($document->file_path);

        $this->assertSame(1, Document::query()->count());
        $this->assertDatabaseCount('document_processing_runs', 0);
        Queue::assertNotPushed(ProcessDocumentJob::class);
    }

    public function test_scan_failed_document_remains_fail_closed(): void
    {
        Queue::fake();

        Storage::fake('documents');
        Storage::fake('document_quarantine');

        $user = User::factory()->create();
        $uploadService = app(DocumentUploadService::class);

        $document = $uploadService->store(
            $user,
            UploadedFile::fake()->createWithContent(
                'failed-scan.txt',
                "Untrusted document content.\n",
            ),
            ProcessingProfile::Cloud,
        );

        $uploadService->rejectAfterUnsafeScan(
            $document,
            DocumentSecurityScanStatus::ScanFailed,
        );

        $document->refresh();

        $this->assertSame(DocumentStatus::Failed, $document->status);

        Storage::disk('document_quarantine')
            ->assertExists($document->file_path);

        Storage::disk('documents')
            ->assertMissing($document->file_path);

        $this->assertSame(1, Document::query()->count());
        $this->assertDatabaseCount('document_processing_runs', 0);
        Queue::assertNotPushed(ProcessDocumentJob::class);
    }
}
