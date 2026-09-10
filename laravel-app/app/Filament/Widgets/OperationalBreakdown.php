<?php

namespace App\Filament\Widgets;

use App\Services\Admin\OperationalOverview;
use Filament\Widgets\Widget;

class OperationalBreakdown extends Widget
{
    protected string $view = 'filament.widgets.operational-breakdown';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return app(OperationalOverview::class)->read();
    }
}
