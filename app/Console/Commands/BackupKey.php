<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class BackupKey extends Command
{
    protected $signature = 'techpos:backup-key';

    protected $description = 'Generate a new BACKUP_KEY (store it in .env AND somewhere off the server)';

    public function handle(): int
    {
        $this->line('BACKUP_KEY=base64:'.base64_encode(random_bytes(32)));
        $this->warn('Put this in .env and keep a second copy outside this server. Without it no backup can be restored.');
        $this->warn('Changing the key later only affects new backups; keep old keys for old archives.');

        return self::SUCCESS;
    }
}
