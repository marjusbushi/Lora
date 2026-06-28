<?php
namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ReportsLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_built_reports_render(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $map = [
            'reports.index' => 'Reports/Index',
            'reports.executive' => 'Reports/Executive',
            'reports.channels' => 'Reports/Channels',
            'reports.outstanding' => 'Reports/Outstanding',
            'reports.shifts' => 'Reports/Shifts',
        ];
        foreach ($map as $name => $component) {
            $this->actingAs($admin)->get(route($name))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $p) => $p->component($component));
        }
    }
}
