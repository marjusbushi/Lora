<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_admin_can_update_a_role_permissions(): void
    {
        $admin = $this->admin();
        $role = Role::findByName('receptionist');

        $this->actingAs($admin)
            ->put(route('users.roles.permissions', $role->id), [
                'permissions' => ['view_rooms', 'view_reservations', 'create_reservations'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            ['view_rooms', 'view_reservations', 'create_reservations'],
            $role->fresh()->permissions->pluck('name')->all()
        );
    }

    public function test_admin_role_cannot_be_restricted(): void
    {
        $admin = $this->admin();
        $role = Role::findByName('admin');
        $before = $role->permissions->count();

        $this->actingAs($admin)
            ->put(route('users.roles.permissions', $role->id), ['permissions' => []])
            ->assertSessionHas('error');

        $this->assertEquals($before, $role->fresh()->permissions->count());
    }

    public function test_admin_can_create_a_role(): void
    {
        $this->actingAs($this->admin())
            ->post(route('users.roles.store'), ['name' => 'kontabilist'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(Role::where('name', 'kontabilist')->exists());
    }

    public function test_non_admin_cannot_edit_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $role = Role::findByName('receptionist');

        $this->actingAs($manager)
            ->put(route('users.roles.permissions', $role->id), ['permissions' => []])
            ->assertForbidden();
    }
}
