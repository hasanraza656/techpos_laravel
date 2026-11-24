<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReleaseCheck extends Command
{
    protected $signature = 'techpos:release-check {--json : Machine-readable report without credentials}';

    protected $description = 'Report release blockers; configuration checks never replace operational signoff';

    public function handle(): int
    {
        $version = app()->version();
        $major = (int) explode('.', $version)[0];
        $framework = ($major === 12 && version_compare($version, '12.69.0', '>=') && now()->lt('2027-02-25')) || ($major === 13 && version_compare($version, '13.30.0', '>=') && now()->lt('2028-03-18'));
        $phpBranch = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $minimum = ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
        $php = isset($minimum[$phpBranch]) && version_compare(PHP_VERSION, $minimum[$phpBranch], '>=');
        $url = (string) config('app.url');
        $front = (string) config('app.frontend_url');
        $key = (string) config('app.key');
        $connection = config('database.default');
        $db = config('database.connections.'.$connection, []);
        $cors = config('cors.allowed_origins', []);
        $health = false;
        try {
            DB::select('SELECT 1');
            $health = true;
        } catch (\Throwable) {
        }
        // Immutable-history triggers need this on binlog-enabled MySQL without SUPER (RDS, Cloud SQL).
        $triggers = ['passed' => true, 'detail' => 'Not applicable for this connection.'];
        if ($health && in_array($db['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            try {
                $row = (array) DB::selectOne('SELECT @@log_bin AS log_bin, @@log_bin_trust_function_creators AS trust');
                $triggers = ['passed' => ! (int) $row['log_bin'] || (int) $row['trust'], 'detail' => 'Binary logging requires log_bin_trust_function_creators=1 (or SUPER) for the immutable-history trigger migrations.'];
            } catch (\Throwable) {
                $triggers = ['passed' => false, 'detail' => 'Could not read log_bin settings; verify trigger creation privileges manually.'];
            }
        }
        $latestBackup = null;
        try {
            $latestBackup = app(\App\Domain\Backup\BackupService::class)->list()[0] ?? null;
        } catch (\Throwable) {
        }
        $backupKey = (string) config('backup.key');
        $checks = [
            'backups' => ['passed' => $backupKey !== '' && $backupKey !== $key && $latestBackup !== null && $latestBackup['modified'] > now()->subHours(26)->getTimestamp(),
                'detail' => 'Set BACKUP_KEY (different from APP_KEY, stored off-server), keep the scheduler running and have a backup from the last 26 hours. Point BACKUP_DISK off-server and rehearse techpos:backup-restore.'],
            'framework' => ['passed' => $framework, 'detail' => 'Installed '.$version.'. Lifecycle/minimum patch matrix reviewed 2026-11-12; composer audit must also pass.'],
            'php_patch' => ['passed' => $php, 'detail' => 'Installed '.PHP_VERSION.'. Update the reviewed PHP security patch matrix before a later release.'],
            'production_environment' => ['passed' => app()->environment('production'), 'detail' => 'APP_ENV must be production.'],
            'debug_disabled' => ['passed' => ! config('app.debug'), 'detail' => 'Debug output must be disabled.'],
            'https_origins' => ['passed' => parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($front, PHP_URL_SCHEME) === 'https', 'detail' => 'Application and SPA need HTTPS origins.'],
            'secure_session' => ['passed' => config('session.secure') === true && in_array(config('session.driver'), ['database', 'redis'], true), 'detail' => 'Secure cookies and shared persistent session storage required.'],
            'explicit_cors' => ['passed' => ! in_array('*', $cors, true) && in_array($front, $cors, true) && empty(config('cors.allowed_origins_patterns')), 'detail' => 'Explicit trusted SPA origin; no wildcard/pattern origin.'],
            'application_key' => ['passed' => str_starts_with($key, 'base64:') && strlen(base64_decode(substr($key, 7), true) ?: '') === 32, 'detail' => 'Retain and securely back up the application encryption key.'],
            'database' => ['passed' => $connection === 'mysql' && $health && ! empty($db['password']), 'detail' => 'Reachable MySQL-compatible database with a configured password; grants still require manual review.'],
            'queued_jobs' => ['passed' => in_array(config('queue.default'), ['database', 'redis'], true), 'detail' => 'Durable job backend; worker/scheduler operation is a separate gate.'],
            'database_triggers' => $triggers,
            'trusted_proxies' => ['passed' => trim((string) config('app.trusted_proxies')) !== '' || ! app()->environment('production'), 'detail' => 'Set TRUSTED_PROXIES to the load balancer/reverse proxy addresses so rate limits and audit IPs see real clients (with no proxy, set it to this server address to confirm the review).'],
            'frontend_assets' => ['passed' => is_file(public_path('build/manifest.json')), 'detail' => 'Run npm ci && npm run build inside the Laravel app; marketing pages fail without the Vite manifest.'],
            'mail_delivery' => ['passed' => ! in_array(config('mail.default'), ['log', 'array', null], true), 'detail' => 'Configured delivery transport, followed by an actual delivery/consent test.'],
        ];
        $manual = ['functional_acceptance', 'independent_security_review', 'full_dependency_audit', 'backup_restore_drill', 'workers_scheduler_monitoring', 'accessibility_device_load_tests', 'legal_privacy_support', 'tax_hardware_approval_as_applicable'];
        $failed = array_keys(array_filter($checks, fn ($c) => ! $c['passed']));
        $report = ['status' => 'blocked', 'reviewed_at' => now()->toIso8601String(), 'checks' => $checks, 'failed_checks' => $failed, 'pending_manual_gates' => $manual, 'note' => 'This report does not authorize deployment. All manual gates need documented evidence and release approval. Runtime matrix is a reviewed baseline, not an up-to-date vulnerability feed.'];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($checks as $name => $check) {
                $this->line(($check['passed'] ? 'PASS ' : 'BLOCK ').$name.': '.$check['detail']);
            }$this->warn('BLOCK: manual release gates remain. See PENDING_WORK.md and deployment documentation.');
        }

        return self::FAILURE;
    }
}
