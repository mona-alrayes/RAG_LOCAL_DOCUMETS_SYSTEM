<?php

namespace App\Filament\Resources;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

abstract class ReadOnlyAdminResource extends Resource
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $user = Filament::auth()->user();

        return in_array($action, ['viewAny', 'view'], true)
            && $user instanceof User
            && $user->canAccessPanel(Filament::getCurrentPanel())
                ? Response::allow() : Response::deny();
    }
}
