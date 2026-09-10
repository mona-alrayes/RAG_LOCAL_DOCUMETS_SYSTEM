<?php

namespace App\Filament\Resources\Pages;

use App\Enums\ProcessingProfile;
use App\Filament\Resources\DocumentResource;
use App\Services\Admin\AdminOperation;
use App\Services\Admin\ManageDocuments;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('uploadDocument')->label('رفع مستند')
            ->schema(DocumentResource::uploadFields())
            ->action(function (array $data): void {
                AdminOperation::run(fn () => app(ManageDocuments::class)->upload(auth()->user(), (int) $data['owner_id'], $data['document'], ProcessingProfile::from($data['processing_profile'])));
                Notification::make()->title('تم رفع المستند وجدولة الفحص والمعالجة')->success()->send();
            })];
    }
}
