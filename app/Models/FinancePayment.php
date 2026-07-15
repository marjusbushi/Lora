<?php

namespace App\Models;

use App\Services\BaseCurrency;
use InvalidArgumentException;

/**
 * The unified finance ledger — every money movement is ONE row here:
 * direction in (arkëtim), out (pagesë) or transfer (account_id → counter_account_id).
 * bill_id / invoice_id settle documents (many partial payments per document);
 * the sourceable morph is ONLY the auto-feed origin (folio Payment, POS shift)
 * and is unique per source so imports/backfills can never double-count.
 */
class FinancePayment extends TenantModel
{
    protected $fillable = [
        'direction', 'account_id', 'counter_account_id', 'amount', 'currency',
        'fx_rate', 'amount_base', 'method', 'source', 'bill_id', 'invoice_id',
        'sourceable_type', 'sourceable_id', 'description', 'paid_at', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_base' => 'decimal:2',
        'fx_rate' => 'decimal:6',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // amount_base is a DERIVED invariant in the tenant's functional
        // currency, so callers cannot send a contradictory value.
        static::saving(function (FinancePayment $p) {
            if (strtoupper((string) $p->currency) === BaseCurrency::code()) {
                $p->amount_base = $p->amount;
            } else {
                if (! $p->fx_rate || (float) $p->fx_rate <= 0) {
                    throw new InvalidArgumentException("fx_rate is required for a {$p->currency} payment.");
                }
                $p->amount_base = round((float) $p->amount / (float) $p->fx_rate, 2);
            }
        });
    }

    public function account()
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function counterAccount()
    {
        return $this->belongsTo(FinanceAccount::class, 'counter_account_id');
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sourceable()
    {
        return $this->morphTo();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
