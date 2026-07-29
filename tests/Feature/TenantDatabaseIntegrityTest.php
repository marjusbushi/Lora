<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Tenant;
use App\Services\TenantBillingService;
use App\Services\TenantIntegrityAuditor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class TenantDatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_family_trigger_sql_uses_the_driver_specific_shared_lock_syntax(): void
    {
        $migration = require database_path(
            'migrations/2026_07_16_140000_enforce_remaining_same_tenant_foreign_keys.php',
        );
        $childGenerator = new ReflectionMethod($migration, 'mysqlValidationStatements');
        $parentGenerator = new ReflectionMethod($migration, 'mysqlParentValidationStatements');
        $relation = [['billing_invoices', 'tenant_subscription_id', 'tenant_subscriptions']];

        foreach ([
            'mysql' => ['LOCK IN SHARE MODE', 'FOR SHARE'],
            'mariadb' => ['LOCK IN SHARE MODE', 'FOR SHARE'],
        ] as $driver => [$expectedClause, $otherClause]) {
            $childSql = $childGenerator->invoke(
                $migration,
                'billing_invoices',
                $relation,
                $driver,
                'UPDATE',
            );
            $parentSql = $parentGenerator->invoke(
                $migration,
                'tenant_subscriptions',
                $relation,
                $driver,
            );

            $this->assertStringContainsString($expectedClause, $childSql);
            $this->assertStringContainsString($expectedClause, $parentSql);
            $this->assertStringNotContainsString($otherClause, $childSql);
            $this->assertStringNotContainsString($otherClause, $parentSql);
        }
    }

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

    public function test_database_rejects_cross_tenant_guest_merge_relations(): void
    {
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();
        $primaryGuestId = $this->createGuest($first->id, 'Primary');
        $secondaryGuestId = $this->createGuest($first->id, 'Secondary');
        $foreignGuestId = $this->createGuest($second->id, 'Foreign');
        $mergedGuestId = $this->createGuest($first->id, 'Merged', $primaryGuestId);

        $this->assertTenantMoveRejected('guests', $mergedGuestId, $first->id, $second->id);

        $merge = fn (int $primaryId, int $secondaryId) => DB::table('guest_merges')->insert([
            'tenant_id' => $first->id,
            'primary_guest_id' => $primaryId,
            'secondary_guest_id' => $secondaryId,
            'field_sources' => '{}',
            'secondary_snapshot' => '{}',
            'moved_counts' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertQueryRejected(
            fn () => $merge($foreignGuestId, $secondaryGuestId),
            'guest_merges accepted a primary guest from another tenant.',
        );
        $this->assertQueryRejected(
            fn () => $merge($primaryGuestId, $foreignGuestId),
            'guest_merges accepted a secondary guest from another tenant.',
        );
    }

    public function test_guest_shadow_tenant_key_is_not_serialized(): void
    {
        $tenant = Tenant::query()->sole();
        $primaryGuestId = $this->createGuest($tenant->id, 'Serialized primary');
        $mergedGuestId = $this->createGuest($tenant->id, 'Serialized secondary', $primaryGuestId);
        $serialized = Guest::withoutGlobalScopes()->findOrFail($mergedGuestId)->toArray();

        $this->assertSame($primaryGuestId, $serialized['merged_into_guest_id']);
        $this->assertArrayNotHasKey('merged_into_guest_tenant_id', $serialized);
    }

    public function test_database_rejects_cross_tenant_catalog_relations(): void
    {
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();
        $foreignWarehouseId = $this->createWarehouse($second->id, 'Foreign warehouse');
        $foreignInventoryItemId = $this->createInventoryItem($second->id, 'FOREIGN-ITEM');

        $this->assertQueryRejected(
            fn () => DB::table('inventory_items')->insert([
                'tenant_id' => $first->id,
                'name' => 'Cross-tenant room stock',
                'sku' => 'CROSS-ROOM-STOCK',
                'room_warehouse_id' => $foreignWarehouseId,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'inventory_items accepted a room warehouse from another tenant.',
        );

        $categoryId = DB::table('menu_categories')->insertGetId([
            'tenant_id' => $first->id,
            'name' => 'Protected category',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertQueryRejected(
            fn () => DB::table('menu_items')->insert([
                'tenant_id' => $first->id,
                'menu_category_id' => $categoryId,
                'inventory_item_id' => $foreignInventoryItemId,
                'name' => 'Cross-tenant inventory menu item',
                'price' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'menu_items accepted an inventory item from another tenant.',
        );
        $this->assertQueryRejected(
            fn () => DB::table('menu_items')->insert([
                'tenant_id' => $first->id,
                'menu_category_id' => $categoryId,
                'warehouse_id' => $foreignWarehouseId,
                'name' => 'Cross-tenant warehouse menu item',
                'price' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'menu_items accepted a warehouse from another tenant.',
        );
    }

    public function test_database_rejects_cross_tenant_platform_billing_relations(): void
    {
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();
        $firstSubscriptionId = DB::table('tenant_subscriptions')
            ->where('tenant_id', $first->id)
            ->value('id');
        $secondSubscriptionId = app(TenantBillingService::class)->provision($second)->id;

        $this->assertNotNull($firstSubscriptionId);
        $this->assertQueryRejected(
            fn () => $this->createBillingInvoice($first->id, $secondSubscriptionId),
            'billing_invoices accepted a subscription from another tenant.',
        );

        $invoiceId = $this->createBillingInvoice($first->id, (int) $firstSubscriptionId);
        $this->assertQueryRejected(
            fn () => $this->createBillingPayment($second->id, $invoiceId),
            'billing_payments accepted an invoice from another tenant.',
        );
        $paymentId = $this->createBillingPayment($first->id, $invoiceId);

        $this->assertQueryRejected(
            fn () => $this->createBillingAttempt($first->id, subscriptionId: $secondSubscriptionId),
            'billing_payment_attempts accepted a subscription from another tenant.',
        );
        $this->assertQueryRejected(
            fn () => $this->createBillingAttempt($second->id, invoiceId: $invoiceId),
            'billing_payment_attempts accepted an invoice from another tenant.',
        );
        $this->assertQueryRejected(
            fn () => $this->createBillingAttempt($second->id, paymentId: $paymentId),
            'billing_payment_attempts accepted a payment from another tenant.',
        );

        $attemptId = $this->createBillingAttempt(
            $first->id,
            (int) $firstSubscriptionId,
            $invoiceId,
            $paymentId,
        );

        foreach ([
            'attempt' => ['billing_payment_attempt_id' => $attemptId],
            'invoice' => ['billing_invoice_id' => $invoiceId],
            'payment' => ['billing_payment_id' => $paymentId],
        ] as $suffix => $reference) {
            $this->assertQueryRejected(
                fn () => DB::table('provider_events')->insert(array_merge([
                    'tenant_id' => $second->id,
                    'provider' => 'stripe',
                    'external_id' => "evt-cross-{$suffix}",
                    'event_type' => 'payment.updated',
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $reference)),
                "provider_events accepted a {$suffix} from another tenant.",
            );
        }

        $this->assertQueryRejected(
            fn () => DB::table('provider_events')->insert([
                'tenant_id' => null,
                'billing_invoice_id' => $invoiceId,
                'provider' => 'stripe',
                'external_id' => 'evt-linked-without-tenant',
                'event_type' => 'invoice.updated',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'provider_events accepted a billing reference without tenant_id.',
        );
    }

    public function test_database_rejects_cross_tenant_pos_service_relations(): void
    {
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();
        $userId = $this->createPosUser();
        $firstOrderId = $this->createPosOrder($first->id, $userId);
        $secondOrderId = $this->createPosOrder($second->id, $userId);
        $firstShiftId = $this->createPosShift($first->id, $userId);
        $secondShiftId = $this->createPosShift($second->id, $userId);
        $firstTableId = $this->createPosTable($first->id, 'A-1');
        $secondTableId = $this->createPosTable($second->id, 'B-1');
        $firstRoundId = $this->createPosRound($first->id, $firstOrderId, 1);
        $secondRoundId = $this->createPosRound($second->id, $secondOrderId, 1);
        $secondPaymentId = $this->createPosPayment($second->id, $secondOrderId);
        $menuItemId = $this->createMenuItem($first->id);

        $this->assertQueryRejected(
            fn () => $this->createPosPayment($first->id, $secondOrderId),
            'pos_order_payments accepted an order from another tenant.',
        );
        $this->assertQueryRejected(
            fn () => $this->createPosPayment($first->id, $firstOrderId, shiftId: $secondShiftId),
            'pos_order_payments accepted a shift from another tenant.',
        );
        $this->assertQueryRejected(
            fn () => $this->createPosPayment($first->id, $firstOrderId, refundedFromId: $secondPaymentId),
            'pos_order_payments accepted a refund source from another tenant.',
        );
        $this->assertQueryRejected(
            fn () => $this->createPosRound($first->id, $secondOrderId, 2),
            'pos_order_rounds accepted an order from another tenant.',
        );
        $this->assertQueryRejected(
            fn () => DB::table('pos_order_items')->insert([
                'tenant_id' => $first->id,
                'pos_order_id' => $firstOrderId,
                'pos_order_round_id' => $secondRoundId,
                'menu_item_id' => $menuItemId,
                'quantity' => 1,
                'unit_price' => 10,
                'total_price' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'pos_order_items accepted a round from another tenant.',
        );
        $this->assertQueryRejected(
            fn () => $this->createPosOrder($first->id, $userId, $secondTableId),
            'pos_orders accepted a table from another tenant.',
        );

        DB::table('pos_orders')->where('id', $firstOrderId)->update(['pos_table_id' => $firstTableId]);
        $firstPaymentId = $this->createPosPayment($first->id, $firstOrderId, shiftId: $firstShiftId);
        DB::table('pos_order_items')->insert([
            'tenant_id' => $first->id,
            'pos_order_id' => $firstOrderId,
            'pos_order_round_id' => $firstRoundId,
            'menu_item_id' => $menuItemId,
            'quantity' => 1,
            'unit_price' => 10,
            'total_price' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertQueryRejected(
            fn () => DB::table('pos_orders')->where('id', $firstOrderId)->update([
                'pos_table_id' => $secondTableId,
            ]),
            'pos_orders accepted a table update from another tenant.',
        );
        $this->assertQueryRejected(
            fn () => DB::table('pos_order_payments')->where('id', $firstPaymentId)->update([
                'pos_shift_id' => $secondShiftId,
            ]),
            'pos_order_payments accepted a shift update from another tenant.',
        );
        $this->assertQueryRejected(
            fn () => DB::table('pos_order_payments')->where('id', $firstPaymentId)->update([
                'refunded_from_id' => $secondPaymentId,
            ]),
            'pos_order_payments accepted a refund-source update from another tenant.',
        );
        $this->assertQueryRejected(
            fn () => DB::table('pos_order_rounds')->where('id', $firstRoundId)->update([
                'pos_order_id' => $secondOrderId,
            ]),
            'pos_order_rounds accepted an order update from another tenant.',
        );

        $this->assertTenantMoveRejected('pos_tables', $firstTableId, $first->id, $second->id);
        $this->assertTenantMoveRejected('pos_shifts', $firstShiftId, $first->id, $second->id);
        $this->assertTenantMoveRejected('pos_order_rounds', $firstRoundId, $first->id, $second->id);
        $this->assertTenantMoveRejected('pos_orders', $firstOrderId, $first->id, $second->id);
    }

    public function test_pos_order_delete_cascades_rounds_and_payments(): void
    {
        $tenant = Tenant::query()->sole();
        $userId = $this->createPosUser();
        $orderId = $this->createPosOrder($tenant->id, $userId);
        $roundId = $this->createPosRound($tenant->id, $orderId, 1);
        $paymentId = $this->createPosPayment($tenant->id, $orderId);

        DB::table('pos_orders')->where('id', $orderId)->delete();

        $this->assertDatabaseMissing('pos_order_rounds', ['id' => $roundId]);
        $this->assertDatabaseMissing('pos_order_payments', ['id' => $paymentId]);
    }

    public function test_deleting_refund_source_nulls_both_link_columns_without_deleting_refund(): void
    {
        $tenant = Tenant::query()->sole();
        $userId = $this->createPosUser();
        $orderId = $this->createPosOrder($tenant->id, $userId);
        $saleId = $this->createPosPayment($tenant->id, $orderId);
        $refundId = $this->createPosPayment($tenant->id, $orderId, refundedFromId: $saleId);

        $this->assertDatabaseHas('pos_order_payments', [
            'id' => $refundId,
            'tenant_id' => $tenant->id,
            'refunded_from_id' => $saleId,
            'refunded_from_tenant_id' => $tenant->id,
        ]);
        $this->assertTenantMoveRejected('pos_order_payments', $saleId, $tenant->id, Tenant::factory()->create()->id);

        DB::table('pos_order_payments')->where('id', $saleId)->delete();

        $this->assertDatabaseHas('pos_order_payments', [
            'id' => $refundId,
            'tenant_id' => $tenant->id,
            'refunded_from_id' => null,
            'refunded_from_tenant_id' => null,
        ]);
    }

    public function test_pos_same_tenant_migration_rolls_back_and_reapplies_cleanly(): void
    {
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();
        $userId = $this->createPosUser();
        $secondOrderId = $this->createPosOrder($second->id, $userId);
        $migration = require database_path(
            'migrations/2026_07_20_000000_enforce_pos_same_tenant_foreign_keys.php',
        );
        $crossRoundId = null;

        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('pos_order_payments', 'refunded_from_tenant_id'));
            $this->assertFalse(Schema::hasForeignKey('pos_order_rounds', ['tenant_id', 'pos_order_id']));

            $crossRoundId = $this->createPosRound($first->id, $secondOrderId, 99);
            $this->assertDatabaseHas('pos_order_rounds', [
                'id' => $crossRoundId,
                'tenant_id' => $first->id,
                'pos_order_id' => $secondOrderId,
            ]);
            $this->assertContains(
                'pos_order_rounds.pos_order_id: 1 rows cross tenant boundaries',
                app(TenantIntegrityAuditor::class)->violations(),
            );
        } finally {
            if ($crossRoundId !== null) {
                DB::table('pos_order_rounds')->where('id', $crossRoundId)->delete();
            }

            $migration->up();
        }

        $this->assertTrue(Schema::hasColumn('pos_order_payments', 'refunded_from_tenant_id'));
        $this->assertTrue(Schema::hasForeignKey('pos_order_rounds', ['tenant_id', 'pos_order_id']));
        $this->assertQueryRejected(
            fn () => $this->createPosRound($first->id, $secondOrderId, 100),
            'POS same-tenant enforcement was not restored after rollback.',
        );
    }

    public function test_integrity_auditor_detects_an_inconsistent_pos_refund_shadow_key(): void
    {
        $tenant = Tenant::query()->sole();
        $userId = $this->createPosUser();
        $orderId = $this->createPosOrder($tenant->id, $userId);
        $saleId = $this->createPosPayment($tenant->id, $orderId);
        $refundId = $this->createPosPayment($tenant->id, $orderId, refundedFromId: $saleId);
        $migration = require database_path(
            'migrations/2026_07_20_000000_enforce_pos_same_tenant_foreign_keys.php',
        );
        $dropSyncTriggers = new ReflectionMethod($migration, 'dropRefundTenantSyncTriggers');
        $addSyncTriggers = new ReflectionMethod($migration, 'addRefundTenantSyncTriggers');

        $dropSyncTriggers->invoke($migration);

        try {
            DB::table('pos_order_payments')->where('id', $refundId)->update([
                'refunded_from_tenant_id' => null,
            ]);

            $this->assertContains(
                'pos_order_payments.refunded_from_tenant_id: 1 rows have an inconsistent tenant shadow key',
                app(TenantIntegrityAuditor::class)->violations(),
            );
        } finally {
            DB::table('pos_order_payments')->where('id', $refundId)->update([
                'refunded_from_tenant_id' => $tenant->id,
            ]);
            $addSyncTriggers->invoke($migration);
        }
    }

    public function test_database_rejects_parent_tenant_moves_for_every_hardened_family(): void
    {
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();

        $guestTargetId = $this->createGuest($first->id, 'Parent target');
        $this->createGuest($first->id, 'Parent target child', $guestTargetId);
        $this->assertTenantMoveRejected('guests', $guestTargetId, $first->id, $second->id);

        $mergePrimaryId = $this->createGuest($first->id, 'Merge primary parent');
        $mergeSecondaryId = $this->createGuest($first->id, 'Merge secondary parent');
        DB::table('guest_merges')->insert([
            'tenant_id' => $first->id,
            'primary_guest_id' => $mergePrimaryId,
            'secondary_guest_id' => $mergeSecondaryId,
            'field_sources' => '{}',
            'secondary_snapshot' => '{}',
            'moved_counts' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTenantMoveRejected('guests', $mergePrimaryId, $first->id, $second->id);
        $this->assertTenantMoveRejected('guests', $mergeSecondaryId, $first->id, $second->id);

        $categoryId = DB::table('menu_categories')->insertGetId([
            'tenant_id' => $first->id,
            'name' => 'Parent move category',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = $this->createWarehouse($first->id, 'Parent move warehouse');
        DB::table('inventory_items')->insert([
            'tenant_id' => $first->id,
            'name' => 'Warehouse child stock',
            'sku' => 'WAREHOUSE-PARENT-CHILD',
            'room_warehouse_id' => $warehouseId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('menu_items')->insert([
            'tenant_id' => $first->id,
            'menu_category_id' => $categoryId,
            'warehouse_id' => $warehouseId,
            'name' => 'Warehouse child menu item',
            'price' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTenantMoveRejected('warehouses', $warehouseId, $first->id, $second->id);

        $inventoryItemId = $this->createInventoryItem($first->id, 'INVENTORY-PARENT');
        DB::table('menu_items')->insert([
            'tenant_id' => $first->id,
            'menu_category_id' => $categoryId,
            'inventory_item_id' => $inventoryItemId,
            'name' => 'Inventory child menu item',
            'price' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTenantMoveRejected('inventory_items', $inventoryItemId, $first->id, $second->id);

        $subscriptionId = (int) DB::table('tenant_subscriptions')
            ->where('tenant_id', $first->id)
            ->value('id');
        $this->createBillingInvoice($first->id, $subscriptionId);
        $this->createBillingAttempt($first->id, subscriptionId: $subscriptionId);
        $this->assertTenantMoveRejected('tenant_subscriptions', $subscriptionId, $first->id, $second->id);

        $invoiceId = DB::table('billing_invoices')->insertGetId([
            'tenant_id' => $first->id,
            'currency' => 'EUR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createBillingPayment($first->id, $invoiceId);
        $this->createBillingAttempt($first->id, invoiceId: $invoiceId);
        DB::table('provider_events')->insert([
            'tenant_id' => $first->id,
            'billing_invoice_id' => $invoiceId,
            'provider' => 'stripe',
            'external_id' => 'evt-invoice-parent-move',
            'event_type' => 'invoice.updated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTenantMoveRejected('billing_invoices', $invoiceId, $first->id, $second->id);

        $paymentId = DB::table('billing_payments')->insertGetId([
            'tenant_id' => $first->id,
            'provider' => 'manual',
            'method' => 'bank_transfer',
            'currency' => 'EUR',
            'amount_cents' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createBillingAttempt($first->id, paymentId: $paymentId);
        DB::table('provider_events')->insert([
            'tenant_id' => $first->id,
            'billing_payment_id' => $paymentId,
            'provider' => 'stripe',
            'external_id' => 'evt-payment-parent-move',
            'event_type' => 'payment.updated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTenantMoveRejected('billing_payments', $paymentId, $first->id, $second->id);

        $attemptId = $this->createBillingAttempt($first->id);
        DB::table('provider_events')->insert([
            'tenant_id' => $first->id,
            'billing_payment_attempt_id' => $attemptId,
            'provider' => 'stripe',
            'external_id' => 'evt-attempt-parent-move',
            'event_type' => 'payment.attempted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTenantMoveRejected('billing_payment_attempts', $attemptId, $first->id, $second->id);
    }

    public function test_nullable_same_tenant_relations_keep_set_null_foreign_actions(): void
    {
        $relations = [
            ['guests', 'merged_into_guest_id', 'guests'],
            ['inventory_items', 'room_warehouse_id', 'warehouses'],
            ['menu_items', 'inventory_item_id', 'inventory_items'],
            ['menu_items', 'warehouse_id', 'warehouses'],
            ['billing_invoices', 'tenant_subscription_id', 'tenant_subscriptions'],
            ['billing_payments', 'billing_invoice_id', 'billing_invoices'],
            ['billing_payment_attempts', 'tenant_subscription_id', 'tenant_subscriptions'],
            ['billing_payment_attempts', 'billing_invoice_id', 'billing_invoices'],
            ['billing_payment_attempts', 'billing_payment_id', 'billing_payments'],
            ['provider_events', 'billing_payment_attempt_id', 'billing_payment_attempts'],
            ['provider_events', 'billing_invoice_id', 'billing_invoices'],
            ['provider_events', 'billing_payment_id', 'billing_payments'],
        ];

        foreach ($relations as [$childTable, $column, $parentTable]) {
            $foreign = collect(Schema::getForeignKeys($childTable))
                ->first(fn (array $candidate) => $candidate['foreign_table'] === $parentTable
                    && in_array($column, $candidate['columns'], true));

            $this->assertNotNull($foreign, "{$childTable}.{$column} foreign key is missing.");
            $this->assertSame(
                'set null',
                strtolower((string) $foreign['on_delete']),
                "{$childTable}.{$column} must keep ON DELETE SET NULL.",
            );
        }

        foreach ([
            ['guests', ['merged_into_guest_tenant_id', 'merged_into_guest_id']],
            ['guest_merges', ['tenant_id', 'primary_guest_id']],
            ['guest_merges', ['tenant_id', 'secondary_guest_id']],
        ] as [$table, $columns]) {
            $this->assertTrue(
                Schema::hasForeignKey($table, $columns),
                $table.' is missing the expected composite same-tenant foreign key.',
            );
        }
    }

    public function test_parent_deletes_null_nullable_references_without_deleting_children(): void
    {
        $tenant = Tenant::query()->sole();

        $primaryGuestId = $this->createGuest($tenant->id, 'Delete target');
        $mergedGuestId = $this->createGuest($tenant->id, 'Delete survivor', $primaryGuestId);

        DB::table('guests')->where('id', $primaryGuestId)->delete();

        $this->assertDatabaseHas('guests', [
            'id' => $mergedGuestId,
            'tenant_id' => $tenant->id,
            'merged_into_guest_id' => null,
            'merged_into_guest_tenant_id' => null,
        ]);

        $warehouseId = $this->createWarehouse($tenant->id, 'Delete target warehouse');
        $inventoryItemId = DB::table('inventory_items')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Delete target inventory item',
            'sku' => 'DELETE-TARGET-ITEM',
            'room_warehouse_id' => $warehouseId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = DB::table('menu_categories')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Delete regression category',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $menuItemId = DB::table('menu_items')->insertGetId([
            'tenant_id' => $tenant->id,
            'menu_category_id' => $categoryId,
            'inventory_item_id' => $inventoryItemId,
            'warehouse_id' => $warehouseId,
            'name' => 'Delete regression menu item',
            'price' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('warehouses')->where('id', $warehouseId)->delete();

        $this->assertDatabaseHas('inventory_items', [
            'id' => $inventoryItemId,
            'room_warehouse_id' => null,
        ]);
        $this->assertDatabaseHas('menu_items', [
            'id' => $menuItemId,
            'inventory_item_id' => $inventoryItemId,
            'warehouse_id' => null,
        ]);

        DB::table('inventory_items')->where('id', $inventoryItemId)->delete();

        $this->assertDatabaseHas('menu_items', [
            'id' => $menuItemId,
            'tenant_id' => $tenant->id,
            'inventory_item_id' => null,
        ]);

        $subscriptionId = (int) DB::table('tenant_subscriptions')
            ->where('tenant_id', $tenant->id)
            ->value('id');
        $invoiceId = $this->createBillingInvoice($tenant->id, $subscriptionId);
        $paymentId = $this->createBillingPayment($tenant->id, $invoiceId);
        $attemptId = $this->createBillingAttempt(
            $tenant->id,
            $subscriptionId,
            $invoiceId,
            $paymentId,
        );
        $providerEventId = DB::table('provider_events')->insertGetId([
            'tenant_id' => $tenant->id,
            'billing_payment_attempt_id' => $attemptId,
            'billing_invoice_id' => $invoiceId,
            'billing_payment_id' => $paymentId,
            'provider' => 'stripe',
            'external_id' => 'evt-delete-regression',
            'event_type' => 'payment.updated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_subscriptions')->where('id', $subscriptionId)->delete();
        $this->assertDatabaseHas('billing_invoices', [
            'id' => $invoiceId,
            'tenant_subscription_id' => null,
        ]);
        $this->assertDatabaseHas('billing_payment_attempts', [
            'id' => $attemptId,
            'tenant_subscription_id' => null,
        ]);

        DB::table('billing_invoices')->where('id', $invoiceId)->delete();
        $this->assertDatabaseHas('billing_payments', [
            'id' => $paymentId,
            'billing_invoice_id' => null,
        ]);
        $this->assertDatabaseHas('billing_payment_attempts', [
            'id' => $attemptId,
            'billing_invoice_id' => null,
        ]);
        $this->assertDatabaseHas('provider_events', [
            'id' => $providerEventId,
            'billing_invoice_id' => null,
        ]);

        DB::table('billing_payments')->where('id', $paymentId)->delete();
        $this->assertDatabaseHas('billing_payment_attempts', [
            'id' => $attemptId,
            'billing_payment_id' => null,
        ]);
        $this->assertDatabaseHas('provider_events', [
            'id' => $providerEventId,
            'billing_payment_id' => null,
        ]);

        DB::table('billing_payment_attempts')->where('id', $attemptId)->delete();
        $this->assertDatabaseHas('provider_events', [
            'id' => $providerEventId,
            'tenant_id' => $tenant->id,
            'billing_payment_attempt_id' => null,
        ]);
    }

    public function test_deleting_a_tenant_preserves_provider_event_after_clearing_billing_references(): void
    {
        $tenant = Tenant::factory()->create();
        $primaryGuestId = $this->createGuest($tenant->id, 'Tenant delete primary');
        $secondaryGuestId = $this->createGuest(
            $tenant->id,
            'Tenant delete merged guest',
            $primaryGuestId,
        );
        $guestMergeId = DB::table('guest_merges')->insertGetId([
            'tenant_id' => $tenant->id,
            'primary_guest_id' => $primaryGuestId,
            'secondary_guest_id' => $secondaryGuestId,
            'field_sources' => '{}',
            'secondary_snapshot' => '{}',
            'moved_counts' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $subscriptionId = app(TenantBillingService::class)->provision($tenant)->id;
        $invoiceId = $this->createBillingInvoice($tenant->id, $subscriptionId);
        $paymentId = $this->createBillingPayment($tenant->id, $invoiceId);
        $attemptId = $this->createBillingAttempt(
            $tenant->id,
            $subscriptionId,
            $invoiceId,
            $paymentId,
        );
        $providerEventId = DB::table('provider_events')->insertGetId([
            'tenant_id' => $tenant->id,
            'billing_payment_attempt_id' => $attemptId,
            'billing_invoice_id' => $invoiceId,
            'billing_payment_id' => $paymentId,
            'provider' => 'stripe',
            'external_id' => 'evt-tenant-delete-regression',
            'event_type' => 'payment.updated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->where('id', $tenant->id)->delete();

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
        $this->assertDatabaseMissing('guest_merges', ['id' => $guestMergeId]);
        $this->assertDatabaseMissing('guests', ['id' => $primaryGuestId]);
        $this->assertDatabaseMissing('guests', ['id' => $secondaryGuestId]);
        $this->assertDatabaseHas('provider_events', [
            'id' => $providerEventId,
            'tenant_id' => null,
            'billing_payment_attempt_id' => null,
            'billing_invoice_id' => null,
            'billing_payment_id' => null,
        ]);
    }

    private function createGuest(int $tenantId, string $suffix, ?int $mergedIntoGuestId = null): int
    {
        return DB::table('guests')->insertGetId([
            'tenant_id' => $tenantId,
            'merged_into_guest_id' => $mergedIntoGuestId,
            'first_name' => 'Protected',
            'last_name' => $suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createWarehouse(int $tenantId, string $name): int
    {
        return DB::table('warehouses')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createInventoryItem(int $tenantId, string $sku): int
    {
        return DB::table('inventory_items')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Protected stock',
            'sku' => $sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBillingInvoice(int $tenantId, int $subscriptionId): int
    {
        return DB::table('billing_invoices')->insertGetId([
            'tenant_id' => $tenantId,
            'tenant_subscription_id' => $subscriptionId,
            'currency' => 'EUR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBillingPayment(int $tenantId, int $invoiceId): int
    {
        return DB::table('billing_payments')->insertGetId([
            'tenant_id' => $tenantId,
            'billing_invoice_id' => $invoiceId,
            'provider' => 'manual',
            'method' => 'bank_transfer',
            'currency' => 'EUR',
            'amount_cents' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBillingAttempt(
        int $tenantId,
        ?int $subscriptionId = null,
        ?int $invoiceId = null,
        ?int $paymentId = null,
    ): int {
        return DB::table('billing_payment_attempts')->insertGetId([
            'tenant_id' => $tenantId,
            'tenant_subscription_id' => $subscriptionId,
            'billing_invoice_id' => $invoiceId,
            'billing_payment_id' => $paymentId,
            'provider' => 'stripe',
            'currency' => 'EUR',
            'amount_cents' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPosUser(): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'POS integrity user',
            'email' => 'pos-integrity-'.str()->uuid().'@example.test',
            'password' => 'not-used',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPosOrder(int $tenantId, int $userId, ?int $tableId = null): int
    {
        return DB::table('pos_orders')->insertGetId([
            'tenant_id' => $tenantId,
            'pos_table_id' => $tableId,
            'status' => 'open',
            'service_status' => 'open',
            'total_amount' => 0,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPosShift(int $tenantId, int $userId): int
    {
        return DB::table('pos_shifts')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'open',
            'opening_float' => 0,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPosTable(int $tenantId, string $number): int
    {
        return DB::table('pos_tables')->insertGetId([
            'tenant_id' => $tenantId,
            'number' => $number,
            'name' => 'Protected table '.$number,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPosRound(int $tenantId, int $orderId, int $sequence): int
    {
        return DB::table('pos_order_rounds')->insertGetId([
            'tenant_id' => $tenantId,
            'pos_order_id' => $orderId,
            'sequence' => $sequence,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPosPayment(
        int $tenantId,
        int $orderId,
        ?int $shiftId = null,
        ?int $refundedFromId = null,
    ): int {
        return DB::table('pos_order_payments')->insertGetId([
            'tenant_id' => $tenantId,
            'pos_order_id' => $orderId,
            'pos_shift_id' => $shiftId,
            'direction' => $refundedFromId ? 'out' : 'in',
            'method' => 'cash',
            'amount' => 10,
            'refunded_from_id' => $refundedFromId,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createMenuItem(int $tenantId): int
    {
        $categoryId = DB::table('menu_categories')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'POS integrity category',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('menu_items')->insertGetId([
            'tenant_id' => $tenantId,
            'menu_category_id' => $categoryId,
            'name' => 'POS integrity item',
            'price' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertQueryRejected(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($message);
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
