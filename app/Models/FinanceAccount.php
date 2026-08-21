<?php

namespace App\Models;

use App\Services\BaseCurrency;

/**
 * Where the money sits: Arka (cash) or a bank account. The balance is NEVER
 * stored — it is always the sum of the ledger (finance_payments), so it can
 * not drift. Transfers count once: minus on the source (account_id), plus on
 * the counter (counter_account_id). Cross-currency transfers store what
 * actually ARRIVED as counter_amount (in the destination's currency), so a
 * balance is always in the account's own currency.
 */
class FinanceAccount extends TenantModel
{
    protected $fillable = ['name', 'type', 'currency', 'scope', 'iban', 'is_active', 'is_system'];

    protected $casts = ['is_active' => 'boolean', 'is_system' => 'boolean'];

    public function payments()
    {
        return $this->hasMany(FinancePayment::class, 'account_id');
    }

    /**
     * Ledger balance in the ACCOUNT's own currency. A base-currency account
     * sums the frozen base value of every row; a foreign-currency account only holds
     * rows in its own currency (enforced at write time), so it sums amounts.
     */
    public function balance(): float
    {
        $col = strtoupper((string) $this->currency) === BaseCurrency::code() ? 'amount_base' : 'amount';

        $in = (float) FinancePayment::where('account_id', $this->id)->where('direction', 'in')->sum($col);
        $out = (float) FinancePayment::where('account_id', $this->id)->where('direction', 'out')->sum($col);
        $transferOut = (float) FinancePayment::where('account_id', $this->id)->where('direction', 'transfer')->sum($col);
        // The incoming leg of a cross-currency transfer is worth what actually
        // arrived (counter_amount, in THIS account's currency), not the sent
        // amount in the source currency.
        $transferIn = (float) FinancePayment::where('counter_account_id', $this->id)
            ->where('direction', 'transfer')
            ->selectRaw("COALESCE(SUM(COALESCE(counter_amount, {$col})), 0) as total")
            ->value('total');

        return round($in - $out - $transferOut + $transferIn, 2);
    }

    /** Default accounts, safe to call repeatedly (seeder + backfill + tests). */
    public static function ensureDefaults(): void
    {
        $currency = BaseCurrency::code();
        static::firstOrCreate(['name' => 'Arka'], ['type' => 'cash', 'currency' => $currency, 'is_system' => true]);
        static::firstOrCreate(['name' => 'Banka'], ['type' => 'bank', 'currency' => $currency, 'is_system' => true]);
    }
}
