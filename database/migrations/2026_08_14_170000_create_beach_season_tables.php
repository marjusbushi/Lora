<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sezonet e çmimeve të plazhit — të NDARA nga Season/SeasonRate të dhomave
        // (moduli 'beach' shitet më vete; vendimet e ratifikuara #2/#7).
        Schema::create('beach_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            // Ditë INKLUZIVE në të dy skajet, si beach_reservations.
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            // Mbivendosja e datave ndalohet me validim në backend — s'ka priority.
            $table->index(['tenant_id', 'start_date', 'end_date'], 'beach_seasons_range_index');
        });

        Schema::create('beach_season_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('beach_season_id');
            $table->unsignedBigInteger('beach_zone_id');
            $table->decimal('price_per_day', 10, 2);
            $table->timestamps();

            $table->unique(['beach_season_id', 'beach_zone_id'], 'beach_season_prices_unique');
            $table->foreign(['tenant_id', 'beach_season_id'], 'beach_season_prices_season_foreign')
                ->references(['tenant_id', 'id'])
                ->on('beach_seasons')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'beach_zone_id'], 'beach_season_prices_zone_foreign')
                ->references(['tenant_id', 'id'])
                ->on('beach_zones')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beach_season_prices');
        Schema::dropIfExists('beach_seasons');
    }
};
