<?php

namespace App\Filament\Resources;

use App\Models\Message;
use App\Services\Admin\AdminAudit;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MessageResource extends ReadOnlyAdminResource
{
    protected static ?string $model = Message::class;

    protected static ?string $pluralModelLabel = 'الرسائل';

    protected static ?string $modelLabel = 'رسالة';

    protected static ?string $navigationLabel = 'الرسائل';

    protected static ?string $slug = 'messages';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('conversation.title')->label('Conversation')->searchable(),
            TextColumn::make('conversation.user.email')->label('Owner')->searchable(),
            TextColumn::make('role')->label('Role')->sortable(),
            TextColumn::make('status')->label('Status')->sortable(),
            TextColumn::make('created_at')->label('Created')->sortable(),
        ])->defaultSort('id', 'desc')->recordActions([ViewAction::make()->before(fn ($record) => AdminAudit::record(auth()->id(), 'record.view', $record->getTable(), $record->id))]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id'),
            TextEntry::make('conversation.title')->label('Conversation'),
            TextEntry::make('conversation.user.email')->label('Owner'),
            TextEntry::make('role')->label('Role'),
            TextEntry::make('status')->label('Status'),
            TextEntry::make('created_at')->label('Created'),
            TextEntry::make('content')->label('Message')->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMessages::route('/')];
    }
}
