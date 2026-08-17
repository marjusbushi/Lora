<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beach_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('price_per_day', 10, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name'], 'beach_zones_name_unique');
        });

        Schema::create('beach_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('beach_zone_id');
            $table->string('number', 10);
            $table->char('qr_token', 40)->unique();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number'], 'beach_units_number_unique');
            $table->index(['tenant_id', 'beach_zone_id', 'sort_order'], 'beach_units_zone_index');
            $table->foreign(['tenant_id', 'beach_zone_id'], 'beach_units_zone_foreign')
                ->references(['tenant_id', 'id'])
                ->on('beach_zones')
                ->restrictOnDelete();
        });

        Schema::create('beach_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('beach_unit_id');
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->string('guest_name', 150);
            $table->string('guest_phone', 50);
            $table->string('guest_email')->nullable();
            // Ditë INKLUZIVE, jo netë: 15–17 = 3 ditë plazh.
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending');
            $table->enum('source', ['website', 'reception'])->default('reception');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->char('confirmation_token', 40)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'beach_unit_id', 'start_date', 'end_date'], 'beach_reservations_range_index');
            $table->index(['tenant_id', 'status', 'start_date'], 'beach_reservations_status_index');
            $table->foreign(['tenant_id', 'beach_unit_id'], 'beach_reservations_unit_foreign')
                ->references(['tenant_id', 'id'])
                ->on('beach_units')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'beach_reservations_reservation_foreign')
                ->references(['tenant_id', 'id'])
                ->on('reservations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beach_reservations');
        Schema::dropIfExists('beach_units');
        Schema::dropIfExists('beach_zones');
    }
};
