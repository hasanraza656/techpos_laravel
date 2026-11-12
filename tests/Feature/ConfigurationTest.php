<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LabelService;
use App\Services\TenantService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::factory()->create();
        $this->tenant = app(TenantService::class)->create($this->user, 'Store');
        $this->actingAs($this->user)->withHeader('X-Tenant-ID', $this->tenant->public_id);
    }

    public function test_onboarding_saves_industry_and_preserves_data_when_changed(): void
    {
        $this->putJson('/api/v1/onboarding', ['step' => 3, 'industry_key' => 'pharmacy', 'name' => 'Medicine shop', 'timezone' => 'Asia/Karachi'])->assertOk();
        $this->getJson('/api/v1/labels?locale=en')->assertOk()->assertJsonFragment(['key' => 'product', 'value' => 'Medicine', 'override' => 'Medicine', 'default' => 'Product', 'section' => 'navigation']);
        $this->putJson('/api/v1/onboarding', ['step' => 5, 'industry_key' => 'restaurant', 'completed' => true])->assertOk();
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseHas('label_overrides', ['value' => 'Medicine']);
        $this->putJson('/api/v1/onboarding', ['step' => 9, 'industry_key' => 'unknown'])->assertUnprocessable();
    }

    public function test_free_entitlement_and_dependencies_are_enforced(): void
    {
        $this->putJson('/api/v1/modules/batches', ['enabled' => true])->assertUnprocessable();
        $this->putJson('/api/v1/modules/pos', ['enabled' => false])->assertUnprocessable();
        $this->putJson('/api/v1/modules/receipts', ['enabled' => false])->assertOk();
        $this->putJson('/api/v1/modules/offline_basic', ['enabled' => false])->assertOk();
        $this->putJson('/api/v1/modules/pos', ['enabled' => false])->assertOk();
        $this->putJson('/api/v1/modules/receipts', ['enabled' => true])->assertUnprocessable();
        $this->assertDatabaseCount('branches', 1);
    }

    public function test_label_fallback_import_validation_and_reset(): void
    {
        $this->putJson('/api/v1/labels', ['locale' => 'en', 'labels' => ['product' => 'Item']])->assertOk();
        $service = app(LabelService::class);
        $this->assertEquals('Item', $service->resolve($this->tenant->id, 'product', 'ur', 'en'));
        $this->putJson('/api/v1/labels', ['locale' => 'ur', 'labels' => ['product' => 'دوا']])->assertOk();
        $this->getJson('/api/v1/labels?locale=ur')->assertJsonPath('direction', 'rtl');
        $this->assertEquals('دوا', $service->resolve($this->tenant->id, 'product', 'ur', 'en'));
        $this->putJson('/api/v1/labels', ['locale' => 'en', 'labels' => ['not-a-key' => 'Bad']])->assertUnprocessable();
        $this->postJson('/api/v1/labels/reset', ['locale' => 'en', 'section' => 'navigation'])->assertOk();
        $this->assertEquals('Product', $service->resolve($this->tenant->id, 'product', 'en', 'en'));
    }

    public function test_role_editor_and_branch_scope_do_not_grant_global_permissions(): void
    {
        $staff = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id]);
        $role = $this->postJson('/api/v1/roles', ['name' => 'Branch reader', 'permissions' => ['products.view']])->assertOk()->json('public_id');
        $branch = DB::table('branches')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->postJson('/api/v1/role-assignments', ['user' => $staff->public_id, 'role' => $role, 'branch' => $branch])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web')->getJson('/api/v1/roles')->assertForbidden();
        $this->getJson('/api/v1/business')->assertOk()->assertJsonPath('permissions', []);
        $context = app(TenantContext::class);
        $this->assertTrue($context->allows('products.view', DB::table('branches')->value('id')));
        $this->assertFalse($context->allows('products.view'));
    }
}
