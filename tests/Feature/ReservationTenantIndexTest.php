<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReservationTenantIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_date_indexes_are_tenant_leading(): void
    {
        $indexes = collect(Schema::getIndexes('reservations'))->keyBy('name');

        $this->assertSame(
            ['tenant_id', 'check_in_date'],
            $indexes->get('reservations_tenant_check_in_index')['columns'] ?? null,
        );
        $this->assertSame(
            ['tenant_id', 'check_out_date'],
            $indexes->get('reservations_tenant_check_out_index')['columns'] ?? null,
        );
    }
}
