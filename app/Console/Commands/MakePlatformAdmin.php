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
        $u = User::where('email', $this->argument('email'))->first();
        if (! $u || ! $u->hasVerifiedEmail()) {
            $this->error('Register and verify this account before promotion.');

            return self::FAILURE;
        }
        $u->forceFill(['is_superadmin' => true])->save();
        Audit::record('platform.admin_promoted', null, $u->public_id);
        $this->info('Platform access granted.');

        return self::SUCCESS;
    }
}
