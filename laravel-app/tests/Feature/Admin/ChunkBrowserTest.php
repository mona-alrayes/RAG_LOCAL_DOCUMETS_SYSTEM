<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Chunks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class ChunkBrowserTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    public function test_regular_user_cannot_open_a_concrete_chunk_url_or_invoke_chunk_loading(): void
    {
        Http::fake();
        $document = $this->document();
        $run = $document->processingRuns()->create(['profile' => 'cloud', 'status' => 'indexed', 'kind' => 'initial', 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        $document->forceFill(['active_processing_run_id' => $run->id])->save();
        $this->actingAs($document->user);
        $this->get('/admin/chunks/'.$run->id)->assertForbidden();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(Chunks::class, ['run' => $run->id])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_chunk_loading_rechecks_admin_access_after_mount(): void
    {
        config(['services.ai_service.base_url' => 'http://ai.test', 'services.ai_service.internal_api_key' => 'test-key']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->admin();
        $this->actingAs($admin);
        $document = $this->document();
        $run = $document->processingRuns()->create(['profile' => 'cloud', 'status' => 'indexed', 'kind' => 'initial', 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        $document->forceFill(['active_processing_run_id' => $run->id])->save();
        Http::fake(['*' => Http::response([], 503)]);
        $component = Livewire::test(Chunks::class, ['run' => $run->id]);
        $admin->forceFill(['is_admin' => false])->save();
        Http::fake();
        $component->call('loadChunks')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_browser_resolves_owner_and_profile_from_active_run_and_escapes_text(): void
    {
        config(['services.ai_service.base_url' => 'http://ai.test', 'services.ai_service.internal_api_key' => 'test-key']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());
        $doc = $this->document();
        $run = $doc->processingRuns()->create(['profile' => 'cloud', 'status' => 'indexed', 'kind' => 'initial', 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        $doc->forceFill(['active_processing_run_id' => $run->id])->save();
        Http::fake(['*' => Http::response(['document_id' => $doc->id, 'processing_run_id' => $run->id, 'processing_profile' => 'cloud', 'total' => 1, 'next_cursor' => null, 'chunks' => [['point_id' => 'abc', 'chunk_index' => 0, 'text' => '<script>alert(1)</script>', 'source' => 'notes.txt', 'page' => null, 'section' => null]]])]);
        Livewire::test(Chunks::class, ['run' => $run->id])->assertSee('notes.txt')->assertDontSeeHtml('<script>alert(1)</script>');
        Http::assertSent(fn ($request) => $request['user_id'] === $doc->user_id && $request['processing_run_id'] === $run->id);
        $doc->forceFill(['active_processing_run_id' => null])->save();
        Livewire::test(Chunks::class, ['run' => $run->id])->assertForbidden();
    }
}
