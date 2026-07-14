<?php

namespace Tests\Feature;

use App\Models\RoomType;
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
}
