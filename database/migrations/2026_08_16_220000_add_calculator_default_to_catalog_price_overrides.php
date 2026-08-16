<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Override i gjendjes fillestare të modulit në kalkulatorin publik të
// marketingut (NULL = vlen 'calculator_default' i config-ut) — vendoset
// nga ekrani "Katalogu i çmimeve", si çmimet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_price_overrides', function (Blueprint $table) {
            $table->boolean('calculator_default_on')->nullable()->after('percentage_bps');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_price_overrides', function (Blueprint $table) {
            $table->dropColumn('calculator_default_on');
        });
    }
};
