<?php

namespace App\Models;

use App\Services\BaseCurrency;
use Illuminate\Support\Collection;

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

    public const UNCATEGORIZED = 'Të tjera';

    /**
     * Line-accurate spend per ROOT inventory-tree category, in the base
     * currency. The document-level category is a human approximation — the
     * lines know exactly what was bought, so a mixed bill splits correctly.
     * Lines whose item has no tree category land in "Të tjera".
     *
     * @return Collection<string, float> category name => spend, sorted desc
     */
    public static function spendByItemCategory(string $from, ?string $to = null): Collection
    {
        $rootNames = InventoryCategory::rootNameMap();

        $bills = static::query()
            ->whereDate('issue_date', '>=', $from)
            ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))
            ->with(['items:id,bill_id,inventory_item_id,line_total', 'items.item:id,category_id'])
            ->get(['id', 'category', 'total', 'total_base']);

        $spend = [];
        foreach ($bills as $bill) {
            // Scale each line from the document currency to the frozen base
            // total, so foreign-currency bills split accurately too.
            $ratio = (float) $bill->total > 0 ? (float) $bill->total_base / (float) $bill->total : 0.0;

            if ($bill->items->isEmpty()) {
                // A totals-only bill has no lines to read — its stored
                // (auto-derived or legacy) category is the best truth we have.
                $name = $bill->category ?: self::UNCATEGORIZED;
                $spend[$name] = ($spend[$name] ?? 0.0) + (float) $bill->total_base;

                continue;
            }

            foreach ($bill->items as $line) {
                $name = $rootNames[$line->item?->category_id] ?? self::UNCATEGORIZED;
                $spend[$name] = ($spend[$name] ?? 0.0) + round((float) $line->line_total * $ratio, 2);
            }
        }

        return collect($spend)->map(fn ($v) => round($v, 2))->sortDesc();
    }

    /** The root tree category carrying most of this bill's value — used to auto-fill the stored category. */
    public function dominantItemCategory(): ?string
    {
        $this->loadMissing('items.item:id,category_id');
        if ($this->items->isEmpty()) {
            return null;
        }

        $rootNames = InventoryCategory::rootNameMap();

        return $this->items
            ->groupBy(fn (BillItem $line) => $rootNames[$line->item?->category_id] ?? self::UNCATEGORIZED)
            ->map(fn ($lines) => (float) $lines->sum('line_total'))
            ->sortDesc()
            ->keys()
            ->first();
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
        return round((float) $this->payments()->where('direction', 'out')->sum('amount_base'), 2);
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
