<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantDatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_operational_tenant_id_is_not_nullable(): void
    {
        $tables = [
            'bill_items',
            'bills',
            'comp_rates',
            'finance_accounts',
            'finance_payments',
            'inventory_items',
            'inventory_movements',
            'inventory_transfers',
            'invoices',
            'menu_item_inventory',
            'message_threads',
            'messages',
            'suppliers',
            'warehouses',
        ];

        foreach ($tables as $tableName) {
            $column = collect(Schema::getColumns($tableName))->firstWhere('name', 'tenant_id');

            $this->assertNotNull($column, "{$tableName}.tenant_id is missing.");
            $this->assertFalse($column['nullable'], "{$tableName}.tenant_id must be NOT NULL.");
        }
    }

    public function test_database_rejects_cross_tenant_relations_across_core_modules(): void
    {
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();
        $now = now();

        $roomTypeId = DB::table('room_types')->insertGetId([
            'tenant_id' => $first->id,
            'name' => 'DB protected type',
            'base_price' => 100,
            'max_occupancy' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roomId = DB::table('rooms')->insertGetId([
            'tenant_id' => $first->id,
            'room_type_id' => $roomTypeId,
            'room_number' => 'DB-101',
            'floor' => 1,
            'status' => 'available',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertTenantMoveRejected('rooms', $roomId, $first->id, $second->id);

        $supplierId = DB::table('suppliers')->insertGetId([
            'tenant_id' => $first->id,
            'name' => 'DB protected supplier',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $billId = DB::table('bills')->insertGetId([
            'tenant_id' => $first->id,
            'supplier_id' => $supplierId,
            'issue_date' => today()->toDateString(),
            'currency' => 'EUR',
            'total' => 10,
            'total_base' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertTenantMoveRejected('bills', $billId, $first->id, $second->id);

        $itemId = DB::table('inventory_items')->insertGetId([
            'tenant_id' => $first->id,
            'name' => 'DB protected stock',
            'sku' => 'DB-STOCK',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $warehouseId = DB::table('warehouses')->insertGetId([
            'tenant_id' => $first->id,
            'name' => 'DB protected warehouse',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $movementId = DB::table('inventory_movements')->insertGetId([
            'tenant_id' => $first->id,
            'inventory_item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'type' => 'adjustment',
            'quantity' => 1,
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertTenantMoveRejected('inventory_movements', $movementId, $first->id, $second->id);

        $threadId = DB::table('message_threads')->insertGetId([
            'tenant_id' => $first->id,
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $messageId = DB::table('messages')->insertGetId([
            'tenant_id' => $first->id,
            'message_thread_id' => $threadId,
            'sender' => 'guest',
            'body' => 'DB protected message',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertTenantMoveRejected('messages', $messageId, $first->id, $second->id);

        $issueId = DB::table('maintenance_issues')->insertGetId([
            'tenant_id' => $first->id,
            'title' => 'DB protected issue',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $eventId = DB::table('maintenance_issue_events')->insertGetId([
            'tenant_id' => $first->id,
            'maintenance_issue_id' => $issueId,
            'type' => 'reported',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertTenantMoveRejected('maintenance_issue_events', $eventId, $first->id, $second->id);
    }

    public function test_database_rejects_null_or_unknown_tenant_ids(): void
    {
        try {
            DB::table('comp_rates')->insert([
                'tenant_id' => null,
                'competitor' => 'Invalid tenant',
                'date' => today()->toDateString(),
                'price' => 10,
                'snapshot_date' => today()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A null tenant_id should be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('comp_rates', ['competitor' => 'Invalid tenant']);
        }

        $this->expectException(QueryException::class);
        DB::table('message_threads')->insert([
            'tenant_id' => 999999,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertTenantMoveRejected(string $table, int $id, int $originalTenantId, int $otherTenantId): void
    {
        try {
            DB::table($table)->where('id', $id)->update(['tenant_id' => $otherTenantId]);
            $this->fail("{$table} accepted a cross-tenant relation.");
        } catch (QueryException) {
            $this->assertDatabaseHas($table, ['id' => $id, 'tenant_id' => $originalTenantId]);
        }
    }
}
