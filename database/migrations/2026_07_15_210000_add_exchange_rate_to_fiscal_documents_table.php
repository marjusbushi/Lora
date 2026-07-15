<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->decimal('exchange_rate', 12, 6)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });
    }
};
