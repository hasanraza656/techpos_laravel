<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class SecurityReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_nonce_and_private_api_responses(): void
    {
        // The marketing layout's own JSON-LD script carries the nonce; Vite assets are not built in CI.
        $this->withoutVite();
        $first = $this->get('/')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Referrer-Policy', 'no-referrer');
        $csp = $first->headers->get('Content-Security-Policy');
        preg_match("/nonce-([^']+)/", $csp, $nonce);
        $this->assertNotEmpty($nonce[1]);
        $this->assertStringContainsString('nonce="'.$nonce[1].'"', $first->getContent());
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $second = $this->get('/health')->assertOk();
        $this->assertNotSame($csp, $second->headers->get('Content-Security-Policy'));
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_is_only_added_for_production_https(): void
    {
        $this->get('/health')->assertHeaderMissing('Strict-Transport-Security');
        $this->app['env'] = 'production';
        $this->get('https://example.test/health')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_forwarded_client_ip_is_only_trusted_from_configured_proxies(): void
    {
        \Illuminate\Support\Facades\Route::get('/_test/client-ip', fn (\Illuminate\Http\Request $r) => $r->ip());
        $viaProxy = fn () => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeader('X-Forwarded-For', '203.0.113.9')->get('/_test/client-ip')->getContent();

        $this->assertSame('10.0.0.5', $viaProxy(), 'Forwarded headers must be ignored when no proxy is configured.');
        config(['app.trusted_proxies' => '10.0.0.0/8, 192.168.1.1']);
        $this->assertSame('203.0.113.9', $viaProxy());
        config(['app.trusted_proxies' => '192.168.1.1']);
        $this->assertSame('10.0.0.5', $viaProxy(), 'An untrusted peer cannot spoof its client IP.');
    }

    public function test_release_check_reports_blockers_without_secrets(): void
    {
        $this->withoutMockingConsoleOutput();
        config(['database.connections.sqlite.password' => 'fixture-secret-never-print']);
        $buffer = new BufferedOutput;
        $code = Artisan::call('techpos:release-check', ['--json' => true], $buffer);
        $output = $buffer->fetch();
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $code);
        $this->assertSame('blocked', $report['status']);
        $this->assertFalse($report['checks']['framework']['passed']);
        $this->assertContains('backup_restore_drill', $report['pending_manual_gates']);
        $this->assertStringNotContainsString('fixture-secret-never-print', $output);
    }

    public function test_new_tenant_returns_database_defaults_to_domain_callers(): void
    {
        $this->seed();
        $tenant = app(TenantService::class)->create(User::factory()->create(), 'Default Snapshot');
        $this->assertSame('PKR', $tenant->currency);
        $this->assertSame('Asia/Karachi',$tenant->timezone);
    }
}
