<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\ProcessingRunResource;
use Filament\Resources\Pages\ListRecords;

class ListProcessingRuns extends ListRecords
{
    protected static string $resource = ProcessingRunResource::class;
}
