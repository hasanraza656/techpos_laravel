<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class BackupCreate extends Command
{
    protected $signature = 'techpos:backup {--list : Show existing backups instead of creating one}';

    protected $description = 'Create an encrypted backup of the database and private business files';

    public function handle(BackupService $backups): int
    {
        if ($this->option('list')) {
            $rows = array_map(fn ($b) => [$b['name'], number_format($b['size'] / 1048576, 2).' MB', date('Y-m-d H:i', $b['modified'])], $backups->list());
            $rows ? $this->table(['Backup', 'Size', 'Created'], $rows) : $this->warn('No backups found.');

            return self::SUCCESS;
        }
        try {
            $result = $backups->create();
        } catch (\Throwable $e) {
            Log::error('techpos.backup.failed', ['error' => $e->getMessage()]);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Backup created: {$result['name']}");
        $this->line(sprintf('%s MB · %d tables · %d rows · %d private files', number_format($result['size'] / 1048576, 2), $result['tables'], $result['rows'], $result['files']));
        $this->line('Verify it with: php artisan techpos:backup-verify '.$result['name']);

        return self::SUCCESS;
    }
}
