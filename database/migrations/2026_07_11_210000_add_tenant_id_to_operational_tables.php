<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'amenities',
        'audit_logs',
        'budgets',
        'channel_mappings',
        'channel_sync_logs',
        'cleaning_tasks',
        'expenses',
        'floors',
        'folio_items',
        'guest_documents',
        'guests',
        'marketing_spends',
        'menu_categories',
        'menu_items',
        'payments',
        'pos_order_items',
        'pos_orders',
        'pos_shifts',
        'pricing_autopilot_logs',
        'pricing_events',
        'pricing_manual_protections',
        'pricing_reports',
        'rate_overrides',
        'reservation_status_logs',
        'reservations',
        'reviews',
        'room_inventory_snapshots',
        'room_type_images',
        'room_types',
        'rooms',
        'season_rates',
        'seasons',
        'settings',
        'website_search_logs',
    ];

    public function up(): void
    {
        $tenantId = DB::table('tenants')->orderBy('id')->value('id');

        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->cascadeOnDelete();
            });

            DB::table($tableName)->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
            });
        }

        $this->replaceGlobalUniqueIndexes();
        $this->replaceLookupIndexes();
    }

    public function down(): void
    {
        $this->restoreLookupIndexes();
        $this->restoreGlobalUniqueIndexes();

        foreach (array_reverse($this->tables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }
    }

    private function replaceGlobalUniqueIndexes(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropUnique(['room_number']);
            $table->unique(['tenant_id', 'room_number'], 'tenant_room_number_unique');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['group', 'key']);
            $table->unique(['tenant_id', 'group', 'key'], 'tenant_setting_unique');
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->dropUnique(['document_number']);
            $table->unique(['tenant_id', 'document_number'], 'tenant_document_number_unique');
        });

        Schema::table('floors', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->unique(['tenant_id', 'number'], 'tenant_floor_number_unique');
        });

        Schema::table('amenities', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['tenant_id', 'name'], 'tenant_amenity_name_unique');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['period']);
            $table->unique(['tenant_id', 'period'], 'tenant_budget_period_unique');
        });

        Schema::table('pricing_reports', function (Blueprint $table) {
            $table->dropUnique(['week_start']);
            $table->unique(['tenant_id', 'week_start'], 'tenant_pricing_week_unique');
        });

        Schema::table('room_inventory_snapshots', function (Blueprint $table) {
            $table->dropUnique('ris_snapshot_stay_type_unique');
            $table->unique(
                ['tenant_id', 'snapshot_date', 'stay_date', 'room_type_id'],
                'ris_tenant_snapshot_stay_type_unique'
            );
        });
    }

    private function restoreGlobalUniqueIndexes(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropUnique('tenant_room_number_unique');
            $table->unique('room_number');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique('tenant_setting_unique');
            $table->unique(['group', 'key']);
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->dropUnique('tenant_document_number_unique');
            $table->unique('document_number');
        });

        Schema::table('floors', function (Blueprint $table) {
            $table->dropUnique('tenant_floor_number_unique');
            $table->unique('number');
        });

        Schema::table('amenities', function (Blueprint $table) {
            $table->dropUnique('tenant_amenity_name_unique');
            $table->unique('name');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique('tenant_budget_period_unique');
            $table->unique('period');
        });

        Schema::table('pricing_reports', function (Blueprint $table) {
            $table->dropUnique('tenant_pricing_week_unique');
            $table->unique('week_start');
        });

        Schema::table('room_inventory_snapshots', function (Blueprint $table) {
            $table->dropUnique('ris_tenant_snapshot_stay_type_unique');
            $table->unique(
                ['snapshot_date', 'stay_date', 'room_type_id'],
                'ris_snapshot_stay_type_unique'
            );
        });
    }

    private function replaceLookupIndexes(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['channel', 'channel_ref']);
            $table->index(['tenant_id', 'channel', 'channel_ref'], 'tenant_channel_ref_index');
        });

        Schema::table('channel_sync_logs', function (Blueprint $table) {
            $table->dropIndex(['channel', 'direction', 'created_at']);
            $table->index(
                ['tenant_id', 'channel', 'direction', 'created_at'],
                'tenant_channel_sync_lookup_index'
            );
        });
    }

    private function restoreLookupIndexes(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('tenant_channel_ref_index');
            $table->index(['channel', 'channel_ref']);
        });

        Schema::table('channel_sync_logs', function (Blueprint $table) {
            $table->dropIndex('tenant_channel_sync_lookup_index');
            $table->index(['channel', 'direction', 'created_at']);
        });
    }
};
