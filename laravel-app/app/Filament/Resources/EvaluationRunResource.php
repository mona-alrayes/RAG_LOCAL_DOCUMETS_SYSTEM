<?php

namespace App\Filament\Resources;

use App\Filament\Pages\EvaluationResults;
use App\Models\EvaluationRun;
use App\Services\Evaluation\DeleteEvaluation;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EvaluationRunResource extends ReadOnlyAdminResource
{
    protected static ?string $model = EvaluationRun::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Evaluation';

    protected static ?string $navigationLabel = 'Evaluation history';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(), TextColumn::make('name')->searchable(),
            TextColumn::make('dataset_version')->searchable(), TextColumn::make('status')->badge(),
            TextColumn::make('k'), TextColumn::make('questions_count')->label('Questions'),
            TextColumn::make('completed_questions')->label('Completed'),
            TextColumn::make('metrics.recall_at_k')->label('Recall@K')->numeric(decimalPlaces: 4),
            TextColumn::make('metrics.ndcg_at_k')->label('nDCG@K')->numeric(decimalPlaces: 4),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([SelectFilter::make('status')->options(['queued' => 'Queued', 'running' => 'Running', 'completed' => 'Completed', 'failed' => 'Failed'])])
            ->defaultSort('id', 'desc')->recordUrl(fn (EvaluationRun $record) => EvaluationResults::getUrl(['run' => $record->id]))
            ->recordActions([
                Action::make('results')->url(fn (EvaluationRun $record) => EvaluationResults::getUrl(['run' => $record->id])),
                Action::make('deleteEvaluation')->label('حذف التقييم')->color('danger')->requiresConfirmation()
                    ->modalDescription('سيحذف هذا التشغيل ونتائجه وملف dataset المرفوع له نهائياً. لن تحذف الوثائق الأصلية أو المقاطع المفهرسة.')
                    ->visible(fn (EvaluationRun $record) => in_array($record->status, ['completed', 'failed'], true))
                    ->action(fn (EvaluationRun $record) => app(DeleteEvaluation::class)->execute(auth()->user(), $record->id)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEvaluationRuns::route('/')];
    }
}
