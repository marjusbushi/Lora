<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_user_can_have_a_different_role_in_each_hotel(): void
    {
        [$first, $firstAdmin] = $this->firstTenantWithAdmin();
        $second = $this->newProvisionedTenant();

        $context = app(TenantContext::class);
        $context->set($first);
        $shared = User::factory()->create();
        $shared->assignRole('receptionist');
        $shared->tenants()->syncWithoutDetaching([
            $second->id => ['is_owner' => false, 'is_active' => true],
        ]);

        $context->set($second);
        $shared->unsetRelation('roles')->assignRole('manager');
        $this->assertTrue($shared->unsetRelation('roles')->hasRole('manager'));
        $this->assertFalse($shared->unsetRelation('roles')->hasRole('receptionist'));

        $context->set($first);
        $this->assertTrue($shared->unsetRelation('roles')->hasRole('receptionist'));
        $this->assertFalse($shared->unsetRelation('roles')->hasRole('manager'));
        $this->assertTrue($firstAdmin->unsetRelation('roles')->hasRole('admin'));

        $context->clear();
        $this->actingAs($firstAdmin)
            ->put(route('users.update', $shared), [
                'name' => $shared->name,
                'email' => $shared->email,
                'password' => '',
                'role' => 'housekeeping',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $context->set($first);
        $this->assertTrue($shared->unsetRelation('roles')->hasRole('housekeeping'));
        $context->set($second);
        $this->assertTrue($shared->unsetRelation('roles')->hasRole('manager'));
    }

    public function test_existing_email_requires_acceptance_without_resetting_its_password_or_other_hotel_role(): void
    {
        [$first, $admin] = $this->firstTenantWithAdmin();
        $second = $this->newProvisionedTenant();
        $context = app(TenantContext::class);

        $context->set($second);
        $existing = User::factory()->create(['email' => 'shared@example.test']);
        $existing->assignRole('manager');
        $passwordBefore = $existing->password;

        $context->clear();
        $this->actingAs($admin)
            ->post(route('users.store'), [
                'name' => 'Shared Account',
                'email' => 'shared@example.test',
                'password' => 'ignored-password',
                'role' => 'receptionist',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($passwordBefore, User::withoutGlobalScopes()->findOrFail($existing->id)->password);
        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $first->id,
            'user_id' => $existing->id,
        ]);
        $this->assertDatabaseHas('tenant_user_invitations', [
            'tenant_id' => $first->id,
            'user_id' => $existing->id,
            'email' => $existing->email,
            'accepted_at' => null,
        ]);

        $context->set($second);
        $this->assertTrue($existing->unsetRelation('roles')->hasRole('manager'));
    }

    public function test_deactivation_removes_access_only_from_the_current_hotel(): void
    {
        [$first, $admin] = $this->firstTenantWithAdmin();
        $second = $this->newProvisionedTenant();
        $context = app(TenantContext::class);

        $context->set($first);
        $shared = User::factory()->create();
        $shared->assignRole('receptionist');
        $shared->tenants()->syncWithoutDetaching([
            $second->id => ['is_owner' => false, 'is_active' => true],
        ]);
        $context->set($second);
        $shared->unsetRelation('roles')->assignRole('manager');

        $context->clear();
        $this->actingAs($admin)
            ->delete(route('users.destroy', $shared))
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $first->id,
            'user_id' => $shared->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $second->id,
            'user_id' => $shared->id,
            'is_active' => true,
        ]);
        $this->assertNull(User::withoutGlobalScopes()->findOrFail($shared->id)->deleted_at);

        $context->set($first);
        $this->assertNull(User::query()->find($shared->id));
        $context->set($second);
        $this->assertNotNull(User::query()->find($shared->id));
        $this->assertTrue($shared->unsetRelation('roles')->hasRole('manager'));
    }

    public function test_admin_cannot_edit_a_role_from_another_hotel(): void
    {
        [$first, $admin] = $this->firstTenantWithAdmin();
        $second = $this->newProvisionedTenant();
        $context = app(TenantContext::class);

        $context->set($second);
        $foreignRole = Role::findByName('receptionist');
        $before = $foreignRole->permissions->pluck('name')->sort()->values()->all();

        $context->clear();
        $this->actingAs($admin)
            ->put(route('users.roles.permissions', $foreignRole), [
                'permissions' => ['view_rooms'],
            ])
            ->assertNotFound();

        $context->set($second);
        $this->assertSame($before, $foreignRole->fresh()->permissions->pluck('name')->sort()->values()->all());
        $context->set($first);
        $this->assertTrue($admin->unsetRelation('roles')->hasRole('admin'));
    }

    public function test_hotel_owner_cannot_be_downgraded_or_deactivated(): void
    {
        [$tenant, $admin] = $this->firstTenantWithAdmin();
        DB::table('tenant_user')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $admin->id)
            ->update(['is_owner' => true]);

        app(TenantContext::class)->clear();

        $this->actingAs($admin)
            ->put(route('users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'password' => '',
                'role' => 'manager',
            ])
            ->assertSessionHasErrors('role');

        $this->actingAs($admin)
            ->delete(route('users.destroy', $admin))
            ->assertSessionHas('error');

        app(TenantContext::class)->set($tenant);
        $this->assertTrue($admin->unsetRelation('roles')->hasRole('admin'));
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'is_active' => true,
        ]);
    }

    private function firstTenantWithAdmin(): array
    {
        $tenant = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($tenant);
        app(TenantContext::class)->set($tenant);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return [$tenant, $admin];
    }

    private function newProvisionedTenant(): Tenant
    {
        $tenant = Tenant::factory()->create();
        app(TenantRoleService::class)->provision($tenant);

        return $tenant;
    }
}
