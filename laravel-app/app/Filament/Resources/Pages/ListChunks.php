<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\ChunkResource;
use Filament\Resources\Pages\ListRecords;

class ListChunks extends ListRecords
{
    protected static string $resource = ChunkResource::class;
}
