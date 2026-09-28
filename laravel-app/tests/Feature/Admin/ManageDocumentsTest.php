<?php

namespace Tests\Feature\Admin;

use App\Enums\ProcessingProfile;
use App\Filament\Resources\Pages\ListDocuments;
use App\Jobs\ProcessDocumentJob;
use App\Jobs\ScanDocumentSecurityJob;
use App\Models\User;
use App\Services\Admin\ManageDocuments;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class ManageDocumentsTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('document_quarantine');
        config(['services.ai_service.base_url' => 'http://ai.test', 'services.ai_service.internal_api_key' => 'test-key']);
        Http::fake(['*/api/v1/capabilities' => Http::response(['available_profiles' => ['cloud', 'hybrid_local']])]);
    }

    public function test_admin_upload_uses_owner_scope_and_security_scan(): void
    {
        $owner = User::factory()->create();
        config(['security.document_security_scan.enabled' => true]);
        $doc = app(ManageDocuments::class)->upload($this->admin(), $owner->id, UploadedFile::fake()->createWithContent('manual.txt', 'A safe original document.'), ProcessingProfile::Cloud);
        $this->assertSame($owner->id, $doc->user_id);
        Storage::disk('document_quarantine')->assertExists($doc->file_path);
        Queue::assertPushed(ScanDocumentSecurityJob::class);
        Queue::assertNotPushed(ProcessDocumentJob::class);
    }

    public function test_filament_reprocessing_preserves_active_run_and_queues_selected_profile(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $doc = $this->document();
        $active = $doc->processingRuns()->create(['profile' => 'cloud', 'status' => 'indexed', 'kind' => 'initial', 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        $doc->forceFill(['active_processing_run_id' => $active->id])->save();
        Livewire::test(ListDocuments::class)->callTableAction('reprocess', $doc, data: ['processing_profile' => 'hybrid_local'])->assertHasNoTableActionErrors();
        $this->assertSame($active->id, $doc->fresh()->active_processing_run_id);
        $this->assertSame('ready', $doc->fresh()->status->value);
        Queue::assertPushed(ProcessDocumentJob::class, fn ($job) => $job->queue === 'ai-local');
        Livewire::test(ListDocuments::class)->callTableAction('reprocess', $doc, data: ['processing_profile' => 'hybrid_local'])->assertHasTableActionErrors();
        $this->assertSame(2, $doc->processingRuns()->count());
    }

    public function test_infected_document_cannot_be_reprocessed_even_via_direct_service(): void
    {
        $doc = $this->document('infected');
        $this->expectException(ValidationException::class);
        app(ManageDocuments::class)->reprocess($this->admin(), $doc->id, ProcessingProfile::Cloud);
    }

    public function test_delete_cleans_all_scoped_runs_and_private_files(): void
    {
        $doc = $this->document();
        $run = $doc->processingRuns()->create(['profile' => 'cloud', 'status' => 'indexed', 'kind' => 'initial', 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        $doc->forceFill(['active_processing_run_id' => $run->id])->save();
        Http::fake(['*' => Http::response(['status' => 'deleted', 'document_id' => $doc->id, 'processing_run_id' => $run->id, 'processing_profile' => 'cloud'])]);
        app(ManageDocuments::class)->delete($this->admin(), $doc->id);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request['user_id'] === $doc->user_id && $request['processing_run_id'] === $run->id);
        Storage::disk('documents')->assertMissing($doc->file_path);
        $this->assertModelMissing($doc);
    }

    public function test_regular_user_cannot_download_another_owners_document_via_admin_service(): void
    {
        $this->expectException(AuthorizationException::class);
        app(ManageDocuments::class)->download(User::factory()->create(), 1);
    }
}
