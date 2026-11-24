<?php

namespace App\Domain\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Encrypted full backups of the business database plus private files, with verification and a
 * restore that only ever writes into a separate, empty target (never the live database).
 */
final class BackupService
{
    public const PREFIX = 'techpos-backup-';

    public const EXTENSION = '.tpbk';

    /** @return array{name: string, size: int, tables: int, rows: int, files: int} */
    public function create(): array
    {
        $key = BackupCipher::key(config('backup.key'));
        $work = $this->workDir();
        try {
            $connection = config('database.default');
            $driver = config("database.connections.$connection.driver");
            $dump = $work.'/'.$this->dumpName($driver);
            $this->dumpDatabase($connection, $driver, $dump);

            $zipPath = $work.'/archive.zip';
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the backup archive.');
            }
            $entries = [basename($dump) => hash_file('sha256', $dump)];
            $zip->addFile($dump, basename($dump));
            $local = Storage::disk('local');
            foreach (config('backup.directories', []) as $directory) {
                foreach ($local->allFiles($directory) as $file) {
                    $entries['files/'.$file] = hash_file('sha256', $local->path($file));
                    $zip->addFile($local->path($file), 'files/'.$file);
                }
            }
            $counts = $this->rowCounts(DB::connection($connection));
            $manifest = [
                'format' => 1,
                'app' => config('app.name'),
                'created_at' => now()->toIso8601String(),
                'driver' => $driver,
                'database' => basename($dump),
                'last_migration' => DB::connection($connection)->table('migrations')->orderByDesc('id')->value('migration'),
                // Taken right after the dump; rows written in between can differ slightly.
                'row_counts' => $counts,
                'entries' => $entries,
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();

            $name = self::PREFIX.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).self::EXTENSION;
            $encrypted = $work.'/'.$name;
            BackupCipher::encrypt($zipPath, $encrypted, $key);
            $stream = fopen($encrypted, 'rb');
            try {
                if (! Storage::disk(config('backup.disk'))->writeStream($this->target($name), $stream)) {
                    throw new RuntimeException('Could not write the backup to the configured disk.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $size = filesize($encrypted);
            $this->prune();
            Log::info('techpos.backup.created', ['name' => $name, 'bytes' => $size, 'files' => count($entries) - 1]);

            return ['name' => $name, 'size' => $size, 'tables' => count($counts), 'rows' => array_sum($counts), 'files' => count($entries) - 1];
        } finally {
            $this->cleanup($work);
        }
    }

    /** @return list<array{name: string, size: int, modified: int}> newest first */
    public function list(): array
    {
        $disk = Storage::disk(config('backup.disk'));

        return collect($disk->files(config('backup.path')))
            ->filter(fn ($p) => str_starts_with(basename($p), self::PREFIX) && str_ends_with($p, self::EXTENSION))
            ->map(fn ($p) => ['name' => basename($p), 'size' => $disk->size($p), 'modified' => $disk->lastModified($p)])
            ->sortByDesc('name')->values()->all();
    }

    /** Decrypts and checks every checksum without touching any database. */
    public function verify(string $name): array
    {
        $work = $this->workDir();
        try {
            return $this->open($name, $work)['manifest'];
        } finally {
            $this->cleanup($work);
        }
    }

    /**
     * Restores into an EMPTY target: a new SQLite file or an existing empty MySQL/MariaDB database
     * that is not the live one. Files go to a separate folder for manual reconciliation.
     *
     * @return array{manifest: array, files_dir: string, row_differences: array<string, array{backup: int, restored: int}>}
     */
    public function restore(string $name, ?string $sqlitePath, ?string $database, string $filesDir): array
    {
        // Refuse unsafe targets before decrypting anything.
        if ($sqlitePath !== null && file_exists($sqlitePath)) {
            throw new RuntimeException('The target SQLite file already exists. Choose a new path; nothing is overwritten.');
        }
        if (is_dir($filesDir) && (new \FilesystemIterator($filesDir))->valid()) {
            throw new RuntimeException('The files folder is not empty. Choose a new folder; nothing is overwritten.');
        }
        $work = $this->workDir();
        $zip = null;
        try {
            ['manifest' => $manifest, 'zip' => $zipPath] = $this->open($name, $work);
            $zip = new ZipArchive;
            $zip->open($zipPath);
            $dump = $work.'/'.$manifest['database'];
            $this->extract($zip, $manifest['database'], $dump);

            if ($manifest['driver'] === 'sqlite') {
                if (! $sqlitePath) {
                    throw new RuntimeException('This backup is SQLite: pass --sqlite-path for a new database file.');
                }
                copy($dump, $sqlitePath);
                $target = $this->temporaryConnection(['driver' => 'sqlite', 'database' => $sqlitePath, 'prefix' => '', 'foreign_key_constraints' => true]);
            } else {
                $target = $this->restoreMysql($dump, (string) $database);
            }

            foreach (array_keys($manifest['entries']) as $entry) {
                if (! str_starts_with($entry, 'files/')) {
                    continue;
                }
                $relative = substr($entry, 6);
                if (str_contains($relative, '..')) {
                    throw new RuntimeException('Unsafe path in backup: '.$relative);
                }
                $path = $filesDir.'/'.$relative;
                @mkdir(dirname($path), 0700, true);
                $this->extract($zip, $entry, $path);
            }
            $zip->close();
            $zip = null;

            $restored = $this->rowCounts(DB::connection($target));
            $differences = [];
            foreach ($manifest['row_counts'] as $table => $count) {
                if (($restored[$table] ?? -1) !== $count) {
                    $differences[$table] = ['backup' => $count, 'restored' => $restored[$table] ?? 0];
                }
            }
            DB::purge($target);

            return ['manifest' => $manifest, 'files_dir' => $filesDir, 'row_differences' => $differences];
        } finally {
            $zip?->close(); // release the handle so Windows can delete the work folder
            $this->cleanup($work);
        }
    }

    /** @return array{manifest: array, zip: string} */
    private function open(string $name, string $work): array
    {
        $disk = Storage::disk(config('backup.disk'));
        $path = $this->target(basename($name));
        if (! $disk->exists($path)) {
            throw new RuntimeException("Backup not found: $name");
        }
        $encrypted = $work.'/archive'.self::EXTENSION;
        $in = $disk->readStream($path);
        $out = fopen($encrypted, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $zipPath = $work.'/archive.zip';
        BackupCipher::decrypt($encrypted, $zipPath, BackupCipher::key(config('backup.key')));

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The decrypted archive is unreadable.');
        }
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (! is_array($manifest) || ($manifest['format'] ?? null) !== 1) {
                throw new RuntimeException('The backup manifest is missing or unsupported.');
            }
            foreach ($manifest['entries'] as $entry => $sha) {
                $stream = $zip->getStream($entry);
                if (! $stream) {
                    throw new RuntimeException("Backup entry missing: $entry");
                }
                $context = hash_init('sha256');
                hash_update_stream($context, $stream);
                fclose($stream);
                if (! hash_equals($sha, hash_final($context))) {
                    throw new RuntimeException("Checksum mismatch for $entry");
                }
            }
        } finally {
            $zip->close();
        }

        return ['manifest' => $manifest, 'zip' => $zipPath];
    }

    private function extract(ZipArchive $zip, string $entry, string $target): void
    {
        $in = $zip->getStream($entry);
        if (! $in) {
            throw new RuntimeException("Backup entry missing: $entry");
        }
        $out = fopen($target, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }

    private function dumpDatabase(string $connection, string $driver, string $target): void
    {
        if ($driver === 'sqlite') {
            if (config("database.connections.$connection.database") === ':memory:') {
                throw new RuntimeException('An in-memory SQLite database cannot be backed up.');
            }
            DB::connection($connection)->statement('VACUUM INTO ?', [$target]);

            return;
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Backups support MySQL/MariaDB and SQLite, not $driver.");
        }
        $config = config("database.connections.$connection");
        $raw = $target.'.raw';
        $this->runClient([config('backup.mysqldump'), '--defaults-extra-file='.$this->credentials($config, dirname($target)),
            '--single-transaction', '--quick', '--routines', '--triggers', '--hex-blob', '--no-tablespaces',
            '--default-character-set=utf8mb4', '--result-file='.$raw, $config['database']]);
        // Drop DEFINER clauses so restoring does not need the original account or SUPER.
        $in = fopen($raw, 'rb');
        $out = fopen($target, 'wb');
        while (($line = fgets($in)) !== false) {
            fwrite($out, preg_replace('/\/\*!50017 DEFINER=`[^`]*`@`[^`]*`\s*\*\/ ?|DEFINER=`[^`]*`@`[^`]*` ?/', '', $line));
        }
        fclose($in);
        fclose($out);
        unlink($raw);
    }

    private function restoreMysql(string $dump, string $database): string
    {
        $live = config('database.connections.'.config('database.default'));
        if ($database === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException('This backup is MySQL: pass --database with an existing EMPTY database name.');
        }
        if (in_array($live['driver'] ?? null, ['mysql', 'mariadb'], true) && $database === $live['database']) {
            throw new RuntimeException('Refusing to restore into the live database. Restore into a separate empty database and reconcile from there.');
        }
        $name = $this->temporaryConnection(['database' => $database] + $live);
        if (DB::connection($name)->select('SHOW TABLES') !== []) {
            DB::purge($name);
            throw new RuntimeException("Database $database is not empty; nothing was changed.");
        }
        $this->runClient([config('backup.mysql'), '--defaults-extra-file='.$this->credentials($live, dirname($dump)), '--default-character-set=utf8mb4', $database], $dump);

        return $name;
    }

    /** @return array<string, int> */
    private function rowCounts($db): array
    {
        $tables = $db->getDriverName() === 'sqlite'
            ? array_column($db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name')
            : array_map(fn ($r) => array_values((array) $r)[0], $db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
        sort($tables);
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $db->table($table)->count();
        }

        return $counts;
    }

    private function prune(): void
    {
        $disk = Storage::disk(config('backup.disk'));
        foreach (array_slice($this->list(), max(1, config('backup.keep', 14))) as $old) {
            $disk->delete($this->target($old['name']));
        }
    }

    private function credentials(array $config, string $dir): string
    {
        // A defaults file keeps the password out of the process list and shell history.
        $path = $dir.'/client.cnf';
        file_put_contents($path, "[client]\nuser=\"".addcslashes((string) $config['username'], '"\\')."\"\npassword=\"".addcslashes((string) $config['password'], '"\\')."\"\nhost=\"".$config['host']."\"\nport=".(int) $config['port']."\n");
        @chmod($path, 0600);

        return $path;
    }

    private function runClient(array $command, ?string $inputFile = null): void
    {
        $process = new Process($command, null, null, $inputFile ? fopen($inputFile, 'rb') : null, 3600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(basename($command[0]).' failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    private function temporaryConnection(array $config): string
    {
        $name = 'backup_restore_'.Str::lower(Str::random(8));
        config(["database.connections.$name" => $config]);

        return $name;
    }

    private function dumpName(?string $driver): string
    {
        return $driver === 'sqlite' ? 'database.sqlite' : 'database.sql';
    }

    private function target(string $name): string
    {
        return trim((string) config('backup.path'), '/').'/'.$name;
    }

    private function workDir(): string
    {
        $dir = storage_path('app/backup-work/'.Str::uuid());
        if (! mkdir($dir, 0700, true)) {
            throw new RuntimeException('Could not create a backup working folder.');
        }

        return $dir;
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        // Best effort: a cleanup problem must never hide the real backup/restore error.
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
