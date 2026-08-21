<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-room direct bookings (one POK order pays a whole booking group) need one folio
     * card-payment row PER reservation of the group, all carrying the same pok_order_id.
     * The 2026-07-13 tenant re-key left payments with UNIQUE(tenant_id, pok_order_id) —
     * one row per order — so a group's second member could never record its share.
     * Generalize to UNIQUE(tenant_id, pok_order_id, reservation_id): the double-record
     * guard stays exactly as strong per reservation (a duplicate/late webhook still can't
     * insert a second payment for the same order+reservation), and stays tenant-scoped.
     * reservations keeps its UNIQUE(tenant_id, pok_order_id) — only the PRIMARY holds the order.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasIndex('payments', 'payments_tenant_id_pok_order_id_unique')) {
                $table->dropUnique('payments_tenant_id_pok_order_id_unique');
            }
            if (! Schema::hasIndex('payments', 'payments_pok_order_reservation_unique')) {
                $table->unique(['tenant_id', 'pok_order_id', 'reservation_id'], 'payments_pok_order_reservation_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasIndex('payments', 'payments_pok_order_reservation_unique')) {
                $table->dropUnique('payments_pok_order_reservation_unique');
            }
            if (! Schema::hasIndex('payments', 'payments_tenant_id_pok_order_id_unique')) {
                $table->unique(['tenant_id', 'pok_order_id']);
            }
        });
    }
};
