<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\UserResource;
use App\Services\Admin\ManageUsers;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('createAccount')->label('إضافة مستخدم')
            ->schema(UserResource::accountFields(creating: true))
            ->action(fn (array $data) => app(ManageUsers::class)->save(auth()->user(), null, $data))];
    }
}
