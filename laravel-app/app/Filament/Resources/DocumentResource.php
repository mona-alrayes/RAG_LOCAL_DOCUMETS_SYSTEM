<?php

namespace App\Filament\Resources;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Exceptions\AiServiceException;
use App\Models\Document;
use App\Models\User;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\AdminOperation;
use App\Services\Admin\ManageDocuments;
use App\Services\Admin\RetryProcessingRun;
use App\Services\Ai\ProcessingCapabilityService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentResource extends ReadOnlyAdminResource
{
    protected static ?string $model = Document::class;

    protected static ?string $slug = 'documents';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('title')->label('Title')->searchable(),
            TextColumn::make('original_name')->label('File')->searchable(),
            TextColumn::make('user.email')->label('Owner')->searchable(),
            TextColumn::make('status')->label('Status')->sortable(),
            TextColumn::make('file_type')->label('Type')->sortable(),
            TextColumn::make('file_size')->label('Bytes')->sortable(),
            TextColumn::make('created_at')->label('Uploaded')->sortable(),
        ])->filters([
            SelectFilter::make('status')->options(array_column(DocumentStatus::cases(), 'value', 'value')),
        ])->defaultSort('id', 'desc')->recordActions([
            ViewAction::make()->before(fn ($record) => AdminAudit::record(auth()->id(), 'record.view', $record->getTable(), $record->id)),
            Action::make('rename')->label('تعديل العنوان')->schema([TextInput::make('title')->label('العنوان')->required()->maxLength(255)])
                ->fillForm(fn (Document $record) => ['title' => $record->title ?: $record->original_name])
                ->action(fn (Document $record, array $data) => app(ManageDocuments::class)->rename(auth()->user(), $record->id, $data['title'])),
            Action::make('download')->label('تنزيل الأصل')->visible(fn (Document $record) => $record->status === DocumentStatus::Ready)
                ->action(fn (Document $record) => app(ManageDocuments::class)->download(auth()->user(), $record->id)),
            Action::make('reprocess')->label('إعادة المعالجة')->requiresConfirmation()
                ->modalDescription('تبقى النسخة الحالية فعّالة حتى نجاح المحاولة الجديدة. Cloud يرسل المحتوى إلى مزوّدي المسار السحابي؛ اختر المسار المناسب للوثيقة.')
                ->visible(fn (Document $record) => $record->status === DocumentStatus::Ready && $record->active_processing_run_id !== null)
                ->schema([self::profileField()])
                ->action(function (Document $record, array $data): void {
                    AdminOperation::run(fn () => app(ManageDocuments::class)->reprocess(auth()->user(), $record->id, ProcessingProfile::from($data['processing_profile'])));
                    Notification::make()->title('تمت جدولة إعادة المعالجة')->success()->send();
                }),
            Action::make('retry')->label('إعادة المحاولة الفاشلة')->requiresConfirmation()
                ->visible(fn (Document $record) => $record->latestAttempt?->status->value === 'failed' && in_array($record->status, [DocumentStatus::Failed, DocumentStatus::Ready], true))
                ->action(fn (Document $record) => AdminOperation::run(fn () => app(RetryProcessingRun::class)->execute(auth()->user(), $record->latestAttempt->id))),
            Action::make('deleteDocument')->label('حذف المستند')->color('danger')->requiresConfirmation()
                ->modalDescription('سيُحذف الملف وكل محاولاته وvectors الخاصة به. هذه العملية نهائية.')
                ->action(fn (Document $record) => AdminOperation::run(fn () => app(ManageDocuments::class)->delete(auth()->user(), $record->id))),
        ]);
    }

    public static function profileField(): Select
    {
        return Select::make('processing_profile')->label('مسار المعالجة')->required()->options(function () {
            try {
                $profiles = app(ProcessingCapabilityService::class)->availableProfiles();

                return collect($profiles)->mapWithKeys(fn ($profile) => [$profile->value => $profile->value])->all();
            } catch (AiServiceException) {
                return [];
            }
        });
    }

    public static function uploadFields(): array
    {
        return [
            Select::make('owner_id')->label('صاحب المستند')->searchable()->required()
                ->getSearchResultsUsing(fn (string $search) => User::whereNull('suspended_at')->where(fn ($q) => $q->where('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->limit(30)->pluck('email', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => User::find($value)?->email)
                ->default(fn () => auth()->id()),
            FileUpload::make('document')->label('المستند')->storeFiles(false)->required()
                ->acceptedFileTypes(['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain'])
                ->maxSize((int) config('documents.upload.max_size_kilobytes', 10240)),
            self::profileField(),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id'),
            TextEntry::make('title')->label('Title'),
            TextEntry::make('original_name')->label('File'),
            TextEntry::make('user.email')->label('Owner'),
            TextEntry::make('status')->label('Status'),
            TextEntry::make('file_type')->label('Type'),
            TextEntry::make('file_size')->label('Bytes'),
            TextEntry::make('created_at')->label('Uploaded'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDocuments::route('/')];
    }
}
