<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\EvaluationRunResource;
use Filament\Resources\Pages\ListRecords;

class ListEvaluationRuns extends ListRecords
{
    protected static string $resource = EvaluationRunResource::class;
}
