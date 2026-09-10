<?php

namespace App\Filament\Resources;

use App\Models\AdminAuditLog;
use App\Services\Admin\AdminAudit;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdminAuditLogResource extends ReadOnlyAdminResource
{
    protected static ?string $model = AdminAuditLog::class;

    protected static ?string $slug = 'audit-logs';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('actor_id')->sortable(),
            TextColumn::make('action')->sortable(),
            TextColumn::make('subject_type')->sortable(),
            TextColumn::make('subject_id')->sortable(),
            TextColumn::make('outcome')->sortable(),
            TextColumn::make('created_at')->sortable(),
        ])->defaultSort('id', 'desc')->recordActions([ViewAction::make()->before(fn ($record) => AdminAudit::record(auth()->id(), 'record.view', $record->getTable(), $record->id))]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id'),
            TextEntry::make('actor_id'),
            TextEntry::make('action'),
            TextEntry::make('subject_type'),
            TextEntry::make('subject_id'),
            TextEntry::make('outcome'),
            TextEntry::make('created_at'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }
}
