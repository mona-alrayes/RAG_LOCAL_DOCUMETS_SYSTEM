<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\MessageResource;
use Filament\Resources\Pages\ListRecords;

class ListMessages extends ListRecords
{
    protected static string $resource = MessageResource::class;
}
