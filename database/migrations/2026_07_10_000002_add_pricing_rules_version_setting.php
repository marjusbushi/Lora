<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['group' => 'pricing', 'key' => 'rules_version'],
            ['value' => '0', 'type' => 'number', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('group', 'pricing')
            ->where('key', 'rules_version')
            ->delete();
    }
};
