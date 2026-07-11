<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_search_and_filter_the_current_hotels_user_directory_with_stats(): void
    {
        $tenant = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($tenant);
        app(TenantContext::class)->set($tenant);

        $admin = User::factory()->create([
            'name' => 'Hotel Admin',
            'email' => 'admin@example.test',
        ]);
        $admin->assignRole('admin');

        $activeReceptionist = User::factory()->create([
            'name' => 'Arta Aktive',
            'email' => 'arta@example.test',
        ]);
        $activeReceptionist->assignRole('receptionist');

        $inactiveReceptionist = User::factory()->create([
            'name' => 'Erion Joaktiv',
            'email' => 'erion@example.test',
        ]);
        $inactiveReceptionist->assignRole('receptionist');
        DB::table('tenant_user')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $inactiveReceptionist->id)
            ->update(['is_active' => false]);

        app(TenantContext::class)->clear();

        $this->actingAs($admin)
            ->get(route('users.index', [
                'search' => 'erion',
                'role' => 'receptionist',
                'status' => 'inactive',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Index')
                ->where('stats.total', 3)
                ->where('stats.active', 2)
                ->where('stats.inactive', 1)
                ->where('stats.roles', 5)
                ->where('filters.search', 'erion')
                ->where('filters.role', 'receptionist')
                ->where('filters.status', 'inactive')
                ->has('users.data', 1)
                ->where('users.data.0.id', $inactiveReceptionist->id)
                ->where('users.data.0.name', 'Erion Joaktiv')
                ->where('users.data.0.membership_active', 0)
                ->where('users.from', 1)
                ->where('users.to', 1)
                ->where('users.total', 1));
    }
}
