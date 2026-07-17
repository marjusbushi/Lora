<?php

namespace Tests\Feature;

use App\Mail\TenantUserInvitationMail;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantUserInvitation;
use App\Models\User;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TenantUserInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $otherTenant;

    private User $admin;

    private User $target;

    private User $wrongUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($this->tenant);
        TenantDomain::query()->create([
            'tenant_id' => $this->tenant->id,
            'domain' => 'hotel-a.test',
            'is_primary' => false,
        ]);

        app(TenantContext::class)->set($this->tenant);
        $this->admin = User::factory()->create([
            'email' => 'admin-a@example.test',
            'current_tenant_id' => $this->tenant->id,
        ]);
        $this->admin->assignRole('admin');

        $this->otherTenant = Tenant::factory()->create(['name' => 'Hotel B']);
        app(TenantRoleService::class)->provision($this->otherTenant);
        app(TenantContext::class)->set($this->otherTenant);
        $this->target = User::factory()->create([
            'email' => 'existing@example.test',
            'password' => 'password',
            'current_tenant_id' => $this->otherTenant->id,
        ]);
        $this->target->assignRole('manager');
        $this->wrongUser = User::factory()->create([
            'email' => 'wrong@example.test',
            'current_tenant_id' => $this->otherTenant->id,
        ]);

        app(TenantContext::class)->clear();
        Mail::fake();
    }

    public function test_existing_account_is_not_linked_or_given_a_role_before_acceptance(): void
    {
        $passwordBefore = $this->target->password;

        $existingResponse = $this->inviteExisting();
        $existingMessage = $existingResponse->getSession()->get('success');

        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->target->id,
        ]);
        $this->assertDatabaseMissing('model_has_roles', [
            'team_id' => $this->tenant->id,
            'model_id' => $this->target->id,
            'model_type' => User::class,
        ]);
        $this->assertDatabaseHas('tenant_user_invitations', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->target->id,
            'email' => $this->target->email,
            'accepted_at' => null,
        ]);
        $this->assertSame($passwordBefore, $this->target->fresh()->password);

        $invitationUrl = $this->mailedInvitationUrl();
        $this->assertStringStartsWith('https://hotel-a.test/tenant-invitations/', $invitationUrl);
        $this->assertTrue(Request::create($invitationUrl)->hasValidRelativeSignature());

        $newResponse = $this->actingAs($this->admin)->post('https://hotel-a.test/pms/users', [
            'name' => 'Brand New',
            'email' => 'brand-new@example.test',
            'password' => 'initial-password',
            'role' => 'receptionist',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($existingMessage, $newResponse->getSession()->get('success'));
        $newUser = User::withoutGlobalScopes()->where('email', 'brand-new@example.test')->firstOrFail();
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $newUser->id,
            'is_active' => true,
        ]);
    }

    public function test_wrong_authenticated_user_cannot_accept_the_invitation(): void
    {
        $this->inviteExisting();
        $invitation = $this->invitation();

        $this->actingAs($this->wrongUser)
            ->post($this->signedUrl('tenant-invitations.accept', $invitation))
            ->assertForbidden();

        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->target->id,
        ]);
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_reissuing_for_another_role_rotates_the_grant_and_invalidates_the_old_links(): void
    {
        $this->inviteExisting('receptionist');
        $oldInvitation = $this->invitation();
        $oldShowUrl = $this->mailedInvitationUrl();
        $oldAcceptUrl = $this->signedUrl('tenant-invitations.accept', $oldInvitation);

        Mail::fake();
        $this->inviteExisting('manager');
        $newInvitation = $this->invitation();

        $this->assertNotSame($oldInvitation->id, $newInvitation->id);
        $this->assertSame(1, TenantUserInvitation::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('email', $this->target->email)
            ->count());

        $this->actingAs($this->target)->get($oldShowUrl)->assertForbidden();
        $this->post($oldAcceptUrl)->assertForbidden();
        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->target->id,
        ]);
        $this->assertNull($newInvitation->fresh()->accepted_at);

        $this->post($this->signedUrl('tenant-invitations.accept', $newInvitation))
            ->assertRedirect();

        $this->assertTrue(app(TenantContext::class)->run(
            $this->tenant,
            fn () => $this->target->unsetRelation('roles')->hasRole('manager'),
        ));
        $this->assertFalse(app(TenantContext::class)->run(
            $this->tenant,
            fn () => $this->target->unsetRelation('roles')->hasRole('receptionist'),
        ));
    }

    public function test_failed_reissue_preserves_the_prior_invitation_and_link(): void
    {
        $this->inviteExisting('receptionist');
        $oldInvitation = $this->invitation()->fresh();
        $oldShowUrl = $this->mailedInvitationUrl();
        $oldAcceptUrl = $this->signedUrl('tenant-invitations.accept', $oldInvitation);

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

        $this->actingAs($this->admin)
            ->post('https://hotel-a.test/pms/users', [
                'name' => 'Ignored Existing Name',
                'email' => $this->target->email,
                'password' => 'ignored-password',
                'role' => 'manager',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('success');

        $currentInvitation = $this->invitation()->fresh();
        $this->assertSame($oldInvitation->id, $currentInvitation->id);
        $this->assertSame($oldInvitation->role_id, $currentInvitation->role_id);
        $this->assertTrue($oldInvitation->expires_at->equalTo($currentInvitation->expires_at));
        $this->assertSame(1, TenantUserInvitation::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('email', $this->target->email)
            ->count());

        $this->actingAs($this->target)->get($oldShowUrl)->assertOk();
        $this->post($oldAcceptUrl)->assertRedirect();

        $this->assertTrue(app(TenantContext::class)->run(
            $this->tenant,
            fn () => $this->target->unsetRelation('roles')->hasRole('receptionist'),
        ));
        $this->assertFalse(app(TenantContext::class)->run(
            $this->tenant,
            fn () => $this->target->unsetRelation('roles')->hasRole('manager'),
        ));
    }

    public function test_failed_initial_delivery_does_not_leave_an_unusable_invitation(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

        $this->actingAs($this->admin)
            ->post('https://hotel-a.test/pms/users', [
                'name' => 'Ignored Existing Name',
                'email' => $this->target->email,
                'password' => 'ignored-password',
                'role' => 'receptionist',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('success');

        $this->assertDatabaseMissing('tenant_user_invitations', [
            'tenant_id' => $this->tenant->id,
            'email' => $this->target->email,
        ]);
    }

    public function test_expired_and_tampered_invitation_urls_are_rejected(): void
    {
        $this->inviteExisting();
        $invitation = $this->invitation();
        $validUrl = $this->signedUrl('tenant-invitations.show', $invitation);

        $this->post('https://hotel-a.test/logout')->assertRedirect();
        $this->get($validUrl.'&tampered=1')
            ->assertForbidden()
            ->assertSessionMissing('url.intended');

        $invitation->update(['expires_at' => now()->subMinute()]);
        $this->get($this->signedUrl('tenant-invitations.show', $invitation->fresh()))
            ->assertForbidden();

        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->target->id,
        ]);
    }

    public function test_acceptance_is_replay_safe_and_does_not_overwrite_a_later_role_change(): void
    {
        $this->inviteExisting();
        $invitation = $this->invitation();
        $acceptUrl = $this->signedUrl('tenant-invitations.accept', $invitation);

        $this->actingAs($this->target)->post($acceptUrl)->assertRedirect();
        $acceptedAt = $invitation->fresh()->accepted_at;

        app(TenantContext::class)->run($this->tenant, function () {
            $this->target->unsetRelation('roles')->syncRoles(['manager']);
        });

        $this->post($acceptUrl)->assertRedirect();

        $this->assertSame(1, DB::table('tenant_user')
            ->where('tenant_id', $this->tenant->id)
            ->where('user_id', $this->target->id)
            ->count());
        $this->assertTrue(app(TenantContext::class)->run(
            $this->tenant,
            fn () => $this->target->unsetRelation('roles')->hasRole('manager'),
        ));
        $this->assertTrue($acceptedAt->equalTo($invitation->fresh()->accepted_at));
    }

    public function test_guest_can_follow_the_signed_link_login_and_accept_the_invitation(): void
    {
        $this->inviteExisting();
        $invitation = $this->invitation();
        $invitationUrl = $this->mailedInvitationUrl();

        $this->post('https://hotel-a.test/logout')->assertRedirect();

        $this->get($invitationUrl)
            ->assertRedirect('https://hotel-a.test/login')
            ->assertSessionHas('url.intended', $invitationUrl);

        $this->post('https://hotel-a.test/login', [
            'email' => $this->target->email,
            'password' => 'password',
        ])->assertRedirect($invitationUrl);
        $this->assertAuthenticatedAs($this->target);

        // Force the next request to rebuild the guard from the persisted
        // session, matching a real PHP-FPM/worker request boundary.
        app('auth')->forgetGuards();

        $this->get($invitationUrl)
            ->assertOk()
            ->assertSee('Prano ftesën');

        $this->post($this->signedUrl('tenant-invitations.accept', $invitation->fresh()))
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->target->id,
            'is_active' => true,
        ]);
        $accepted = $invitation->fresh();
        $this->assertSame($this->target->id, $accepted->accepted_by);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertTrue(app(TenantContext::class)->run(
            $this->tenant,
            fn () => $this->target->unsetRelation('roles')->hasRole('receptionist'),
        ));
        $this->assertTrue(app(TenantContext::class)->run(
            $this->otherTenant,
            fn () => $this->target->unsetRelation('roles')->hasRole('manager'),
        ));
    }

    public function test_soft_deleted_account_is_restored_only_after_signed_link_and_credentials(): void
    {
        DB::table('tenant_user')
            ->where('tenant_id', $this->otherTenant->id)
            ->where('user_id', $this->target->id)
            ->update(['is_active' => false]);
        $this->target->delete();

        $this->inviteExisting();
        $invitationUrl = $this->mailedInvitationUrl();

        $this->post('https://hotel-a.test/logout')->assertRedirect();
        $this->get($invitationUrl)->assertRedirect('https://hotel-a.test/login');

        $this->assertNotNull(User::withoutGlobalScopes()->findOrFail($this->target->id)->deleted_at);
        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->target->id,
        ]);

        $this->post('https://hotel-a.test/login', [
            'email' => $this->target->email,
            'password' => 'password',
        ])->assertRedirect($invitationUrl);

        $this->assertNull(User::withoutGlobalScopes()->findOrFail($this->target->id)->deleted_at);
        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->target->id,
        ]);

        app('auth')->forgetGuards();

        $this->post($this->signedUrl('tenant-invitations.accept', $this->invitation()))
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->target->id,
            'is_active' => true,
        ]);
    }

    private function inviteExisting(string $role = 'receptionist')
    {
        return $this->actingAs($this->admin)
            ->post('https://hotel-a.test/pms/users', [
                'name' => 'Ignored Existing Name',
                'email' => $this->target->email,
                'password' => 'ignored-password',
                'role' => $role,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    private function invitation(): TenantUserInvitation
    {
        return TenantUserInvitation::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('user_id', $this->target->id)
            ->firstOrFail();
    }

    private function mailedInvitationUrl(): string
    {
        $url = null;

        Mail::assertSent(TenantUserInvitationMail::class, function (TenantUserInvitationMail $mail) use (&$url) {
            $url = $mail->invitationUrl;

            return $mail->hasTo($this->target->email);
        });

        return $url;
    }

    private function signedUrl(string $route, TenantUserInvitation $invitation): string
    {
        $relative = URL::temporarySignedRoute(
            $route,
            $invitation->expires_at,
            ['invitation' => $invitation],
            absolute: false,
        );

        return 'https://hotel-a.test'.$relative;
    }
}
