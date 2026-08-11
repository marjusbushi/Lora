<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * is_system marks accounts the LEDGER may auto-route money into (the
 * auto-created Arka/Banka family, Import, per-channel OTA accounts).
 * Custom accounts a hotel adds by hand — e.g. Saturn's "Menaxher" — must
 * never absorb automatic payments even when they share type+currency+scope
 * with a default ("The manager is not the default euro account").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('scope');
        });

        // Backfill: recognize auto-created accounts by their canonical names.
        // PHP-side matching keeps this identical on MySQL and SQLite.
        foreach (DB::table('finance_accounts')->get() as $account) {
            $isSystem = match (true) {
                // Every channel-scope clearing account is importer-created.
                $account->scope === 'channel' && $account->type === 'clearing' => true,
                // The untouched migration-clearing default.
                $account->type === 'clearing' && $account->name === 'Import' => true,
                // Arka/Banka [Bar/Restorant] [CUR] — exactly what auto-create names them.
                in_array($account->type, ['cash', 'bank'], true)
                    && preg_match('/^(Arka|Banka)( Bar\/Restorant)?( ([A-Z]{3}))?$/', (string) $account->name, $m) === 1
                    && ($m[1] === 'Arka') === ($account->type === 'cash')
                    && (($m[4] ?? '') === '' || $m[4] === strtoupper((string) $account->currency)) => true,
                default => false,
            };

            if ($isSystem) {
                DB::table('finance_accounts')->where('id', $account->id)->update(['is_system' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
