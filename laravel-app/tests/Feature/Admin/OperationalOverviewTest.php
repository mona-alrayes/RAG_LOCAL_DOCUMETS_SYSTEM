<?php

namespace Tests\Feature\Admin;

use App\Services\Admin\OperationalOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class OperationalOverviewTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    public function test_counts_only_active_indexed_chunks_and_reports_failures_and_duration(): void
    {
        $doc = $this->document();
        $run = $doc->processingRuns()->create(['profile_snapshot' => [], 'stage_timings_ms' => [], 'profile' => 'cloud', 'status' => 'indexed', 'kind' => 'initial', 'vector_count' => 7, 'started_at' => now()->subSeconds(2), 'indexed_at' => now()]);
        $doc->forceFill(['active_processing_run_id' => $run->id])->save();
        $doc->processingRuns()->create(['profile_snapshot' => [], 'stage_timings_ms' => [], 'profile' => 'cloud', 'status' => 'indexed', 'kind' => 'initial', 'vector_count' => 99]);
        $this->document('failed');
        $this->document('infected');
        $data = app(OperationalOverview::class)->read();
        $this->assertSame(7, $data['active_chunks']);
        $this->assertSame(['failed' => 1, 'infected' => 1, 'ready' => 1], $data['documents']);
        $this->assertSame(2, $data['runs']['cloud / indexed']);
        $this->assertEquals(2000, $data['average_duration_ms']);
    }
}
