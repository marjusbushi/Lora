<?php

namespace App\Models;

/**
 * Where the money sits: Arka (cash) or a bank account. The balance is NEVER
 * stored — it is always the sum of the ledger (finance_payments), so it can
 * not drift. Transfers count once: minus on the source (account_id), plus on
 * the counter (counter_account_id). Phase 1 allows transfers only between
 * same-currency accounts, so a balance is always in the account's currency.
 */
class FinanceAccount extends TenantModel
{
    protected $fillable = ['name', 'type', 'currency', 'iban', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function payments()
    {
        return $this->hasMany(FinancePayment::class, 'account_id');
    }

    /**
     * Ledger balance in the ACCOUNT's own currency. A EUR account sums the
     * frozen base value of every row (so paying a LEK bill from the EUR arka
     * deducts its exact EUR equivalent); a non-EUR account only ever holds
     * rows in its own currency (enforced at write time), so it sums amounts.
     */
    public function balance(): float
    {
        $col = strtoupper((string) $this->currency) === 'EUR' ? 'amount_base' : 'amount';

        $in = (float) FinancePayment::where('account_id', $this->id)->where('direction', 'in')->sum($col);
        $out = (float) FinancePayment::where('account_id', $this->id)->where('direction', 'out')->sum($col);
        $transferOut = (float) FinancePayment::where('account_id', $this->id)->where('direction', 'transfer')->sum($col);
        $transferIn = (float) FinancePayment::where('counter_account_id', $this->id)->where('direction', 'transfer')->sum($col);

        return round($in - $out - $transferOut + $transferIn, 2);
    }

    /** Default accounts, safe to call repeatedly (seeder + backfill + tests). */
    public static function ensureDefaults(): void
    {
        static::firstOrCreate(['name' => 'Arka'], ['type' => 'cash', 'currency' => 'EUR']);
        static::firstOrCreate(['name' => 'Banka'], ['type' => 'bank', 'currency' => 'EUR']);
    }
}
