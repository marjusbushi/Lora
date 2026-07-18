<?php

namespace App\Services;

use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Payment;
use App\Models\PosOrderPayment;
use App\Models\PosShift;
use Illuminate\Database\Eloquent\Model;

/**
 * The auto-feed into the finance ledger: folio payments, POS tenders/refunds
 * and shift differences become finance_payments WITHOUT re-typing a number. Idempotent by
 * design — each source record maps to at most ONE ledger row (unique
 * sourceable index + updateOrCreate), so observers, webhooks and the
 * finance:backfill command can all run repeatedly without double-counting.
 *
 * Cash goes to the cash account (Arka); card/POK/OTA/bank money to the first
 * bank account. OTA commissions are deliberately NOT ledger rows (they are
 * never cash movements) — the dashboard reads them straight from reservations.
 */
class FinanceLedger
{
    public static function accountFor(string $method): FinanceAccount
    {
        FinanceAccount::ensureDefaults();

        $type = $method === 'cash' ? 'cash' : 'bank';

        return FinanceAccount::where('type', $type)
            ->where('currency', BaseCurrency::code())
            ->where('is_active', true)
            ->orderBy('id')
            ->firstOrFail();
    }

    /** Mirror one folio payment into the ledger (or remove it when voided). */
    public function recordFolioPayment(Payment $payment): ?FinancePayment
    {
        // Voided or non-positive rows must LEAVE no ledger trace.
        if ($payment->is_voided || (float) $payment->amount <= 0) {
            $this->removeFor($payment);

            return null;
        }
        if (($payment->type ?? 'payment') !== 'payment') {
            return null; // refunds/adjustments are out of Phase 1 scope
        }

        $baseCurrency = BaseCurrency::code();
        $currency = strtoupper((string) ($payment->currency ?: $baseCurrency));
        $method = in_array($payment->method, ['cash', 'card', 'bank', 'pok', 'ota'], true) ? $payment->method : 'card';

        return FinancePayment::updateOrCreate(
            ['sourceable_type' => Payment::class, 'sourceable_id' => $payment->id],
            [
                'direction' => 'in',
                'account_id' => self::accountFor($method)->id,
                'amount' => $payment->amount,
                'currency' => $currency,
                'fx_rate' => $currency === $baseCurrency ? null : $this->fxRate($currency),
                'method' => $method,
                'source' => 'auto',
                'description' => 'Pagesë folio — rezervimi #'.$payment->reservation_id,
                'paid_at' => $payment->created_at ?? now(),
                'created_by' => $payment->created_by,
            ],
        );
    }

    /** Mirror one POS tender/refund. Room charges stay in the guest folio, not Arka/Banka. */
    public function recordPosOrderPayment(PosOrderPayment $payment): ?FinancePayment
    {
        if ($payment->method === 'room_charge' || (float) $payment->amount <= 0) {
            $this->removeFor($payment);

            return null;
        }

        $payment->loadMissing('order');

        return FinancePayment::updateOrCreate(
            ['sourceable_type' => PosOrderPayment::class, 'sourceable_id' => $payment->id],
            [
                'direction' => $payment->direction,
                'account_id' => self::accountFor($payment->method)->id,
                'amount' => $payment->amount,
                'currency' => BaseCurrency::code(),
                'fx_rate' => null,
                'method' => $payment->method,
                'source' => 'auto',
                'description' => ($payment->direction === 'out' ? 'Rimbursim' : 'Pagesë')
                    .' POS — porosia #'.$payment->pos_order_id,
                'paid_at' => $payment->paid_at,
                'created_by' => $payment->created_by,
            ],
        );
    }

    /**
     * Mirror a CLOSED POS shift. For the current tender workflow this records only
     * the counted over/short adjustment; legacy shifts retain counted-yield behavior.
     */
    public function recordShiftClose(PosShift $shift): ?FinancePayment
    {
        if ($shift->status !== 'closed' || ! $shift->closed_at) {
            $this->removeFor($shift); // re-opened shift leaves no ledger row

            return null;
        }

        // New tenders reach Arka/Banka at payment time. Orders completed before the
        // tender table existed are posted here, even when the shift spans deployment.
        $legacyCash = (float) $shift->orders()
            ->where('status', 'completed')
            ->where('payment_method', 'cash')
            ->whereDoesntHave('payments', fn ($query) => $query->where('direction', 'in'))
            ->sum('total_amount');
        $hasNewTenders = $shift->payments()->where('direction', 'in')->exists();
        $yield = $hasNewTenders || $legacyCash > 0
            ? round($legacyCash + (float) $shift->over_short, 2)
            : ($shift->counted_cash !== null
                ? round((float) $shift->counted_cash - (float) $shift->opening_float, 2)
                : round((float) $shift->cash_sales, 2));
        if ($yield == 0.0) {
            $this->removeFor($shift);

            return null;
        }

        return FinancePayment::updateOrCreate(
            ['sourceable_type' => PosShift::class, 'sourceable_id' => $shift->id],
            [
                'direction' => $yield > 0 ? 'in' : 'out',
                'account_id' => self::accountFor('cash')->id,
                'amount' => abs($yield),
                'currency' => BaseCurrency::code(),
                'fx_rate' => null,
                'method' => 'cash',
                'source' => 'auto',
                'description' => ($legacyCash > 0 ? 'Mbyllje turni POS — ' : 'Diferencë turni POS — ')
                    .($shift->user?->name ?? ('turni #'.$shift->id))
                    .((float) $shift->over_short != 0.0 ? sprintf(' (%+.2f)', (float) $shift->over_short) : ''),
                'paid_at' => $shift->closed_at,
                'created_by' => $shift->closed_by,
            ],
        );
    }

    public function removeFor(Model $source): void
    {
        FinancePayment::where('sourceable_type', get_class($source))
            ->where('sourceable_id', $source->getKey())
            ->delete();
    }

    /** Quote-currency units per one base-currency unit, frozen on the row. */
    protected function fxRate(string $currency): float
    {
        $fx = (float) (BaseCurrency::rate($currency) ?? 0);
        if ($fx <= 0) {
            throw new \RuntimeException("Kursi {$currency}/".BaseCurrency::code().' mungon — aktivizo Settings → Monedhat ose vendos kursin manual.');
        }

        return $fx;
    }
}
