<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which flow feeds the account: 'general' (reception folio payments,
     * manual movements — the default for every existing account) or 'pos'
     * (auto-created Bar/Restorant drawers when the hotel opts into split
     * POS accounts). Routing keys on this column, never on the name.
     */
    public function up(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->string('scope', 10)->default('general')->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
