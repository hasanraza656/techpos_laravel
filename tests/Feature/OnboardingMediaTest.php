<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnboardingMediaTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->tenant = app(TenantService::class)->create($this->owner, 'Setup Shop');
        $this->actingAs($this->owner)->withHeader('X-Tenant-ID', $this->tenant->public_id);
    }

    private function product(): string
    {
        return $this->postJson('/api/v1/operations/products', ['name' => 'Test Tea', 'sku' => 'PHOTO-TEA', 'type' => 'standard', 'unit' => 'piece',
            'cost_price' => '50', 'selling_price' => '100', 'tax_rate' => '0', 'tax_inclusive' => false, 'reorder_level' => '2', 'active' => true, 'barcodes' => []])->assertCreated()->json('public_id');
    }

    public function test_setup_revisions_reject_stale_writes_without_overwriting_answers(): void
    {
        $this->getJson('/api/v1/onboarding')->assertOk()->assertJsonPath('revision', 1);
        $this->putJson('/api/v1/onboarding', ['step' => 2, 'revision' => 1, 'industry_key' => 'pharmacy'])->assertOk()->assertJsonPath('revision', 2);
        $this->putJson('/api/v1/onboarding', ['step' => 1, 'revision' => 1, 'industry_key' => 'restaurant'])->assertConflict();
        $this->assertDatabaseHas('onboarding_progress', ['tenant_id' => $this->tenant->id, 'step' => 2, 'revision' => 2]);
        $saved = $this->getJson('/api/v1/onboarding')->assertOk()->json();
        $this->assertSame('pharmacy', json_decode($saved['data'], true)['industry_key']);
        $this->putJson('/api/v1/onboarding', ['step' => 5, 'revision' => 2, 'completed' => true])->assertOk()->assertJsonPath('revision', 3);
        $completed = DB::table('onboarding_progress')->where('tenant_id', $this->tenant->id)->value('completed_at');
        $this->travel(1)->hours();
        $this->putJson('/api/v1/onboarding', ['step' => 5, 'revision' => 3, 'completed' => true])->assertOk();
        $this->assertSame($completed, DB::table('onboarding_progress')->where('tenant_id', $this->tenant->id)->value('completed_at'));
    }

    public function test_finish_later_saves_optional_answers_without_requiring_industry_or_completion(): void
    {
        $this->putJson('/api/v1/onboarding', ['step' => 1, 'revision' => 1, 'deferred' => true, 'business_model' => null, 'operation' => null])->assertOk();
        $business = $this->getJson('/api/v1/business')->assertOk();
        $this->assertNotNull($business->json('onboarding.deferred_at'));
        $this->assertNull($business->json('onboarding.completed_at'));
        $this->putJson('/api/v1/onboarding', ['step' => 5, 'completed' => true])->assertUnprocessable();
    }

    public function test_product_photo_upload_is_reencoded_private_and_returned_in_catalog_without_storage_paths(): void
    {
        $product = $this->product();
        $upload = $this->post('/api/v1/operations/products/'.$product.'/image', ['image' => UploadedFile::fake()->image('tea.png', 1600, 800)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('width', 1200)->assertJsonPath('height', 600)->json();
        $row = DB::table('tenant_media')->where('public_id', $upload['public_id'])->first();
        Storage::disk('local')->assertExists($row->path);
        $this->assertSame('image/webp', getimagesize(Storage::disk('local')->path($row->path))['mime']);
        $this->assertArrayNotHasKey('path', $upload);
        $this->get($upload['url'])->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/v1/operations/products/'.$product)->assertOk()->assertJsonPath('image.public_id', $upload['public_id']);
        $branch = DB::table('branches')->where('tenant_id', $this->tenant->id)->value('public_id');
        $warehouse = DB::table('warehouses')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->getJson('/api/v1/operations/products?'.http_build_query(['branch' => $branch, 'warehouse' => $warehouse]))->assertOk()->assertJsonPath('data.0.image.public_id', $upload['public_id']);
    }

    public function test_media_rejects_cross_tenant_and_ungranted_platform_access(): void
    {
        $product = $this->product();
        $url = $this->post('/api/v1/operations/products/'.$product.'/image', ['image' => UploadedFile::fake()->image('tea.jpg')], ['Accept' => 'application/json'])->assertOk()->json('url');
        $other = User::factory()->create(['is_superadmin' => true]);
        $tenant = app(TenantService::class)->create($other, 'Other shop');
        $this->actingAs($other)->withHeader('X-Tenant-ID', $tenant->public_id)->get($url, ['Accept' => 'application/json'])->assertNotFound();
        $this->post('/api/v1/operations/products/'.$product.'/image', ['image' => UploadedFile::fake()->image('bad.jpg')], ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson('/api/v1/operations/products/'.$product.'/image')->assertNotFound();
        $this->assertDatabaseCount('tenant_media', 1);
    }

    public function test_member_without_catalog_grant_cannot_read_or_replace_product_photo(): void
    {
        $product = $this->product();
        $url = $this->post('/api/v1/operations/products/'.$product.'/image', ['image' => UploadedFile::fake()->image('tea.jpg')], ['Accept' => 'application/json'])->assertOk()->json('url');
        $staff = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id, 'is_owner' => false]);
        $this->actingAs($staff)->get($url, ['Accept' => 'application/json'])->assertForbidden();
        $this->post('/api/v1/operations/products/'.$product.'/image', ['image' => UploadedFile::fake()->image('tea.jpg')], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson('/api/v1/operations/products/'.$product.'/image')->assertForbidden();
        $this->getJson('/api/v1/business/setup-checklist')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_media_rejects_svg_executables_oversize_and_excessive_dimensions(): void
    {
        $product = $this->product();
        foreach ([UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "bad";'), UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'), UploadedFile::fake()->image('huge.png')->size(4097), UploadedFile::fake()->image('wide.jpg', 6001, 1)] as $file) {
            $this->post('/api/v1/operations/products/'.$product.'/image', ['image' => $file], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('tenant_media', 0);
    }

    public function test_branch_catalog_grant_allows_media_and_revocation_is_immediate(): void
    {
        $product = $this->product();
        $url = $this->post('/api/v1/operations/products/'.$product.'/image', ['image' => UploadedFile::fake()->image('tea.jpg')], ['Accept' => 'application/json'])->assertOk()->json('url');
        $staff = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id]);
        $role = $this->postJson('/api/v1/roles', ['name' => 'Branch photo reader', 'permissions' => ['products.view']])->assertOk()->json('public_id');
        $branch = DB::table('branches')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->postJson('/api/v1/role-assignments', ['user' => $staff->public_id, 'role' => $role, 'branch' => $branch])->assertOk();
        $this->actingAs($staff)->get($url)->assertOk();
        DB::table('role_permissions')->where('role_id', DB::table('roles')->where('public_id', $role)->value('id'))->delete();
        $this->get($url, ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_business_logo_replacement_and_removal_are_tenant_scoped(): void
    {
        $first = $this->post('/api/v1/business/logo', ['image' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])->assertOk()->json();
        $second = $this->post('/api/v1/business/logo', ['image' => UploadedFile::fake()->image('new-logo.png')], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertNotEquals($first['public_id'], $second['public_id']);
        $this->assertDatabaseCount('tenant_media', 1);
        $this->get($first['url'], ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson('/api/v1/business')->assertOk()->assertJsonPath('logo.public_id', $second['public_id']);
        $this->deleteJson('/api/v1/business/logo')->assertNoContent();
        $this->assertDatabaseCount('tenant_media', 0);
    }

    public function test_tax_setup_is_validated_and_does_not_rewrite_existing_product_or_other_tenant(): void
    {
        $product = $this->product();
        $this->getJson('/api/v1/business/setup-checklist')->assertOk()->assertJsonPath('items.0.done', true)->assertJsonPath('items.2.done', false);
        $this->putJson('/api/v1/settings/tax', ['name' => 'Sales tax', 'default_rate' => '18.0000', 'tax_inclusive' => true, 'registration_number' => 'TEST-123'])->assertOk()->assertJsonPath('tax_profile.default_rate', '18.0000');
        $this->getJson('/api/v1/business/setup-checklist')->assertOk()->assertJsonPath('items.2.done', true);
        $this->getJson('/api/v1/operations/products/'.$product)->assertOk()->assertJsonPath('tax_rate', '0.0000');
        $this->putJson('/api/v1/settings/tax', ['name' => 'Tax', 'default_rate' => '101', 'tax_inclusive' => false])->assertUnprocessable();
        $other = User::factory()->create();
        $tenant = app(TenantService::class)->create($other, 'Other tax shop');
        $this->actingAs($other)->withHeader('X-Tenant-ID', $tenant->public_id)->getJson('/api/v1/settings/tax')->assertOk()->assertJsonPath('tax_profile.default_rate', '0.0000');
        $this->assertDatabaseHas('products', ['public_id' => $product, 'tax_rate' => 0]);
    }

    public function test_currency_change_is_blocked_after_stock_history_without_relabeling_costs(): void
    {
        $this->putJson('/api/v1/onboarding', ['step' => 3, 'currency' => 'USD'])->assertOk();
        $this->putJson('/api/v1/onboarding', ['step' => 3, 'currency' => 'PKR'])->assertOk();
        $product = $this->product();
        $branch = DB::table('branches')->where('tenant_id', $this->tenant->id)->value('public_id');
        $warehouse = DB::table('warehouses')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->postJson('/api/v1/operations/inventory/adjustments', ['branch' => $branch, 'warehouse' => $warehouse, 'product' => $product, 'quantity' => '10', 'unit_cost' => '50', 'type' => 'opening_stock', 'reason' => 'Counted test stock', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->putJson('/api/v1/onboarding', ['step' => 3, 'currency' => 'USD'])->assertUnprocessable();
        $this->getJson('/api/v1/business')->assertOk()->assertJsonPath('tenant.currency', 'PKR')->assertJsonPath('currency_locked', true);
        $this->assertDatabaseHas('stock_balances', ['tenant_id' => $this->tenant->id, 'cost_value' => 500]);
    }

    public function test_currency_is_fixed_after_the_first_register_shift_even_without_sales(): void
    {
        $branch = DB::table('branches')->where('tenant_id', $this->tenant->id)->value('public_id');
        $register = DB::table('registers')->where('tenant_id', $this->tenant->id)->value('public_id');
        $this->postJson('/api/v1/operations/shifts', ['branch' => $branch, 'register' => $register, 'opening_float' => '500', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->putJson('/api/v1/onboarding', ['step' => 3, 'currency' => 'USD'])->assertUnprocessable();
        $this->assertDatabaseHas('tenants', ['id' => $this->tenant->id, 'currency' => 'PKR']);
    }
}
