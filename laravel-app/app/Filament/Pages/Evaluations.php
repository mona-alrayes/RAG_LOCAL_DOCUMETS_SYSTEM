<?php

namespace App\Filament\Pages;

use App\Services\Admin\AdminAccess;
use App\Services\Evaluation\DatasetUpload;
use App\Services\Evaluation\ExcelDataset;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class Evaluations extends Page
{
    protected string $view = 'filament.pages.evaluations';

    protected static ?string $navigationLabel = 'Upload dataset';

    protected static string|\UnitEnum|null $navigationGroup = 'Evaluation';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public function mount(): void
    {
        AdminAccess::authorize(auth()->user());
        $this->form->fill(['k' => 5]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Evaluation name')->required()->maxLength(255),
            TextInput::make('k')->label('Top K')->numeric()->integer()->minValue(1)->maxValue(20)->required(),
            FileUpload::make('dataset')->label('Dataset (Excel / JSON)')->acceptedFileTypes(['application/json', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->maxSize(1024)->storeFiles(false)->required()->helperText('Excel .xlsx أو JSON، حتى ١٠٠ سؤال / ١ MiB. استخدم القالب وأرقام المستندات والمقاطع الفعلية.')->columnSpanFull(),
        ])->statePath('data');
    }

    public function submit(): void
    {
        AdminAccess::authorize(auth()->user());
        $state = $this->form->getState();
        $run = app(DatasetUpload::class)->create(auth()->user(), $state['name'], $state['dataset'], (int) $state['k']);
        $this->redirect(EvaluationResults::getUrl(['run' => $run->id]));
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
        }, 'golden-dataset-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function downloadTemplate()
    {
        AdminAccess::authorize(auth()->user());

        return response()->streamDownload(function (): void {
            echo json_encode(['schema_version' => 1, 'dataset_version' => 'golden-v1', 'examples' => [['question' => 'Replace with your question', 'document_ids' => [1], 'relevant_chunks' => [['document_id' => 1, 'chunk_index' => 0]], 'expected_answer' => 'Optional reference; answer quality is not scored in this retrieval benchmark.']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'golden-dataset-template.json', ['Content-Type' => 'application/json']);
    }
}
