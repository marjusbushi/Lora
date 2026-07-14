<?php

namespace Tests\Feature;

use App\Models\RoomType;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
