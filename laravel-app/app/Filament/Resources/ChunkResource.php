<?php

namespace App\Filament\Resources;

use App\Filament\Pages\Chunks;
use App\Models\ProcessingRun;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChunkResource extends ReadOnlyAdminResource
{
    protected static ?string $model = ProcessingRun::class;

    protected static ?string $slug = 'chunks';

    protected static ?string $navigationLabel = 'Chunks';

    protected static ?string $pluralModelLabel = 'Chunks';

    protected static ?string $modelLabel = 'Chunk';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('document.user')->where('status', 'indexed')
            ->whereHas('document', fn (Builder $query) => $query->whereColumn('documents.active_processing_run_id', 'document_processing_runs.id'));
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('document_id')->label('Document ID')->sortable(),
            TextColumn::make('document.original_name')->label('الوثيقة')->searchable(),
            TextColumn::make('document.user.email')->label('المالك')->searchable(),
            TextColumn::make('id')->label('Processing run')->sortable(),
            TextColumn::make('profile')->label('Profile')->badge(),
            TextColumn::make('vector_count')->label('عدد المقاطع')->numeric(),
            TextColumn::make('indexed_at')->label('تاريخ الفهرسة')->dateTime()->sortable(),
        ])->filters([SelectFilter::make('profile')->options(['cloud' => 'Cloud', 'hybrid_local' => 'Hybrid Local'])])
            ->defaultSort('id', 'desc')->recordUrl(fn (ProcessingRun $record) => Chunks::getUrl(['run' => $record->id]))
            ->recordActions([Action::make('browse')->label('عرض المقاطع')->icon('heroicon-o-eye')->url(fn (ProcessingRun $record) => Chunks::getUrl(['run' => $record->id]))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListChunks::route('/')];
    }
}
