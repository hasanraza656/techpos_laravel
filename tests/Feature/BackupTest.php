<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupService;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class BackupTest extends TestCase
{
    private string $dir;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        // Backups need a real database file, not the suite's in-memory SQLite.
        $this->dir = sys_get_temp_dir().'/techpos-backup-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->database = $this->dir.'/live.sqlite';
        touch($this->database);
        config(['database.connections.sqlite.database' => $this->database]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->seed();
        Storage::fake('local');
        config(['backup.key' => 'base64:'.base64_encode(random_bytes(32)), 'backup.disk' => 'local', 'backup.path' => 'backups', 'backup.keep' => 14]);
        app(TenantService::class)->create(User::factory()->create(), 'Backup Store');
        Storage::disk('local')->put('private/payment-proofs/proof.png', 'proof-bytes');
        Storage::disk('local')->put('private/tenant-media/t/logo.webp', 'logo-bytes');
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        parent::tearDown();
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_backup_is_encrypted_verifiable_and_restores_into_a_separate_target(): void
    {
        $this->artisan('techpos:backup')->assertSuccessful();
        $backups = app(BackupService::class);
        $name = $backups->list()[0]['name'];
        $raw = Storage::disk('local')->get('backups/'.$name);
        $this->assertStringStartsWith("TPBK1\n", $raw);
        $this->assertStringNotContainsString('Backup Store', $raw, 'Archive contents must be encrypted.');
        $this->assertStringNotContainsString('proof-bytes', $raw);

        $manifest = $backups->verify($name);
        $this->assertSame('sqlite', $manifest['driver']);
        $this->assertSame(1, $manifest['row_counts']['tenants']);
        $this->assertArrayHasKey('files/private/payment-proofs/proof.png', $manifest['entries']);
        $this->artisan('techpos:backup-verify')->assertSuccessful();

        $target = $this->dir.'/restored.sqlite';
        $files = $this->dir.'/files';
        $result = $backups->restore($name, $target, null, $files);
        $this->assertSame([], $result['row_differences']);
        $this->assertSame('proof-bytes', file_get_contents($files.'/private/payment-proofs/proof.png'));
        $this->assertSame('logo-bytes', file_get_contents($files.'/private/tenant-media/t/logo.webp'));
        $restored = new \PDO('sqlite:'.$target);
        $this->assertSame('Backup Store', $restored->query('SELECT name FROM tenants')->fetchColumn());
        $restored = null;
        // The live database is untouched.
        $this->assertSame(1, DB::table('tenants')->count());
    }

    public function test_wrong_key_or_tampering_is_rejected(): void
    {
        $backups = app(BackupService::class);
        $name = $backups->create()['name'];
        $path = 'backups/'.$name;
        $original = Storage::disk('local')->get($path);

        $tampered = $original;
        $tampered[200] = $tampered[200] === 'A' ? 'B' : 'A';
        Storage::disk('local')->put($path, $tampered);
        $this->assertVerifyFails($backups, $name, 'wrong BACKUP_KEY or the file was changed');

        Storage::disk('local')->put($path, substr($original, 0, -10));
        $this->assertVerifyFails($backups, $name, 'truncated');

        Storage::disk('local')->put($path, $original);
        config(['backup.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertVerifyFails($backups, $name, 'wrong BACKUP_KEY');
        $this->artisan('techpos:backup-verify', ['backup' => $name])->assertFailed();
    }

    public function test_restore_never_overwrites_and_retention_keeps_the_newest(): void
    {
        $backups = app(BackupService::class);
        $name = $backups->create()['name'];
        touch($this->dir.'/existing.sqlite');
        try {
            $backups->restore($name, $this->dir.'/existing.sqlite', null, $this->dir.'/f1');
            $this->fail('Existing SQLite file must not be overwritten.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }
        mkdir($this->dir.'/busy');
        touch($this->dir.'/busy/keep.txt');
        try {
            $backups->restore($name, $this->dir.'/new.sqlite', null, $this->dir.'/busy');
            $this->fail('A non-empty files folder must not be used.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not empty', $e->getMessage());
        }

        config(['backup.keep' => 2]);
        foreach ([1, 2, 3] as $day) {
            $this->travel(1)->days();
            $backups->create();
        }
        $this->assertCount(2, $backups->list());
        $this->assertNotContains($name, array_column($backups->list(), 'name'));
    }

    public function test_missing_key_and_in_memory_database_fail_clearly(): void
    {
        config(['backup.key' => null]);
        $this->artisan('techpos:backup')->assertFailed();
        config(['backup.key' => 'base64:'.base64_encode(random_bytes(32)), 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        try {
            app(BackupService::class)->create();
            $this->fail('In-memory databases cannot be backed up.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('in-memory', $e->getMessage());
        }
    }

    private function assertVerifyFails(BackupService $backups, string $name, string $message): void
    {
        try {
            $backups->verify($name);
            $this->fail('Verification should have failed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
