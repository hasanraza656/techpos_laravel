<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;

class MakePlatformAdmin extends Command
{
    protected $signature = 'techpos:make-admin {email}';

    protected $description = 'Promote an existing verified identity to platform superadmin (no default credentials)';

    public function handle(): int
    {
        $u = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();
        if (! $u || ! $u->hasVerifiedEmail() || ($u->status ?? 'active') !== 'active') {
            $this->error('Register, verify and keep this account active before promotion.');

            return self::FAILURE;
        }
        $u->forceFill(['is_superadmin' => true])->save();
        Audit::record('platform.admin_promoted', null, $u->public_id);
        $this->info('Platform access granted.');

        return self::SUCCESS;
    }
}
