<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupService;
use Illuminate\Console\Command;

final class BackupRestore extends Command
{
    protected $signature = 'techpos:backup-restore {backup : Backup name}
        {--sqlite-path= : New SQLite file to create (for SQLite backups)}
        {--database= : Existing EMPTY MySQL/MariaDB database, never the live one (for MySQL backups)}
        {--files-to= : New empty folder for private files (default storage/app/restored/<time>)}';

    protected $description = 'Restore a backup into a separate empty database and folder for checking and reconciliation';

    public function handle(BackupService $backups): int
    {
        $files = $this->option('files-to') ?: storage_path('app/restored/'.now()->format('Ymd-His'));
        try {
            $result = $backups->restore($this->argument('backup'), $this->option('sqlite-path'), $this->option('database'), $files);
        } catch (\Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('Restored into the separate target. The live database and files were not changed.');
        $this->line('Private files: '.$result['files_dir']);
        if ($result['row_differences']) {
            $this->warn('Row counts differ from the backup manifest (writes during the backup can cause small differences):');
            $this->table(['Table', 'Backup', 'Restored'], array_map(fn ($t, $d) => [$t, $d['backup'], $d['restored']], array_keys($result['row_differences']), $result['row_differences']));
        } else {
            $this->info('All table row counts match the backup.');
        }
        $this->line('Check totals in the restored copy before switching anything over. Never copy single tenant rows into the live database.');

        return self::SUCCESS;
    }
}
