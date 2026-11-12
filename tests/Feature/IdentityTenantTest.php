<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdentityTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_registration_creates_isolated_free_business(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Owner', 'business_name' => 'My Store', 'email' => 'owner@example.test', 'password' => 'StrongPass1234', 'password_confirmation' => 'StrongPass1234', 'is_superadmin' => true, 'tenant_id' => 77])->assertCreated()->assertJsonPath('user.is_superadmin', false);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseHas('tenant_users', ['is_owner' => true]);
        $this->assertEquals(DB::table('plans')->where('key', 'free')->value('id'), DB::table('subscriptions')->value('plan_id'));
    }

    public function test_validation_and_login_failure(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'password', 'business_name', 'email']);
        $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'invalid'])->assertUnprocessable();
    }

    public function test_login_logout_and_authentication(): void
    {
        $u = User::factory()->create(['password' => 'StrongPass1234']);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'StrongPass1234', 'remember' => true])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.email', $u->email);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_tenant_header_cannot_access_another_business(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $ta = app(TenantService::class)->create($a, 'A');
        $tb = app(TenantService::class)->create($b, 'B');
        $this->actingAs($a)->withHeader('X-Tenant-ID', $tb->public_id)->getJson('/api/v1/business')->assertForbidden();
        $this->withHeader('X-Tenant-ID', $ta->public_id)->getJson('/api/v1/business')->assertOk()->assertJsonPath('tenant.name', 'A');
        $this->withHeader('X-Tenant-ID', 'not-real')->getJson('/api/v1/business')->assertNotFound();
    }

    public function test_branch_write_enforces_permission_and_free_limit(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        $t = app(TenantService::class)->create($owner, 'Store');
        DB::table('tenant_users')->insert(['tenant_id' => $t->id, 'user_id' => $staff->id]);
        $this->actingAs($staff)->withHeader('X-Tenant-ID', $t->public_id)->postJson('/api/v1/branches', ['name' => 'Denied'])->assertForbidden();
        $this->actingAs($owner)->postJson('/api/v1/branches', ['name' => 'Second'])->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->assertDatabaseCount('branches', 1);
    }
}
