<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    public function test_pending_challenge_is_invalid_after_password_change_and_attempt_limit(): void
    {
        [$secret,$code,$codes] = $this->enable();
        $this->logout();
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'SecurePassword123'])->assertOk();
        $this->user->forceFill(['password' => 'DifferentPassword123'])->save();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $codes[0]])->assertUnprocessable();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'DifferentPassword123'])->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => 'invalid'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $codes[0]])->assertUnprocessable();
    }

    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['password' => 'SecurePassword123']);
        $this->actingAs($this->user, 'web');
    }

    private function enable(): array
    {
        $secret = $this->postJson('/api/v1/account/two-factor/setup', ['password' => 'SecurePassword123'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('secret');
        $this->assertNotEquals($secret, DB::table('users')->where('id', $this->user->id)->value('two_factor_secret'));
        $code = app(TwoFactor::class)->totp()->getCurrentOtp($secret);
        $codes = $this->postJson('/api/v1/account/two-factor/confirm', ['code' => $code])->assertOk()->json('recovery_codes');

        return [$secret, $code, $codes];
    }

    private function logout(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
    }

    public function test_confirmation_encrypts_secrets_and_password_alone_does_not_authenticate(): void
    {
        [$secret,$code,$codes] = $this->enable();
        $this->assertCount(10, $codes);
        $this->getJson('/api/v1/auth/me')->assertDontSee($secret)->assertDontSee($codes[0]);
        $this->logout();
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'SecurePassword123', 'remember' => true])->assertOk()->assertJsonPath('two_factor_required', true);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code])->assertUnprocessable();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $codes[0]])->assertOk()->assertJsonPath('user.email', $this->user->email);
        $this->getJson('/api/v1/account/two-factor')->assertOk()->assertJsonPath('remaining_recovery_codes', 9);
        $this->logout();
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'SecurePassword123'])->assertOk();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $codes[0]])->assertUnprocessable();
    }

    public function test_next_totp_can_sign_in_and_challenge_expires(): void
    {
        [$secret] = $this->enable();
        $this->logout();
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'SecurePassword123'])->assertOk();
        $this->travel(31)->seconds();
        $code = app(TwoFactor::class)->totp()->oathTotp($secret, (int) floor(now()->timestamp / 30));
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code])->assertOk();
        $this->logout();
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'SecurePassword123'])->assertOk();
        $this->travel(6)->minutes();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code])->assertUnprocessable();
    }

    public function test_disable_requires_password_and_unused_second_factor(): void
    {
        [$secret,$code,$codes] = $this->enable();
        $this->postJson('/api/v1/account/two-factor/disable', ['password' => 'wrong', 'code' => $codes[0]])->assertUnprocessable();
        $this->postJson('/api/v1/account/two-factor/disable', ['password' => 'SecurePassword123', 'code' => 'invalid'])->assertUnprocessable();
        $this->postJson('/api/v1/account/two-factor/disable', ['password' => 'SecurePassword123', 'code' => $codes[0]])->assertOk();
        $this->assertNull($this->user->fresh()->two_factor_secret);
        $this->getJson('/api/v1/account/two-factor')->assertJsonPath('enabled', false);
    }

    public function test_recovery_regeneration_invalidates_old_codes_and_setup_expires(): void
    {
        [$secret,$code,$codes] = $this->enable();
        $new = $this->postJson('/api/v1/account/two-factor/recovery-codes', ['password' => 'SecurePassword123', 'code' => $codes[0]])->assertOk()->json('recovery_codes');
        $this->postJson('/api/v1/account/two-factor/disable', ['password' => 'SecurePassword123', 'code' => $codes[1]])->assertUnprocessable();
        $this->postJson('/api/v1/account/two-factor/disable', ['password' => 'SecurePassword123', 'code' => $new[0]])->assertOk();
        $secret = $this->postJson('/api/v1/account/two-factor/setup', ['password' => 'SecurePassword123'])->assertOk()->json('secret');
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/account/two-factor/confirm', ['code' => app(TwoFactor::class)->totp()->getCurrentOtp($secret)])->assertUnprocessable();
    }
}
