<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_recovery_is_private_and_reset_token_is_one_time(): void
    {
        Notification::fake();
        $u = User::factory()->create();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $u->email])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'absent@example.test'])->assertOk()->assertJsonPath('message', 'If this email has an account, a reset link has been sent.');
        $token = '';
        Notification::assertSentTo($u, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $data = ['email' => $u->email, 'token' => $token, 'password' => 'NewSecurePassword123', 'password_confirmation' => 'NewSecurePassword123'];
        $this->postJson('/api/v1/auth/reset-password', $data)->assertOk();
        $this->assertTrue(Hash::check($data['password'], $u->fresh()->password));
        $this->postJson('/api/v1/auth/reset-password', $data)->assertUnprocessable();
    }

    public function test_email_verification_requires_correct_user_hash_and_signature(): void
    {
        $u = User::factory()->unverified()->create();
        $this->actingAs($u, 'web');
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), ['id' => $u->id, 'hash' => sha1($u->email)]);
        $this->get($url.'tampered')->assertForbidden();
        $this->get($url)->assertRedirect();
        $this->assertNotNull($u->fresh()->email_verified_at);
    }

    public function test_login_rate_limit_blocks_repeated_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'locked@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'locked@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_unverified_user_cannot_initiate_payment(): void
    {
        $this->seed();
        $u = User::factory()->unverified()->create();
        $t = app(TenantService::class)->create($u, 'Store');
        $this->actingAs($u, 'web')->withHeader('X-Tenant-ID', $t->public_id)->postJson('/api/v1/payments', ['plan_key' => 'standard', 'payment_method_id' => 1])->assertForbidden();
    }
}
