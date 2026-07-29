<?php

namespace Tests\Feature;

use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantIntegrityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrity_command_passes_on_a_valid_database(): void
    {
        $this->artisan('tenants:verify-integrity')
            ->expectsOutput('Tenant integrity passed.')
            ->assertSuccessful();
    }

    public function test_storage_verification_fails_closed_for_a_missing_database_file_reference(): void
    {
        Storage::fake('public');
        $tenant = Tenant::query()->sole();
        $path = "tenants/{$tenant->id}/branding/logo.png";
        Storage::disk('public')->put($path, 'image');
        Setting::set('hotel.logo', $path, 'image');

        $this->artisan('tenants:verify-integrity', ['--verify-storage' => true])
            ->expectsOutput('Tenant integrity passed.')
            ->assertSuccessful();

        Storage::disk('public')->delete($path);

        $this->artisan('tenants:verify-integrity')
            ->expectsOutput('Tenant integrity passed.')
            ->assertSuccessful();

        $this->artisan('tenants:verify-integrity', ['--verify-storage' => true])
            ->expectsOutputToContain('settings.value: 1 rows reference a missing stored file')
            ->assertFailed();
    }

    public function test_storage_verification_rejects_a_cross_tenant_file_namespace(): void
    {
        Storage::fake('public');
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();
        $foreignPath = "tenants/{$second->id}/branding/logo.png";
        Storage::disk('public')->put($foreignPath, 'image');
        Setting::set('hotel.logo', $foreignPath, 'image');

        $this->artisan('tenants:verify-integrity', ['--verify-storage' => true])
            ->expectsOutputToContain("settings.value: 1 rows reference another tenant's storage namespace")
            ->assertFailed();

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_storage_verification_rejects_a_cross_tenant_onboarding_document(): void
    {
        Storage::fake('local');
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();
        $onboardingId = DB::table('tenant_onboardings')->insertGetId([
            'tenant_id' => $first->id,
            'status' => 'not_started',
            'progress' => 0,
            'steps' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignPath = "onboarding/tenant-{$second->id}/contract.pdf";
        Storage::disk('local')->put($foreignPath, 'document');
        DB::table('tenant_onboarding_documents')->insert([
            'tenant_onboarding_id' => $onboardingId,
            'step_key' => 'contract',
            'name' => 'contract.pdf',
            'disk' => 'local',
            'path' => $foreignPath,
            'size' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('tenants:verify-integrity', ['--verify-storage' => true])
            ->expectsOutputToContain("tenant_onboarding_documents.path: 1 rows reference another tenant's storage namespace")
            ->assertFailed();
    }

    public function test_unresolved_provider_event_without_billing_references_is_valid(): void
    {
        DB::table('provider_events')->insert([
            'tenant_id' => null,
            'provider' => 'stripe',
            'external_id' => 'evt-unresolved-without-tenant',
            'event_type' => 'event.received',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('tenants:verify-integrity')
            ->expectsOutput('Tenant integrity passed.')
            ->assertSuccessful();
    }

    public function test_database_rejects_an_invitation_with_a_cross_tenant_role(): void
    {
        $first = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($first);
        $second = Tenant::factory()->create();
        app(TenantRoleService::class)->provision($second);
        $user = User::factory()->create();
        $foreignRoleId = DB::table('roles')
            ->where('team_id', $second->id)
            ->value('id');

        $this->assertNotNull($foreignRoleId);
        try {
            DB::table('tenant_user_invitations')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $first->id,
                'user_id' => $user->id,
                'email' => $user->email,
                'role_id' => $foreignRoleId,
                'expires_at' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A cross-tenant invitation role should be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('tenant_user_invitations', [
                'tenant_id' => $first->id,
                'role_id' => $foreignRoleId,
            ]);
        }
    }

    public function test_integrity_command_detects_an_inconsistent_guest_merge_shadow_key(): void
    {
        $tenant = Tenant::query()->sole();
        $targetId = DB::table('guests')->insertGetId([
            'tenant_id' => $tenant->id,
            'first_name' => 'Merge',
            'last_name' => 'Target',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceId = DB::table('guests')->insertGetId([
            'tenant_id' => $tenant->id,
            'merged_into_guest_id' => $targetId,
            'first_name' => 'Merge',
            'last_name' => 'Source',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::unprepared('DROP TRIGGER IF EXISTS guests_merged_tenant_update_sync');
        DB::table('guests')->where('id', $sourceId)->update([
            'merged_into_guest_tenant_id' => null,
        ]);

        $this->artisan('tenants:verify-integrity')
            ->expectsOutputToContain('guests.merged_into_guest_tenant_id: 1 rows have an inconsistent tenant shadow key')
            ->assertFailed();
    }

    public function test_snapshot_detects_changed_counts_or_financial_totals(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lora-tenant-baseline-');
        $this->assertNotFalse($path);

        try {
            $this->artisan('tenants:verify-integrity', ['--snapshot' => $path])
                ->assertSuccessful();

            RoomType::create([
                'name' => 'Changed after baseline',
                'base_price' => 100,
                'max_occupancy' => 2,
            ]);

            $this->artisan('tenants:verify-integrity', ['--compare' => $path])
                ->expectsOutput('Tenant counts or financial totals changed from the baseline.')
                ->assertFailed();
        } finally {
            if (is_string($path)) {
                @unlink($path);
            }
        }
    }

    public function test_additive_schema_compare_allows_new_tables_and_permission_growth(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lora-tenant-baseline-');
        $this->assertNotFalse($path);

        try {
            $this->artisan('tenants:verify-integrity', ['--snapshot' => $path])
                ->assertSuccessful();

            $baseline = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($baseline);

            $firstTenantTable = array_key_first($baseline['tenant_counts']);
            $this->assertNotNull($firstTenantTable);
            unset($baseline['tenant_counts'][$firstTenantTable]);
            $baseline['central_counts']['permissions'] = max(
                0,
                $baseline['central_counts']['permissions'] - 1,
            );
            file_put_contents($path, json_encode($baseline, JSON_THROW_ON_ERROR));

            $this->artisan('tenants:verify-integrity', [
                '--compare' => $path,
                '--allow-additive-schema' => true,
            ])
                ->expectsOutput('Tenant integrity passed; existing counts and financial totals are unchanged (additive schema allowed).')
                ->assertSuccessful();
        } finally {
            if (is_string($path)) {
                @unlink($path);
            }
        }
    }

    public function test_additive_schema_compare_rejects_changes_to_existing_counts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lora-tenant-baseline-');
        $this->assertNotFalse($path);

        try {
            $this->artisan('tenants:verify-integrity', ['--snapshot' => $path])
                ->assertSuccessful();

            RoomType::create([
                'name' => 'Changed after additive baseline',
                'base_price' => 100,
                'max_occupancy' => 2,
            ]);

            $this->artisan('tenants:verify-integrity', [
                '--compare' => $path,
                '--allow-additive-schema' => true,
            ])
                ->expectsOutputToContain('Baseline value changed: tenant_counts.room_types')
                ->assertFailed();
        } finally {
            if (is_string($path)) {
                @unlink($path);
            }
        }
    }

    public function test_additive_settings_compare_allows_setting_growth_for_an_existing_tenant(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lora-tenant-baseline-');
        $this->assertNotFalse($path);

        try {
            Setting::set('integrity.present_at_baseline', '1', 'boolean');

            $this->artisan('tenants:verify-integrity', ['--snapshot' => $path])
                ->assertSuccessful();

            Setting::set('integrity.added_after_baseline', '1', 'boolean');

            $this->artisan('tenants:verify-integrity', [
                '--compare' => $path,
                '--allow-additive-schema' => true,
                '--allow-additive-settings' => true,
            ])
                ->expectsOutput('Tenant integrity passed; existing data and financial totals are preserved (approved additive schema/settings allowed).')
                ->assertSuccessful();
        } finally {
            if (is_string($path)) {
                @unlink($path);
            }
        }
    }

    public function test_additive_settings_compare_still_rejects_setting_deletion(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lora-tenant-baseline-');
        $this->assertNotFalse($path);

        try {
            Setting::set('integrity.present_at_baseline', '1', 'boolean');

            $this->artisan('tenants:verify-integrity', ['--snapshot' => $path])
                ->assertSuccessful();

            Setting::query()
                ->where('group', 'integrity')
                ->where('key', 'present_at_baseline')
                ->delete();

            $this->artisan('tenants:verify-integrity', [
                '--compare' => $path,
                '--allow-additive-settings' => true,
            ])
                ->expectsOutputToContain('Baseline value changed: tenant_counts.settings')
                ->assertFailed();
        } finally {
            if (is_string($path)) {
                @unlink($path);
            }
        }
    }

    public function test_additive_settings_compare_rejects_growth_outside_settings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lora-tenant-baseline-');
        $this->assertNotFalse($path);

        try {
            $this->artisan('tenants:verify-integrity', ['--snapshot' => $path])
                ->assertSuccessful();

            RoomType::create([
                'name' => 'Not an approved setting',
                'base_price' => 100,
                'max_occupancy' => 2,
            ]);

            $this->artisan('tenants:verify-integrity', [
                '--compare' => $path,
                '--allow-additive-settings' => true,
            ])
                ->expectsOutputToContain('Baseline value changed: tenant_counts.room_types')
                ->assertFailed();
        } finally {
            if (is_string($path)) {
                @unlink($path);
            }
        }
    }
}
