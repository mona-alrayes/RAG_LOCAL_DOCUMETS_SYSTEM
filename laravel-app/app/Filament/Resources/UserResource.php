<?php

namespace App\Filament\Resources;

use App\Models\User;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\ManageUsers;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends ReadOnlyAdminResource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'users';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('name')->label('Name')->searchable(),
            TextColumn::make('email')->label('Email')->searchable(),
            TextColumn::make('email_verified_at')->label('Email verified')->sortable(),
            IconColumn::make('is_admin')->label('مدير')->boolean(),
            IconColumn::make('suspended_at')->label('معطّل')->getStateUsing(fn (User $record) => $record->suspended_at !== null)->boolean(),
            TextColumn::make('created_at')->label('Joined')->sortable(),
        ])->defaultSort('id', 'desc')->recordActions([
            ViewAction::make()->before(fn ($record) => AdminAudit::record(auth()->id(), 'record.view', $record->getTable(), $record->id)),
            Action::make('manage')->label('إدارة الحساب')->schema(self::accountFields())
                ->fillForm(fn (User $record) => ['name' => $record->name, 'email' => $record->email, 'is_admin' => $record->is_admin, 'verified' => $record->hasVerifiedEmail(), 'suspended' => $record->suspended_at !== null])
                ->action(fn (User $record, array $data) => app(ManageUsers::class)->save(auth()->user(), $record->id, $data)),
            Action::make('deleteAccount')->label('حذف الحساب')->color('danger')->requiresConfirmation()
                ->modalDescription('حذف الحساب ومحادثاته نهائياً. يجب حذف مستنداته أولاً، والحسابات المرتبطة بسجل تقييم يمكن تعطيلها للحفاظ على النتائج.')
                ->visible(fn (User $record) => $record->id !== auth()->id())
                ->action(fn (User $record) => app(ManageUsers::class)->delete(auth()->user(), $record->id)),
        ]);
    }

    public static function accountFields(bool $creating = false): array
    {
        return [
            TextInput::make('name')->label('الاسم')->required()->maxLength(255),
            TextInput::make('email')->label('البريد الإلكتروني')->email()->autocomplete('off')->required()->maxLength(255),
            TextInput::make('password')->label($creating ? 'كلمة المرور' : 'كلمة مرور جديدة (اختياري)')->password()->autocomplete('new-password')->revealable()->minLength(12)->required($creating)->confirmed(),
            TextInput::make('password_confirmation')->label('تأكيد كلمة المرور')->password()->autocomplete('new-password'),
            Toggle::make('verified')->label('البريد موثّق يدوياً')->default(false)->helperText('فعّلها فقط بعد التأكد من هوية صاحب الحساب.'),
            Toggle::make('is_admin')->label('صلاحية إدارة النظام')->default(false),
            Toggle::make('suspended')->label('تعطيل الوصول للحساب')->default(false),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id'),
            TextEntry::make('name')->label('Name'),
            TextEntry::make('email')->label('Email'),
            TextEntry::make('email_verified_at')->label('Email verified'),
            TextEntry::make('created_at')->label('Joined'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUsers::route('/')];
    }
}
