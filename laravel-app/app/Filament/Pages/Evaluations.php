<?php

namespace App\Filament\Pages;

use App\Models\Document;
use App\Services\Admin\AdminAccess;
use App\Services\Evaluation\DatasetUpload;
use App\Services\Evaluation\ExcelDataset;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class Evaluations extends Page
{
    protected string $view = 'filament.pages.evaluations';

    protected static ?string $navigationLabel = 'تقييم جديد';

    protected static string|\UnitEnum|null $navigationGroup = 'التقييم';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'تقييم جديد';

    public ?array $data = [];

    public function mount(): void
    {
        AdminAccess::authorize(auth()->user());

        $this->form->fill([
            'k' => 5,
            'pipeline' => 'dense_sparse_rrf_reranker',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('اسم التقييم')
                ->required()
                ->maxLength(255),

            Select::make('document_ids')
                ->label('الوثائق المفهرسة')
                ->multiple()
                ->searchable()
                ->preload()
                ->required()
                ->maxItems(20)
                ->options(fn (): array => Document::query()
                    ->with('activeProcessingRun')
                    ->where('status', 'ready')
                    ->whereNotNull('active_processing_run_id')
                    ->orderByDesc('id')
                    ->limit(200)
                    ->get()
                    ->mapWithKeys(fn (Document $document) => [
                        $document->id => sprintf(
                            '#%d · %s · %s',
                            $document->id,
                            $document->title ?: $document->original_name,
                            $document->activeProcessingRun?->profile->value ?? 'unknown',
                        ),
                    ])
                    ->all())
                ->helperText(
                    'اختر الوثائق المفهرسة التي سيبحث فيها التقييم. لا تضع document_id داخل Golden Dataset.'
                )
                ->columnSpanFull(),

            Select::make('pipeline')
                ->label('مسار الاسترجاع')
                ->options([
                    'dense_only' => 'بحث كثيف فقط (Dense only)',
                    'dense_sparse_rrf' => 'بحث كثيف + متناثر + RRF',
                    'dense_sparse_rrf_reranker' => 'بحث كثيف + متناثر + RRF + إعادة ترتيب',
                ])
                ->required(),

            TextInput::make('k')
                ->label('عدد النتائج المسترجعة K')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(20)
                ->required(),

            FileUpload::make('dataset')
                ->label('مجموعة الاختبار المرجعية Golden Dataset v2')
                ->acceptedFileTypes([
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->maxSize(1024)
                ->storeFiles(false)
                ->required()
                ->helperText(
                    'ارفع ملف Excel بصيغة xlsx يحتوي الأسئلة والإجابات والأدلة المرجعية. لا تضع document_id أو chunk_index.'
                )
                ->columnSpanFull(),
        ])->statePath('data');
    }

    public function submit(): void
    {
        AdminAccess::authorize(auth()->user());

        $this->resetErrorBag();

        try {
            $state = $this->form->getState();

            $run = app(DatasetUpload::class)->create(
                auth()->user(),
                $state['name'],
                $state['dataset'],
                (int) $state['k'],
                $state['pipeline'],
                array_map('intval', $state['document_ids']),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            Notification::make()
                ->title('تعذر بدء التقييم')
                ->body('راجع الحقول ورسائل الخطأ الظاهرة ثم حاول مرة أخرى.')
                ->danger()
                ->send();

            return;
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('تعذر بدء التقييم')
                ->body('حدث خطأ داخلي أثناء بدء التقييم. لم يتم إنشاء تشغيل جديد.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('تم بدء التقييم')
            ->body('تم قبول مجموعة الاختبار وبدأت معالجة الأسئلة.')
            ->success()
            ->send();

        $this->redirect(
            EvaluationResults::getUrl(['run' => $run->id])
        );
    }

    public function downloadExcelTemplate()
    {
        AdminAccess::authorize(auth()->user());

        return response()->streamDownload(function (): void {
            $path = tempnam(sys_get_temp_dir(), 'dataset-template');

            try {
                app(ExcelDataset::class)->writeTemplate($path);
                readfile($path);
            } finally {
                @unlink($path);
            }
        }, 'golden-dataset-v2-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
