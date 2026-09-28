<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentSecurityScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;
use App\Jobs\ProcessDocumentJob;
use App\Jobs\ScanDocumentSecurityJob;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Services\Documents\DocumentProcessingDispatcher;
use App\Services\Documents\DocumentSecurityService;
use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\DocumentUploadService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class ScanDocumentSecurityJobTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * يعزل مسار security scan عن FastAPI الحقيقي عندما تنتقل
     * الوثيقة النظيفة إلى processing بعد نجاح الفحص.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/api/v1/capabilities' => Http::response([
                'available_profiles' => [
                    ProcessingProfile::Cloud->value,
                    ProcessingProfile::HybridLocal->value,
                ],
            ]),
        ]);
    }

    public function test_clean_scan_updates_status_and_promotes_document(): void
    {
        Queue::fake();

        Storage::fake('documents');
        Storage::fake('document_quarantine');

        $user = User::factory()->create();

        $document = app(DocumentStorageService::class)
            ->storeQuarantined(
                $user,
                UploadedFile::fake()->createWithContent(
                    'notes.txt',
                    "Clean document content.\n",
                ),
            );

        $document->refresh();

        $this->assertSame(
            DocumentStatus::Pending,
            $document->status,
        );

        $this->mock(
            DocumentSecurityService::class,
            function (MockInterface $mock) use ($document): void {
                $mock->shouldReceive('scan')
                    ->once()
                    ->andReturnUsing(
                        function (string $filePath) use ($document): DocumentSecurityScanStatus {
                            $this->assertSame(
                                DocumentStatus::Scanning,
                                $document->fresh()->status,
                            );

                            $this->assertSame(
                                Storage::disk('document_quarantine')
                                    ->path($document->file_path),
                                $filePath,
                            );

                            return DocumentSecurityScanStatus::Clean;
                        },
                    );
            },
        );

        $job = new ScanDocumentSecurityJob(
            $document,
            ProcessingProfile::Cloud,
        );

        $job->handle(
            app(DocumentSecurityService::class),
            app(DocumentUploadService::class),
            app(DocumentProcessingDispatcher::class),
        );

        $document->refresh();

        $this->assertSame(
            DocumentStatus::Queued,
            $document->status,
        );

        $processingRun = ProcessingRun::query()->sole();

        $this->assertSame(
            ProcessingProfile::Cloud,
            $processingRun->profile,
        );

        $this->assertSame(
            ProcessingRunStatus::Pending,
            $processingRun->status,
        );

        Queue::assertPushed(
            ProcessDocumentJob::class,
            fn (ProcessDocumentJob $job) => $job->processingRunId
                === $processingRun->id,
        );

        Storage::disk('documents')
            ->assertExists($document->file_path);

        Storage::disk('document_quarantine')
            ->assertMissing($document->file_path);
    }

    public function test_failed_scan_remains_quarantined_and_never_falls_back_to_permanent_storage(): void
    {
        Queue::fake();

        Storage::fake('documents');
        Storage::fake('document_quarantine');

        $user = User::factory()->create();

        $document = app(DocumentStorageService::class)
            ->storeQuarantined(
                $user,
                UploadedFile::fake()->createWithContent(
                    'notes.txt',
                    "Untrusted document content.\n",
                ),
            );

        $this->mock(
            DocumentSecurityService::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('scan')
                    ->once()
                    ->andReturn(DocumentSecurityScanStatus::ScanFailed);
            },
        );

        $job = new ScanDocumentSecurityJob(
            $document,
            ProcessingProfile::HybridLocal,
        );

        $job->handle(
            app(DocumentSecurityService::class),
            app(DocumentUploadService::class),
            app(DocumentProcessingDispatcher::class),
        );

        $document->refresh();

        $this->assertSame(
            DocumentStatus::Failed,
            $document->status,
        );

        Storage::disk('document_quarantine')
            ->assertExists($document->file_path);

        Storage::disk('documents')
            ->assertMissing($document->file_path);

        $this->assertDatabaseCount(
            'document_processing_runs',
            0,
        );

        Queue::assertNotPushed(ProcessDocumentJob::class);
    }
}
