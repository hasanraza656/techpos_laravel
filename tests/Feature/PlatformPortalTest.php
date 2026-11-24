<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\TenantService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    private User $owner;

    private $tenant;

    private $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->root = User::factory()->create(['is_superadmin' => true, 'password' => 'AdminPassword1234']);
        $this->owner = User::factory()->create();
        $this->tenant = app(TenantService::class)->create($this->owner, 'Alpha Shop');
        $this->other = app(TenantService::class)->create(User::factory()->create(), 'Beta Shop');
        $this->as($this->root);
    }

    private function as(User $user): static
    {
        $this->actingAs($user, 'web');

        return $this->actingAs($user, 'sanctum');
    }

    private function command(array $d = []): array
    {
        return $d + ['request_key' => (string) Str::uuid(), 'password' => 'AdminPassword1234', 'reason' => 'Explicit administrator test action'];
    }

    private function product($tenant, string $sku = 'TEA'): string
    {
        $owner = User::findOrFail(DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('is_owner', true)->value('user_id'));
        $p = $this->as($owner)->withHeader('X-Tenant-ID', $tenant->public_id)->postJson('/api/v1/operations/products', ['name' => 'Sample '.$sku, 'sku' => $sku, 'type' => 'standard', 'unit' => 'piece', 'cost_price' => '60', 'selling_price' => '100', 'tax_rate' => '0', 'tax_inclusive' => false, 'reorder_level' => '2', 'active' => true, 'barcodes' => []])->assertCreated()->json('public_id');
        $this->as($this->root);

        return $p;
    }

    private function staff(array $permissions): User
    {
        $u = User::factory()->create();
        $id = DB::table('platform_roles')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => 'Restricted '.Str::random(6), 'version' => 1]);
        foreach ($permissions as $p) {
            DB::table('platform_role_permissions')->insert(['role_id' => $id, 'permission' => $p]);
        }DB::table('platform_user_roles')->insert(['user_id' => $u->id, 'role_id' => $id]);

        return $u;
    }

    public function test_root_sees_all_clients_and_scoped_inventory_without_tenant_membership(): void
    {
        $this->product($this->tenant, 'A');
        $this->product($this->other, 'B');
        $this->getJson('/api/v1/platform/clients')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/platform/tenants')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/platform/inventory')->assertOk()->assertJsonPath('total', 2)->assertJsonStructure(['data' => [['cost_price', 'stock_value']]]);
        $this->getJson('/api/v1/platform/inventory?client='.$this->tenant->public_id)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.business_name', 'Alpha Shop');
        $this->assertDatabaseMissing('tenant_users', ['user_id' => $this->root->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.data_access', 'actor_id' => $this->root->id]);
    }

    public function test_restricted_platform_staff_cannot_read_costs_payments_or_mutate_clients(): void
    {
        $this->product($this->tenant);
        $staff = $this->staff(['inventory.view', 'clients.view']);
        $this->as($staff)->getJson('/api/v1/platform/me')->assertOk()->assertJsonPath('root', false);
        $r = $this->getJson('/api/v1/platform/inventory')->assertOk();
        $this->assertArrayNotHasKey('cost_price', $r->json('data.0'));
        $this->assertArrayNotHasKey('stock_value', $r->json('data.0'));
        $this->getJson('/api/v1/platform/payments')->assertForbidden();
        $this->postJson('/api/v1/platform/clients/'.$this->tenant->public_id.'/state', $this->command(['action' => 'suspend']))->assertForbidden();
        $this->as($this->owner)->getJson('/api/v1/platform/clients')->assertForbidden();
    }

    public function test_client_create_update_suspend_archive_restore_is_idempotent_and_preserves_records(): void
    {
        $data = $this->command(['name' => 'New Shop', 'owner_name' => 'New Owner', 'owner_email' => 'new-owner@example.test', 'owner_password' => 'NewOwnerPass123', 'country' => 'PK', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi']);
        $id = $this->postJson('/api/v1/platform/clients', $data)->assertOk()->json('public_id');
        $this->postJson('/api/v1/platform/clients', $data)->assertOk()->assertJsonPath('public_id', $id);
        $this->assertDatabaseCount('tenants', 3);
        $this->postJson('/api/v1/platform/clients', array_replace($data, ['name' => 'Different Shop']))->assertConflict();
        $this->putJson('/api/v1/platform/clients/'.$id, $this->command(['name' => 'Updated Shop', 'country' => 'PK', 'timezone' => 'Asia/Karachi', 'email' => 'billing@example.test', 'address' => 'Test street']))->assertOk();
        foreach (['suspend', 'archive', 'activate'] as $action) {
            $this->postJson('/api/v1/platform/clients/'.$id.'/state', $this->command(['action' => $action]))->assertOk();
            if ($action === 'archive') {
                // Suspending an archived client must not quietly restore it.
                $this->postJson('/api/v1/platform/clients/'.$id.'/state', $this->command(['action' => 'suspend']))->assertUnprocessable();
                $this->assertNotNull(\Illuminate\Support\Facades\DB::table('tenants')->where('public_id', $id)->value('deleted_at'));
            }
        }$this->getJson('/api/v1/platform/clients/'.$id)->assertOk()->assertJsonPath('name', 'Updated Shop')->assertJsonPath('status', 'active');
        $this->assertDatabaseCount('subscriptions', 3);
    }

    public function test_password_reason_and_request_key_are_required_for_sensitive_actions(): void
    {
        $url = '/api/v1/platform/clients/'.$this->tenant->public_id.'/state';
        $this->postJson($url, ['action' => 'suspend'])->assertUnprocessable();
        $this->postJson($url, $this->command(['action' => 'suspend', 'password' => 'wrong']))->assertUnprocessable();
        $this->assertDatabaseHas('tenants', ['id' => $this->tenant->id, 'status' => 'active']);
    }

    public function test_invoice_issue_is_unpaid_idempotent_printable_and_financial_snapshot_is_immutable(): void
    {
        $data = $this->command(['client' => $this->tenant->public_id, 'plan_key' => 'standard', 'due_on' => now()->addDays(7)->toDateString()]);
        $id = $this->postJson('/api/v1/platform/invoices', $data)->assertOk()->json('public_id');
        $this->postJson('/api/v1/platform/invoices', $data)->assertOk()->assertJsonPath('public_id', $id);
        $this->assertDatabaseCount('billing_invoices', 1);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $this->tenant->id, 'plan_id' => DB::table('plans')->where('key', 'free')->value('id')]);
        $this->get('/api/v1/platform/invoices/'.$id.'/document')->assertOk()->assertSee('Alpha Shop');
        try {
            DB::table('billing_invoices')->where('public_id', $id)->update(['amount' => '1']);
            $this->fail('Invoice amount was rewritten.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }$this->postJson('/api/v1/platform/invoices/'.$id.'/void', $this->command())->assertOk();
        $this->assertDatabaseHas('billing_invoices', ['public_id' => $id, 'status' => 'void']);
    }

    public function test_payment_approval_marks_one_invoice_paid_and_extends_only_once(): void
    {
        $this->as($this->owner)->withHeader('X-Tenant-ID', $this->tenant->public_id);
        $method = DB::table('payment_methods')->value('id');
        $payment = $this->postJson('/api/v1/payments', ['plan_key' => 'standard', 'payment_method_id' => $method])->assertCreated()->json('public_id');
        $this->post('/api/v1/payments/'.$payment.'/proof', ['proof' => UploadedFile::fake()->image('transfer.png')], ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/api/v1/payments/'.$payment.'/submit', ['transaction_reference' => 'REAL-QA-123', 'amount' => '5000', 'paid_on' => now()->toDateString()])->assertOk();
        $this->as($this->root)->postJson('/api/v1/platform/payments/'.$payment.'/review', ['decision' => 'approved', 'password' => 'AdminPassword1234'])->assertOk();
        $end = DB::table('subscriptions')->where('tenant_id', $this->tenant->id)->value('ends_at');
        $this->postJson('/api/v1/platform/payments/'.$payment.'/review', ['decision' => 'approved', 'password' => 'AdminPassword1234'])->assertUnprocessable();
        $this->assertDatabaseCount('billing_invoices', 1);
        $this->assertDatabaseHas('billing_invoices', ['status' => 'paid', 'amount' => 5000]);
        $this->getJson('/api/v1/platform/payments/'.$payment)->assertOk()->assertJsonPath('status', 'approved')->assertJsonMissingPath('reference_fingerprint')->assertJsonMissingPath('path');
        $this->assertSame($end, DB::table('subscriptions')->where('tenant_id', $this->tenant->id)->value('ends_at'));
        $this->getJson('/api/v1/platform/overview')->assertOk()->assertJsonPath('active_paid', 1)->assertJsonPath('collected_this_month', '5000.00');
    }

    public function test_global_user_crud_protects_root_and_active_business_owner(): void
    {
        $id = $this->postJson('/api/v1/platform/users', $this->command(['name' => 'Managed User', 'email' => 'managed@example.test', 'new_password' => 'ManagedPassword123']))->assertOk()->json('public_id');
        $this->putJson('/api/v1/platform/users/'.$id, $this->command(['name' => 'Renamed User', 'email' => 'managed@example.test']))->assertOk();
        foreach (['verify', 'suspend', 'activate', 'archive', 'activate'] as $action) {
            $this->postJson('/api/v1/platform/users/'.$id.'/state', $this->command(['action' => $action]))->assertOk();
        }$this->getJson('/api/v1/platform/users/'.$id)->assertOk()->assertJsonPath('name', 'Renamed User')->assertJsonMissingPath('password');
        $this->postJson('/api/v1/platform/users/'.$this->root->public_id.'/state', $this->command(['action' => 'archive']))->assertUnprocessable();
        $this->postJson('/api/v1/platform/users/'.$this->owner->public_id.'/state', $this->command(['action' => 'suspend']))->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $this->owner->id, 'status' => 'active']);
    }

    public function test_platform_role_versions_assignments_and_delegation_are_enforced(): void
    {
        $id = $this->postJson('/api/v1/platform/roles', $this->command(['name' => 'Inventory observer', 'permissions' => ['clients.view', 'inventory.view']]))->assertOk()->json('public_id');
        $staff = User::factory()->create();
        $this->postJson('/api/v1/platform/users/'.$staff->public_id.'/platform-roles', $this->command(['roles' => [$id]]))->assertOk();
        $this->as($staff)->getJson('/api/v1/platform/clients')->assertOk();
        $this->getJson('/api/v1/platform/payments')->assertForbidden();
        $this->as($this->root)->putJson('/api/v1/platform/roles/'.$id, $this->command(['name' => 'Updated observer', 'permissions' => ['clients.view'], 'version' => 1]))->assertOk();
        $this->putJson('/api/v1/platform/roles/'.$id, $this->command(['name' => 'Stale observer', 'permissions' => ['clients.view'], 'version' => 1]))->assertConflict();
        $this->deleteJson('/api/v1/platform/roles/'.$id, $this->command())->assertUnprocessable();
    }

    public function test_client_role_assignment_rejects_foreign_branch_and_owner_replacement(): void
    {
        $role = DB::table('roles')->where('tenant_id', $this->tenant->id)->where('name', 'Read-only Auditor')->value('public_id');
        $staff = User::factory()->create();
        $branch = DB::table('branches')->where('tenant_id', $this->other->id)->value('public_id');
        $url = '/api/v1/platform/clients/'.$this->tenant->public_id.'/memberships';
        $this->postJson($url, $this->command(['user' => $staff->public_id, 'action' => 'assign', 'role' => $role, 'branch' => $branch]))->assertNotFound();
        $this->postJson($url, $this->command(['user' => $this->owner->public_id, 'action' => 'remove']))->assertUnprocessable();
        $this->postJson($url, $this->command(['user' => $staff->public_id, 'action' => 'assign', 'role' => $role]))->assertOk();
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $this->tenant->id, 'user_id' => $staff->id]);
        $this->postJson($url, $this->command(['user' => $staff->public_id, 'action' => 'remove']))->assertOk();
    }

    public function test_subscription_state_changes_and_free_fallback_do_not_delete_products(): void
    {
        $this->product($this->tenant);
        $url = '/api/v1/platform/clients/'.$this->tenant->public_id.'/subscription';
        $this->postJson($url, $this->command(['action' => 'suspend']))->assertOk();
        $this->getJson('/api/v1/platform/subscriptions')->assertOk()->assertJsonFragment(['subscription_status' => 'suspended', 'effective_plan' => 'free']);
        $this->postJson($url, $this->command(['action' => 'resume']))->assertOk();
        $this->postJson($url, $this->command(['action' => 'grace', 'days' => 7]))->assertUnprocessable();
        $this->postJson($url, $this->command(['action' => 'cancel']))->assertOk();
        $this->postJson($url, $this->command(['action' => 'free']))->assertOk();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('subscription_events', 4);
    }

    public function test_entitlement_dependencies_and_free_core_cannot_be_bypassed(): void
    {
        $this->putJson('/api/v1/platform/plans/standard/features', $this->command(['features' => ['expiry']]))->assertUnprocessable();
        $this->putJson('/api/v1/platform/plans/free/features', $this->command(['features' => ['inventory']]))->assertUnprocessable();
        $this->getJson('/api/v1/platform/entitlements')->assertOk();
    }

    public function test_archived_user_sessions_are_denied_and_ordinary_users_cannot_enter_portal(): void
    {
        $u = User::factory()->create();
        DB::table('users')->where('id', $u->id)->update(['status' => 'archived']);
        $this->as($u)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($this->owner)->getJson('/api/v1/platform/me')->assertForbidden();
    }

    public function test_user_and_role_creation_retries_do_not_duplicate_records_or_log_secrets(): void
    {
        $data = $this->command(['name' => 'Retry User', 'email' => 'retry-user@example.test', 'new_password' => 'NewPrivatePassword123']);
        $id = $this->postJson('/api/v1/platform/users', $data)->assertOk()->json('public_id');
        $this->postJson('/api/v1/platform/users', $data)->assertOk()->assertJsonPath('public_id', $id);
        $role = $this->command(['name' => 'Retry Role', 'permissions' => ['clients.view']]);
        $roleId = $this->postJson('/api/v1/platform/roles', $role)->assertOk()->json('public_id');
        $this->postJson('/api/v1/platform/roles', $role)->assertOk()->assertJsonPath('public_id', $roleId);
        foreach (DB::table('platform_commands')->get() as $row) {
            $this->assertStringNotContainsString('NewPrivatePassword123', json_encode($row));
            $this->assertStringNotContainsString('AdminPassword1234', json_encode($row));
        }
        $this->assertSame(1, User::where('email', 'retry-user@example.test')->count());
    }

    public function test_restricted_role_manager_cannot_escalate_grants_or_edit_a_root(): void
    {
        $staff = $this->staff(['access.view', 'access.manage', 'users.view', 'users.manage']);
        $staff->password = 'StaffPassword123';
        $staff->save();
        $this->as($staff);
        $this->postJson('/api/v1/platform/roles', $this->command(['name' => 'Escalation', 'permissions' => ['payments.view', 'payments.review'], 'password' => 'StaffPassword123']))->assertForbidden();
        $this->postJson('/api/v1/platform/users/'.$this->root->public_id.'/state', $this->command(['action' => 'suspend', 'password' => 'StaffPassword123']))->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $this->root->id, 'status' => 'active']);
    }

    public function test_delegated_admins_cannot_take_over_or_demote_more_privileged_staff(): void
    {
        $billing = $this->staff(['billing.view', 'billing.manage', 'access.view', 'access.manage']);
        $billingRole = DB::table('platform_user_roles as a')->join('platform_roles as r', 'r.id', '=', 'a.role_id')->where('a.user_id', $billing->id)->first(['r.public_id', 'r.version']);
        $manager = $this->staff(['users.view', 'users.manage', 'access.view', 'access.manage', 'client_access.view', 'client_access.manage']);
        $manager->password = 'StaffPassword123';
        $manager->save();
        $this->as($manager);
        $cmd = fn (array $d) => $this->command($d + ['password' => 'StaffPassword123']);
        $this->putJson('/api/v1/platform/users/'.$billing->public_id, $cmd(['name' => 'Hijacked', 'email' => $billing->email, 'new_password' => 'TakenOverPass123']))->assertForbidden();
        $this->postJson('/api/v1/platform/users/'.$billing->public_id.'/state', $cmd(['action' => 'suspend']))->assertForbidden();
        $this->postJson('/api/v1/platform/users/'.$billing->public_id.'/platform-roles', $cmd(['roles' => []]))->assertForbidden();
        $this->putJson('/api/v1/platform/roles/'.$billingRole->public_id, $cmd(['name' => 'Stripped', 'permissions' => ['access.view', 'access.manage'], 'version' => $billingRole->version]))->assertForbidden();
        $role = DB::table('roles')->where('tenant_id', $this->tenant->id)->where('name', 'Read-only Auditor')->value('public_id');
        $this->postJson('/api/v1/platform/clients/'.$this->tenant->public_id.'/memberships', $cmd(['user' => $manager->public_id, 'action' => 'assign', 'role' => $role]))->assertUnprocessable();
        $this->assertDatabaseMissing('tenant_users', ['user_id' => $manager->id]);
        $this->assertDatabaseHas('users', ['id' => $billing->id, 'name' => $billing->name, 'status' => 'active']);
        $this->assertDatabaseHas('platform_user_roles', ['user_id' => $billing->id]);
    }

    public function test_lapsed_grace_expires_and_suspended_subscription_cannot_be_graced(): void
    {
        $plan = DB::table('plans')->where('key', 'standard')->value('id');
        DB::table('subscriptions')->where('tenant_id', $this->tenant->id)->update(['plan_id' => $plan, 'status' => 'grace', 'ends_at' => now()->subDays(10), 'grace_ends_at' => now()->subDay()]);
        DB::table('subscriptions')->where('tenant_id', $this->other->id)->update(['plan_id' => $plan, 'status' => 'grace', 'ends_at' => now()->subDays(10), 'grace_ends_at' => now()->addDay()]);
        $this->artisan('techpos:expire-subscriptions')->assertSuccessful();
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $this->tenant->id, 'status' => 'expired']);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $this->other->id, 'status' => 'grace']);
        DB::table('subscriptions')->where('tenant_id', $this->tenant->id)->update(['status' => 'suspended', 'status_before_suspension' => 'active']);
        $this->postJson('/api/v1/platform/clients/'.$this->tenant->public_id.'/subscription', $this->command(['action' => 'grace', 'days' => 7]))->assertUnprocessable();
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $this->tenant->id, 'status' => 'suspended']);
    }

    public function test_permission_dependencies_and_client_role_versions_are_enforced(): void
    {
        $this->postJson('/api/v1/platform/roles', $this->command(['name' => 'Broken Role', 'permissions' => ['payments.review']]))->assertUnprocessable();
        $url = '/api/v1/platform/clients/'.$this->tenant->public_id.'/roles';
        $data = $this->command(['name' => 'Client observer', 'permissions' => ['products.view']]);
        $role = $this->postJson($url, $data)->assertOk()->json('public_id');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('public_id', $role);
        $this->putJson($url.'/'.$role, $this->command(['name' => 'Client observer updated', 'permissions' => ['products.view'], 'version' => 1]))->assertOk();
        $this->putJson($url.'/'.$role, $this->command(['name' => 'Stale client observer', 'permissions' => [], 'version' => 1]))->assertConflict();
        $this->deleteJson($url.'/'.$role, $this->command())->assertOk();
    }

    public function test_billing_identity_and_due_filters_use_issued_snapshots(): void
    {
        $this->putJson('/api/v1/platform/settings', $this->command(['name' => 'Original Billing Seller', 'email' => 'billing@example.test']))->assertOk();
        $id = $this->postJson('/api/v1/platform/invoices', $this->command(['client' => $this->tenant->public_id, 'plan_key' => 'standard', 'due_on' => now()->toDateString()]))->assertOk()->json('public_id');
        $this->getJson('/api/v1/platform/invoices?due=overdue')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/platform/invoices?due=next7')->assertOk()->assertJsonPath('total', 1);
        $this->putJson('/api/v1/platform/settings', $this->command(['name' => 'Changed Billing Seller']))->assertOk();
        $this->get('/api/v1/platform/invoices/'.$id.'/document')->assertOk()->assertSee('Original Billing Seller')->assertDontSee('Changed Billing Seller');
        $this->travel(2)->days();
        $this->getJson('/api/v1/platform/invoices?status=overdue&client='.$this->tenant->public_id)->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/platform/invoices?status=overdue&client='.$this->other->public_id)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/platform/overview')->assertOk()->assertJsonPath('overdue_invoices', 1)->assertJsonPath('collected_this_month', '0.00');
    }

    public function test_missing_stored_proof_cannot_activate_a_paid_plan(): void
    {
        $this->as($this->owner)->withHeader('X-Tenant-ID', $this->tenant->public_id);
        $id = $this->postJson('/api/v1/payments', ['plan_key' => 'standard', 'payment_method_id' => DB::table('payment_methods')->value('id')])->assertCreated()->json('public_id');
        $this->postJson('/api/v1/payments/'.$id.'/proof', ['proof' => UploadedFile::fake()->image('proof.png')])->assertOk();
        $this->postJson('/api/v1/payments/'.$id.'/submit', ['transaction_reference' => 'STORED-PROOF-123', 'amount' => '5000', 'paid_on' => now()->toDateString()])->assertOk();
        Storage::disk('local')->delete(DB::table('payment_proofs')->value('path'));
        $this->as($this->root)->getJson('/api/v1/platform/payments/'.$id.'/proof')->assertNotFound();
        $this->as($this->root)->postJson('/api/v1/platform/payments/'.$id.'/review', ['decision' => 'approved', 'password' => 'AdminPassword1234'])->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->assertDatabaseHas('subscription_payments', ['public_id' => $id, 'status' => 'pending_review', 'applied_at' => null]);
        $this->assertDatabaseHas('billing_invoices', ['status' => 'issued']);
        $this->assertSame('free', app(Entitlements::class)->plan($this->tenant->id)->key);
    }

    public function test_cross_client_private_images_need_an_explicit_platform_read_grant(): void
    {
        $product = $this->product($this->tenant);
        $this->as($this->owner)->withHeader('X-Tenant-ID', $this->tenant->public_id);
        $this->postJson('/api/v1/operations/products/'.$product.'/image', ['image' => UploadedFile::fake()->image('tea.jpg')])->assertOk();
        $image = DB::table('tenant_media')->where('kind', 'product')->value('public_id');
        $this->as($this->root)->get('/api/v1/platform/media/'.$image)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $staff = $this->staff(['clients.view']);
        $this->as($staff)->getJson('/api/v1/platform/media/'.$image)->assertForbidden();
        $this->as($this->owner)->getJson('/api/v1/platform/media/'.$image)->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.product_image_read', 'tenant_id' => $this->tenant->id]);
    }

    public function test_plan_and_payment_instruction_edits_require_password_and_keep_free_useful(): void
    {
        $plan = Plan::where('key', 'standard')->firstOrFail();
        $payload = $this->command(['name' => 'Standard Revised', 'description' => 'Updated catalog description', 'limits' => $plan->limits, 'amount' => '6000.00']);
        $this->putJson('/api/v1/platform/plans/standard', $payload)->assertOk();
        $this->assertDatabaseHas('plan_prices', ['plan_id' => $plan->id, 'amount' => '6000.00']);
        $this->putJson('/api/v1/platform/plans/free', $payload)->assertUnprocessable();
        $method = DB::table('payment_methods')->value('id');
        $details = $this->command(['name' => 'Updated Bank', 'active' => true, 'details' => ['account_number' => 'QA-ONLY-123', 'account_holder' => 'QA Seller']]);
        $this->putJson('/api/v1/platform/payment-methods/'.$method, array_replace($details, ['password' => 'wrong']))->assertUnprocessable();
        $this->putJson('/api/v1/platform/payment-methods/'.$method, $details)->assertOk();
        $this->getJson('/api/v1/platform/payment-methods')->assertOk()->assertJsonFragment(['account_number' => 'QA-ONLY-123']);
    }

    public function test_picker_searches_users_independently_and_scopes_inventory_locations(): void
    {
        $this->getJson('/api/v1/platform/lookup?user_q='.urlencode($this->owner->email).'&q=NoMatchingClient')
            ->assertOk()->assertJsonCount(1, 'users')->assertJsonPath('users.0.email', $this->owner->email)->assertJsonCount(0, 'clients');
        $r = $this->getJson('/api/v1/platform/lookup?client='.$this->tenant->public_id)->assertOk();
        $r->assertJsonCount(1, 'branches')->assertJsonCount(1, 'warehouses');
        $this->assertSame(DB::table('branches')->where('tenant_id', $this->tenant->id)->value('public_id'), $r->json('branches.0.public_id'));
        $this->as($this->staff(['clients.view']))->getJson('/api/v1/platform/lookup?user_q='.urlencode($this->owner->email))->assertOk()->assertJsonCount(0, 'users');
    }

    public function test_client_scoped_staff_get_client_picker_without_owner_contact(): void
    {
        $billing = $this->as($this->staff(['billing.view']))->getJson('/api/v1/platform/lookup')->assertOk();
        $this->assertNotEmpty($billing->json('clients'));
        $this->assertArrayNotHasKey('owner_email', $billing->json('clients.0'));
        $this->assertArrayHasKey('owner_email', $this->as($this->staff(['clients.view']))->getJson('/api/v1/platform/lookup')->assertOk()->json('clients.0'));
        $this->as($this->staff(['leads.view']))->getJson('/api/v1/platform/lookup')->assertOk()->assertJsonCount(0, 'clients');
    }
}
