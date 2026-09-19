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

    protected static string|\UnitEnum|null $navigationGroup = 'التقييم';

    protected static ?string $navigationLabel = 'النتائج والسجل';

    protected static ?string $modelLabel = 'تشغيل تقييم';

    protected static ?string $pluralModelLabel = 'النتائج والسجل';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('المعرف')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('اسم التقييم')
                    ->searchable(),

                TextColumn::make('dataset_version')
                    ->label('إصدار البيانات')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(
                        fn (?string $state): string => match ($state) {
                            'queued' => 'بانتظار التنفيذ',
                            'running' => 'قيد التنفيذ',
                            'completed' => 'مكتمل',
                            'failed' => 'فشل',
                            default => $state ?: 'غير محدد',
                        }
                    ),

                TextColumn::make('k')
                    ->label('K'),

                TextColumn::make('questions_count')
                    ->label('الأسئلة'),

                TextColumn::make('successful_questions')
                    ->label('الناجحة'),

                TextColumn::make('failed_questions')
                    ->label('الفاشلة'),

                TextColumn::make('metrics.recall_at_k')
                    ->label('Recall@K')
                    ->numeric(decimalPlaces: 4),

                TextColumn::make('metrics.ndcg_at_k')
                    ->label('nDCG@K')
                    ->numeric(decimalPlaces: 4),

                TextColumn::make('metrics.correctness')
                    ->label('Correctness')
                    ->numeric(decimalPlaces: 4),

                TextColumn::make('metrics.faithfulness')
                    ->label('Faithfulness')
                    ->numeric(decimalPlaces: 4),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'queued' => 'بانتظار التنفيذ',
                        'running' => 'قيد التنفيذ',
                        'completed' => 'مكتمل',
                        'failed' => 'فشل',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(
                fn (EvaluationRun $record) =>
                    EvaluationResults::getUrl([
                        'run' => $record->id,
                    ])
            )
            ->recordActions([
                Action::make('results')
                    ->label('عرض النتائج')
                    ->icon('heroicon-o-chart-bar-square')
                    ->url(
                        fn (EvaluationRun $record) =>
                            EvaluationResults::getUrl([
                                'run' => $record->id,
                            ])
                    ),

                Action::make('deleteEvaluation')
                    ->label('حذف التقييم')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('حذف تشغيل التقييم')
                    ->modalDescription(
                        'سيُحذف هذا التشغيل ونتائجه وملف مجموعة الاختبار المرفوع له نهائيًا. لن تُحذف الوثائق الأصلية أو المقاطع المفهرسة.'
                    )
                    ->visible(
                        fn (EvaluationRun $record) =>
                            in_array(
                                $record->status,
                                ['completed', 'failed'],
                                true,
                            )
                    )
                    ->action(
                        fn (EvaluationRun $record) =>
                            app(DeleteEvaluation::class)->execute(
                                auth()->user(),
                                $record->id,
                            )
                    ),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' =>
                Pages\ListEvaluationRuns::route('/'),
        ];
    }
}
