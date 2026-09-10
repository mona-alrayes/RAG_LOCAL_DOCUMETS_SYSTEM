<?php

namespace App\Filament\Resources;

use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;
use App\Filament\Pages\Chunks;
use App\Models\ProcessingRun;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\RetryProcessingRun;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProcessingRunResource extends ReadOnlyAdminResource
{
    protected static ?string $model = ProcessingRun::class;

    protected static ?string $slug = 'processing-runs';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('document.title')->sortable(),
            TextColumn::make('document.user.email')->sortable(),
            TextColumn::make('profile')->sortable(),
            TextColumn::make('kind')->sortable(),
            TextColumn::make('status')->sortable(),
            TextColumn::make('total_chunks')->sortable(),
            TextColumn::make('vector_count')->sortable(),
            TextColumn::make('error_code')->sortable(),
            TextColumn::make('started_at')->sortable(),
            TextColumn::make('indexed_at')->sortable(),
        ])->filters([
            SelectFilter::make('status')->options(array_column(ProcessingRunStatus::cases(), 'value', 'value')),
            SelectFilter::make('profile')->options(array_column(ProcessingProfile::cases(), 'value', 'value')),
        ])->defaultSort('id', 'desc')->recordActions([
            ViewAction::make()->before(fn ($record) => AdminAudit::record(auth()->id(), 'record.view', $record->getTable(), $record->id)),
            Action::make('chunks')->label('View chunks')
                ->visible(fn (ProcessingRun $record) => $record->status === ProcessingRunStatus::Indexed && (int) $record->document?->active_processing_run_id === $record->id)
                ->url(fn (ProcessingRun $record) => Chunks::getUrl(['run' => $record->id])),
            Action::make('retry')->label('Retry failed attempt')->requiresConfirmation()
                ->visible(fn (ProcessingRun $record) => $record->status === ProcessingRunStatus::Failed)
                ->action(function (ProcessingRun $record): void {
                    app(RetryProcessingRun::class)->execute(auth()->user(), $record->id);
                    Notification::make()->title('New processing attempt queued')->success()->send();
                }),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('document.title'),
            TextEntry::make('document.user.email'),
            TextEntry::make('profile'),
            TextEntry::make('kind'),
            TextEntry::make('status'),
            TextEntry::make('total_pages'),
            TextEntry::make('total_chunks'),
            TextEntry::make('vector_count'),
            TextEntry::make('error_code'),
            TextEntry::make('started_at'),
            TextEntry::make('indexing_started_at'),
            TextEntry::make('indexed_at'),
            TextEntry::make('failed_at'),
            KeyValueEntry::make('stage_timings_ms')->label('Stage durations (ms)'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListProcessingRuns::route('/')];
    }
}
