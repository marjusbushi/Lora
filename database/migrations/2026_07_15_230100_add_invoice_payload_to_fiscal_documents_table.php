<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->json('invoice_payload')->nullable()->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropColumn('invoice_payload');
        });
    }
};
