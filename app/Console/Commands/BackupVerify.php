<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupService;
use Illuminate\Console\Command;

final class BackupVerify extends Command
{
    protected $signature = 'techpos:backup-verify {backup? : Backup name; defaults to the newest}';

    protected $description = 'Decrypt a backup and check every checksum without touching any database';

    public function handle(BackupService $backups): int
    {
        $name = $this->argument('backup') ?? ($backups->list()[0]['name'] ?? null);
        if (! $name) {
            $this->error('No backups found.');

            return self::FAILURE;
        }
        try {
            $manifest = $backups->verify($name);
        } catch (\Throwable $e) {
            $this->error("Backup $name is NOT usable: ".$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Backup $name is intact.");
        $this->line(sprintf('Created %s · %s · %d tables · %d rows · %d files · schema %s',
            $manifest['created_at'], $manifest['driver'], count($manifest['row_counts']), array_sum($manifest['row_counts']), count($manifest['entries']) - 1, $manifest['last_migration']));

        return self::SUCCESS;
    }
}
