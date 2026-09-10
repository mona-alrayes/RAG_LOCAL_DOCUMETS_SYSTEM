<?php

namespace App\Filament\Resources;

use App\Models\Conversation;
use App\Services\Admin\AdminAudit;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ConversationResource extends ReadOnlyAdminResource
{
    protected static ?string $model = Conversation::class;

    protected static ?string $slug = 'conversations';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('title')->label('Title')->searchable(),
            TextColumn::make('user.email')->label('Owner')->searchable(),
            TextColumn::make('created_at')->label('Created')->sortable(),
        ])->defaultSort('id', 'desc')->recordActions([ViewAction::make()->before(fn ($record) => AdminAudit::record(auth()->id(), 'record.view', $record->getTable(), $record->id))]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id'),
            TextEntry::make('title')->label('Title'),
            TextEntry::make('user.email')->label('Owner'),
            TextEntry::make('created_at')->label('Created'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListConversations::route('/')];
    }
}
