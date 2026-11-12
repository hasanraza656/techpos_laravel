<?php

namespace Tests\Feature;

use App\Domain\Operations\ReportService;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\ModuleService;
use App\Services\TenantService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReadinessFoundationTest extends TestCase
{
    use RefreshDatabase;

    private $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->tenant = app(TenantService::class)->create($this->owner, 'Readiness Store');
        $this->actingAs($this->owner, 'web')->withHeader('X-Tenant-ID', $this->tenant->public_id);
    }

    public function test_child_entitlement_fails_closed_when_parent_is_disabled_or_removed(): void
    {
        $e = app(Entitlements::class);
        $this->assertTrue($e->enabled($this->tenant->id, 'offline_basic'));
        DB::table('tenant_feature_overrides')->insert(['tenant_id' => $this->tenant->id, 'feature_id' => DB::table('features')->where('key', 'pos')->value('id'), 'enabled' => false]);
        $this->assertFalse($e->enabled($this->tenant->id, 'offline_basic'));
        DB::table('tenant_feature_overrides')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('feature_entitlements')->where('plan_id', $e->plan($this->tenant->id)->id)->where('feature_id', DB::table('features')->where('key', 'pos')->value('id'))->delete();
        $this->assertFalse($e->enabled($this->tenant->id, 'receipts'));
        $this->assertTrue($e->included($this->tenant->id, 'receipts'));
    }

    public function test_corrupt_dependency_cycles_and_missing_dependencies_fail_closed(): void
    {
        DB::table('features')->where('key', 'pos')->update(['dependencies' => json_encode(['offline_basic'])]);
        $e = app(Entitlements::class);
        $this->assertFalse($e->enabled($this->tenant->id, 'pos'));
        $this->assertFalse($e->enabled($this->tenant->id, 'offline_basic'));
        DB::table('features')->where('key', 'pos')->update(['dependencies' => json_encode(['missing-capability'])]);
        $this->assertFalse($e->enabled($this->tenant->id, 'pos'));
        $this->assertFalse($e->enabled($this->tenant->id, 'does-not-exist'));
    }

    public function test_module_listing_has_bounded_catalog_queries(): void
    {
        DB::enableQueryLog();
        $rows = app(ModuleService::class)->listing($this->tenant->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertGreaterThan(20, count($rows));
        $this->assertLessThanOrEqual(8, count($queries), 'Module catalog must not query once per feature/dependency.');
    }

    public function test_direct_report_tool_cannot_bypass_branch_permission(): void
    {
        $staff = User::factory()->create();
        $c = new TenantContext;
        $c->tenant = $this->tenant;
        $c->user = $staff;
        $branch = DB::table('branches')->where('tenant_id', $this->tenant->id)->first();
        $this->expectException(HttpException::class);
        app(ReportService::class)->summary($c,$branch,[]);
    }
}
