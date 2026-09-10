<?php

namespace App\Filament\Widgets;

use App\Services\Admin\OperationalOverview;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationalStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $data = app(OperationalOverview::class)->read();

        return [
            Stat::make('Documents', array_sum($data['documents'])),
            Stat::make('Failed documents', $data['documents']['failed'] ?? 0)->color('danger'),
            Stat::make('Infected documents', $data['documents']['infected'] ?? 0)->color('danger'),
            Stat::make('Active indexed chunks', $data['active_chunks'])->description('Persisted counts verified at indexing; not a live Qdrant recount'),
            Stat::make('Average processing (ms)', $data['average_duration_ms'] === null ? '—' : number_format($data['average_duration_ms']))->description('Latest 100 completed runs with timestamps'),
        ];
    }
}
