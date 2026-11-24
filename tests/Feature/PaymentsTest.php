<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Entitlements;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create(['is_superadmin' => true, 'password' => 'AdminPassword123']);
        $this->tenant = app(TenantService::class)->create($this->owner, 'Store');
        $this->actingAs($this->owner)->withHeader('X-Tenant-ID', $this->tenant->public_id);
    }

    private function draft(): string
    {
        return $this->postJson('/api/v1/payments', ['plan_key' => 'standard', 'payment_method_id' => DB::table('payment_methods')->value('id')])->assertCreated()->json('public_id');
    }

    private function submit(string $id): void
    {
        $this->postJson("/api/v1/payments/$id/proof", ['proof' => UploadedFile::fake()->image('proof.png')])->assertOk();
        $this->postJson("/api/v1/payments/$id/submit", ['transaction_reference' => 'TX123456', 'paid_on' => now()->toDateString(), 'amount' => 5000])->assertOk()->assertJsonPath('status', 'pending_review');
    }

    public function test_pending_proof_does_not_activate_paid_entitlements_and_approval_applies_once(): void
    {
        $id = $this->draft();
        $this->submit($id);
        $this->assertEquals('free', app(Entitlements::class)->plan($this->tenant->id)->key);
        $this->postJson("/api/v1/platform/payments/$id/review", ['decision' => 'approved', 'password' => 'AdminPassword123'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'web')->postJson("/api/v1/platform/payments/$id/review", ['decision' => 'approved', 'password' => 'AdminPassword123'])->assertOk();
        $this->assertEquals('standard', app(Entitlements::class)->plan($this->tenant->id)->key);
        $ends = DB::table('subscriptions')->value('ends_at');
        $this->postJson("/api/v1/platform/payments/$id/review", ['decision' => 'approved', 'password' => 'AdminPassword123'])->assertUnprocessable();
        $this->assertEquals($ends, DB::table('subscriptions')->value('ends_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.approved', 'tenant_id' => $this->tenant->id]);
    }

    public function test_invalid_amount_missing_proof_and_invalid_file_are_rejected(): void
    {
        $id = $this->draft();
        $this->postJson("/api/v1/payments/$id/submit", ['transaction_reference' => 'TX12345', 'paid_on' => now()->toDateString(), 'amount' => 5000])->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->postJson("/api/v1/payments/$id/proof", ['proof' => UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml')])->assertUnprocessable();
        $this->postJson("/api/v1/payments/$id/proof", ['proof' => UploadedFile::fake()->image('p.jpg')])->assertOk();
        $this->postJson("/api/v1/payments/$id/submit", ['transaction_reference' => 'TX12345', 'paid_on' => now()->toDateString(), 'amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('amount');
        // Numeric-but-not-plain-decimal input must be a validation error, not a bcmath ValueError (500).
        $this->postJson("/api/v1/payments/$id/submit", ['transaction_reference' => 'TX12345', 'paid_on' => now()->toDateString(), 'amount' => '5e3'])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_mobile_upload_is_signed_scoped_expiring_and_one_time(): void
    {
        $id = $this->draft();
        $url = $this->postJson("/api/v1/payments/$id/upload-link")->assertOk()->json('url');
        $this->get($url)->assertOk();
        $this->post($url, ['proof' => UploadedFile::fake()->image('phone.png')])->assertOk()->assertSee('Screenshot received');
        $this->post($url, ['proof' => UploadedFile::fake()->image('phone.png')])->assertStatus(410);
        $this->get($url.'x')->assertForbidden();
        $url = $this->postJson("/api/v1/payments/$id/upload-link")->assertOk()->json('url');
        $this->travel(16)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_cross_tenant_payment_read_write_and_download_are_denied(): void
    {
        $id = $this->draft();
        $other = User::factory()->create();
        $t = app(TenantService::class)->create($other, 'Other');
        $this->actingAs($other)->withHeader('X-Tenant-ID', $t->public_id)->getJson('/api/v1/payments')->assertOk()->assertExactJson([]);
        $this->postJson("/api/v1/payments/$id/proof", ['proof' => UploadedFile::fake()->image('p.png')])->assertNotFound();
        $this->getJson("/api/v1/payments/$id/proof")->assertNotFound();
    }

    public function test_submitted_payment_waits_for_late_review_instead_of_expiring(): void
    {
        $id = $this->draft();
        $this->submit($id);
        $this->travel(10)->days();
        $this->artisan('techpos:expire-subscriptions')->assertSuccessful();
        $this->assertDatabaseHas('subscription_payments', ['public_id' => $id, 'status' => 'pending_review']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'web')->getJson('/api/v1/platform/overview')->assertOk()->assertJsonPath('overdue_invoices', 0);
        $this->actingAs($this->owner)->withHeader('X-Tenant-ID', $this->tenant->public_id);
        // Still counts as the one open request, so a duplicate cannot be started meanwhile.
        $this->postJson('/api/v1/payments', ['plan_key' => 'standard', 'payment_method_id' => DB::table('payment_methods')->value('id')])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'web')->postJson("/api/v1/platform/payments/$id/review", ['decision' => 'approved', 'password' => 'AdminPassword123'])->assertOk();
        $this->assertEquals('standard', app(Entitlements::class)->plan($this->tenant->id)->key);
    }

    public function test_unanswered_information_request_expires_and_releases_the_reference(): void
    {
        $id = $this->draft();
        $this->submit($id);
        $this->travel(10)->days();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'web')->postJson("/api/v1/platform/payments/$id/review", ['decision' => 'needs_information', 'note' => 'Please upload a clearer screenshot.', 'password' => 'AdminPassword123'])->assertOk();
        // The customer gets a fresh window to respond even though the original quote was older.
        $this->assertTrue(\App\Models\SubscriptionPayment::where('public_id', $id)->first()->expires_at->isFuture());
        $this->travel(8)->days();
        $this->artisan('techpos:expire-subscriptions')->assertSuccessful();
        $this->assertDatabaseHas('subscription_payments', ['public_id' => $id, 'status' => 'expired', 'reference_fingerprint' => null]);
        $this->assertDatabaseHas('billing_invoices', ['payment_id' => \App\Models\SubscriptionPayment::where('public_id', $id)->value('id'), 'status' => 'void']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner)->withHeader('X-Tenant-ID', $this->tenant->public_id);
        $this->submit($this->draft());
    }

    public function test_failed_proof_transaction_leaves_no_orphaned_file(): void
    {
        $id = $this->draft();
        // Force the audit insert after the file write to fail, rolling the upload transaction back.
        \Illuminate\Support\Facades\Schema::drop('audit_logs');
        $this->withoutExceptionHandling();
        try {
            $this->postJson("/api/v1/payments/$id/proof", ['proof' => UploadedFile::fake()->image('proof.png')]);
            $this->fail('The upload should have failed.');
        } catch (\Illuminate\Database\QueryException) {
        }
        $this->assertSame([], Storage::disk('local')->allFiles('private/payment-proofs'));
        $this->assertDatabaseCount('payment_proofs', 0);
    }

    public function test_image_memory_guard_rejects_oversized_decodes_before_gd_runs_out(): void
    {
        $original = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 32 * 1024 * 1024));
        try {
            \App\Support\ImageMemory::assertDecodable(200, 200, 'proof');
            $this->expectException(\Illuminate\Validation\ValidationException::class);
            \App\Support\ImageMemory::assertDecodable(4000, 4000, 'proof');
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    public function test_platform_payment_list_reports_whether_a_proof_exists(): void
    {
        $id = $this->draft();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'web')->getJson('/api/v1/platform/payments')->assertOk()->assertJsonPath('data.0.public_id', $id)->assertJsonPath('data.0.has_proof', 0);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner)->withHeader('X-Tenant-ID', $this->tenant->public_id)->postJson("/api/v1/payments/$id/proof", ['proof' => UploadedFile::fake()->image('proof.png')])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertTrue((bool) $this->actingAs($this->admin, 'web')->getJson('/api/v1/platform/payments')->assertOk()->json('data.0.has_proof'));
    }

    public function test_rejected_payment_voids_its_own_invoice(): void
    {
        $id = $this->draft();
        $this->submit($id);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'web')->postJson("/api/v1/platform/payments/$id/review", ['decision' => 'rejected', 'note' => 'Transfer not found.', 'password' => 'AdminPassword123'])->assertOk();
        $paymentId = \App\Models\SubscriptionPayment::where('public_id', $id)->value('id');
        $this->assertDatabaseHas('billing_invoices', ['payment_id' => $paymentId, 'status' => 'void', 'void_reason' => 'Linked payment rejected']);
        $this->travel(30)->days();
        $this->getJson('/api/v1/platform/overview')->assertOk()->assertJsonPath('overdue_invoices', 0);
    }

    public function test_expired_subscription_falls_back_to_free_and_keeps_records(): void
    {
        $id = $this->draft();
        $this->submit($id);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'web')->postJson("/api/v1/platform/payments/$id/review", ['decision' => 'approved', 'password' => 'AdminPassword123'])->assertOk();
        $this->travel(40)->days();
        $this->assertEquals('free', app(Entitlements::class)->plan($this->tenant->id)->key);
        $this->artisan('techpos:expire-subscriptions')->assertSuccessful();
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $this->tenant->id, 'status' => 'expired']);
        $this->assertDatabaseCount('branches', 1);
    }
}
