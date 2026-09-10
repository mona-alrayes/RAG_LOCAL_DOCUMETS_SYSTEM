<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\AdminAuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AdminAuditLogResource::class;
}
