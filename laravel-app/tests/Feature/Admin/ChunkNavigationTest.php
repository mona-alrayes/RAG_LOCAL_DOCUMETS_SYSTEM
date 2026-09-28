<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Pages\ListChunks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class ChunkNavigationTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    public function test_chunks_navigation_shows_only_active_indexed_runs(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $doc = $this->document();
        $attributes = ['profile' => 'cloud', 'status' => 'indexed', 'kind' => 'initial', 'profile_snapshot' => [], 'stage_timings_ms' => []];
        $old = $doc->processingRuns()->create($attributes);
        $active = $doc->processingRuns()->create($attributes);
        $doc->forceFill(['active_processing_run_id' => $active->id])->save();
        $this->get('/admin/chunks')->assertOk();
        Livewire::test(ListChunks::class)->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$old]);
    }
}
