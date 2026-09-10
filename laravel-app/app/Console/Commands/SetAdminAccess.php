<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\AdminAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetAdminAccess extends Command
{
    protected $signature = 'rag:admin {email : Existing user email} {--revoke : Revoke administrator access}';

    protected $description = 'Grant or revoke Filament access for an existing verified user';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user || (! $this->option('revoke') && ! $user->hasVerifiedEmail())) {
            $this->error('An existing email-verified user is required.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($user): void {
            $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
            AdminAudit::record(null, $this->option('revoke') ? 'admin.revoke.cli' : 'admin.grant.cli', 'user', $user->id);
        });
        $this->info('Administrator access updated.');

        return self::SUCCESS;
    }
}
