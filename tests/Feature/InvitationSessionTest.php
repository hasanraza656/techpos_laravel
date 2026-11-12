<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\BusinessInvitation;
use App\Notifications\NewSignIn;
use App\Services\AccountSecurity;
use App\Services\InvitationService;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationSessionTest extends TestCase
{
    public function test_revocation_marker_blocks_a_session_reinserted_by_an_inflight_request(): void
    {
        $id = $this->app['session']->driver()->getId();
        DB::table('revoked_sessions')->insert(['user_id' => $this->owner->id, 'session_hash' => AccountSecurity::sessionHash($id), 'expires_at' => now()->addHour(), 'created_at' => now()]);
        $this->withCredentials()->withCookie(config('session.cookie'), $id)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    use RefreshDatabase;

    private User $owner;

    private $tenant;

    private string $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
        $this->owner = User::factory()->create(['password' => 'OwnerPassword123']);
        $this->tenant = app(TenantService::class)->create($this->owner, 'Inviting store');
        $this->role = DB::table('roles')->where('tenant_id', $this->tenant->id)->where('name', 'Cashier')->value('public_id');
        $this->actingAs($this->owner, 'web')->withHeader('X-Tenant-ID', $this->tenant->public_id);
    }

    private function invite(string $email = 'worker@example.test'): array
    {
        $response = $this->postJson('/api/v1/invitations', ['email' => $email, 'role' => $this->role])->assertCreated();
        $token = '';
        Notification::assertSentOnDemand(BusinessInvitation::class, function ($n, $channels, $notifiable) use (&$token, $email) {
            if ($notifiable->routes['mail'] !== $email) {
                return false;
            }$token = $n->token;

            return true;
        });

        return [$response->json('public_id'), $token];
    }

    public function test_invitation_reserves_capacity_and_acceptance_is_email_bound_and_one_time(): void
    {
        [$id,$token] = $this->invite();
        $this->postJson('/api/v1/invitations', ['email' => 'second@example.test', 'role' => $this->role])->assertUnprocessable();
        $wrong = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->actingAs($wrong, 'web')->postJson("/api/v1/invitations/$token/accept")->assertForbidden();
        $worker = User::factory()->create(['email' => 'worker@example.test']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($worker, 'web')->postJson("/api/v1/invitations/$token/accept")->assertOk()->assertJsonPath('tenant', $this->tenant->public_id);
        $this->postJson("/api/v1/invitations/$token/accept")->assertStatus(410);
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $this->tenant->id, 'user_id' => $worker->id, 'is_owner' => false]);
        $this->getJson('/api/v1/business')->assertOk()->assertJsonFragment(['sales.create']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invitation.accepted', 'subject' => $id]);
    }

    public function test_invited_registration_creates_no_extra_business_and_ignores_privilege_input(): void
    {
        [$id,$token] = $this->invite();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/invitations/$token/register", ['name' => 'Invited person', 'password' => 'NewWorkerPassword123', 'password_confirmation' => 'NewWorkerPassword123', 'email' => 'attacker@example.test', 'is_superadmin' => true])->assertCreated()->assertJsonPath('user.email', 'worker@example.test')->assertJsonPath('user.is_superadmin', false);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseHas('tenant_invitations', ['public_id' => $id]);
        $this->assertNotNull(User::where('email', 'worker@example.test')->first()->email_verified_at);
    }

    public function test_revoked_expired_and_cross_tenant_invitations_do_not_grant_access(): void
    {
        [$id,$token] = $this->invite();
        $this->postJson("/api/v1/invitations/$id/revoke")->assertOk();
        $this->getJson("/api/v1/invitations/$token")->assertStatus(410);
        [$id,$token] = $this->invite('another@example.test');
        $this->travel(73)->hours();
        $this->getJson("/api/v1/invitations/$token")->assertStatus(410);
        $this->travelBack();
        $other = app(TenantService::class)->create(User::factory()->create(), 'Other store');
        $foreignRole = DB::table('roles')->where('tenant_id', $other->id)->value('public_id');
        $this->postJson('/api/v1/invitations', ['email' => 'x@example.test', 'role' => $foreignRole])->assertNotFound();
    }

    public function test_owner_cannot_be_removed_and_member_removal_preserves_other_businesses(): void
    {
        [$id,$token] = $this->invite();
        $worker = User::factory()->create(['email' => 'worker@example.test']);
        $other = app(TenantService::class)->create($worker, 'Worker business');
        app(InvitationService::class)->accept($token, $worker);
        $this->postJson('/api/v1/members/'.$this->owner->public_id.'/remove', ['password' => 'OwnerPassword123'])->assertUnprocessable();
        $this->postJson('/api/v1/members/'.$worker->public_id.'/remove', ['password' => 'OwnerPassword123'])->assertOk();
        $this->assertDatabaseMissing('tenant_users', ['tenant_id' => $this->tenant->id, 'user_id' => $worker->id]);
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $other->id, 'user_id' => $worker->id]);
    }

    public function test_session_management_never_exposes_cookie_ids_or_revokes_other_users(): void
    {
        $other = User::factory()->create();
        foreach ([['own-session', $this->owner->id], ['foreign-session', $other->id]] as [$id,$user]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user, 'payload' => '', 'last_activity' => now()->timestamp]);
        }
        $this->getJson('/api/v1/account/sessions')->assertOk()->assertJsonFragment(['public_id' => AccountSecurity::sessionHash('own-session')])->assertDontSee('own-session')->assertDontSee('foreign-session');
        $this->postJson('/api/v1/account/sessions/'.AccountSecurity::sessionHash('foreign-session').'/revoke', ['password' => 'OwnerPassword123'])->assertNotFound();
        $this->postJson('/api/v1/account/sessions/'.AccountSecurity::sessionHash('own-session').'/revoke', ['password' => 'incorrect'])->assertUnprocessable();
        $this->postJson('/api/v1/account/sessions/'.AccountSecurity::sessionHash('own-session').'/revoke', ['password' => 'OwnerPassword123'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'own-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-session']);
    }

    public function test_new_signin_combination_is_detected_and_notification_is_queued(): void
    {
        $s = app(AccountSecurity::class);
        $a = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'Browser A']);
        $s->recordLogin($this->owner, $a);
        $s->recordLogin($this->owner, $a);
        Notification::assertNothingSent();
        $b = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.2', 'HTTP_USER_AGENT' => 'Browser B']);
        $s->recordLogin($this->owner, $b);
        Notification::assertSentTo($this->owner, NewSignIn::class);
        $this->assertDatabaseHas('login_events', ['user_id' => $this->owner->id, 'suspicious' => true]);
    }
}
