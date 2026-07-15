<?php

namespace App\Models;

use App\Services\BaseCurrency;

/**
 * A supplier purchase invoice (payable). It may be in the base or a foreign currency — the fx rate is
 * FROZEN on the document, so yesterday's bills never move when today's rate
 * changes. Partial payments accumulate via finance_payments.bill_id until the
 * base total is covered (open → partial → paid).
 */
class Bill extends TenantModel
{
    /** Default expense categories; the owner can override the list in Settings. */
    public const DEFAULT_CATEGORIES = [
        'Ushqim & Pije', 'Utilitete', 'Lavanderi', 'Mirëmbajtje', 'Marketing', 'Të tjera',
    ];

    /** @return list<string> */
    public static function categories(): array
    {
        $custom = Setting::get('financial.expense_categories', null);

        return is_array($custom) && $custom !== [] ? array_values($custom) : self::DEFAULT_CATEGORIES;
    }

    protected $fillable = [
        'supplier_id', 'number', 'category', 'issue_date', 'due_date',
        'currency', 'fx_rate', 'total', 'total_base', 'status', 'notes',
    ];

    protected $casts = [
        'issue_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'total' => 'decimal:2',
        'total_base' => 'decimal:2',
        'fx_rate' => 'decimal:6',
    ];

    protected static function booted(): void
    {
        static::saving(function (Bill $b) {
            if (strtoupper((string) $b->currency) === BaseCurrency::code()) {
                $b->total_base = $b->total;
            } elseif ($b->fx_rate && (float) $b->fx_rate > 0) {
                $b->total_base = round((float) $b->total / (float) $b->fx_rate, 2);
            } else {
                throw new \InvalidArgumentException("fx_rate is required for a {$b->currency} bill.");
            }
        });
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payments()
    {
        return $this->hasMany(FinancePayment::class);
    }

    public function items()
    {
        return $this->hasMany(BillItem::class);
    }

    public function paidBase(): float
    {
        return round((float) $this->payments()->sum('amount_base'), 2);
    }

    public function remainingBase(): float
    {
        return max(0, round((float) $this->total_base - $this->paidBase(), 2));
    }

    /** Recompute status from payments — the single source of state truth. */
    public function refreshStatus(): void
    {
        $paid = $this->paidBase();
        $this->status = $paid <= 0 ? 'open' : ($paid + 0.005 >= (float) $this->total_base ? 'paid' : 'partial');
        $this->saveQuietly();
    }
}
