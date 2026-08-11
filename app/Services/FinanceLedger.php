<?php

namespace App\Services;

use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Payment;
use App\Models\PosOrderPayment;
use App\Models\PosShift;
use App\Models\PosShiftCurrency;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;

/**
 * The auto-feed into the finance ledger: folio payments, POS tenders/refunds
 * and shift differences become finance_payments WITHOUT re-typing a number. Idempotent by
 * design — each source record maps to at most ONE ledger row (unique
 * sourceable index + updateOrCreate), so observers, webhooks and the
 * finance:backfill command can all run repeatedly without double-counting.
 *
 * Cash goes to the cash account (Arka); card/POK/bank money to the first
 * bank account; OTA-collected folio money to a per-channel clearing account
 * (see channelAccountFor). OTA commissions are deliberately NOT ledger rows
 * (they are never cash movements) — the dashboard reads them straight from
 * reservations.
 */
class FinanceLedger
{
    /**
     * Display names for the per-channel money accounts. Online-collected
     * folio payments (method 'ota') accumulate on these until the OTA's real
     * payout lands in the bank, which the desk records as a transfer.
     */
    public const CHANNEL_ACCOUNT_LABELS = [
        'booking.com' => 'Booking.com',
        'expedia' => 'Expedia',
        'airbnb' => 'Airbnb',
    ];

    /** Where POS money lands, per hotel: the shared hotel accounts (default), a separate POS cash drawer, a separate POS bank, or separate POS cash AND bank accounts. */
    public const POS_MODE_SHARED = 'shared';

    public const POS_MODE_SPLIT_CASH = 'split_cash';

    public const POS_MODE_SPLIT_BANK = 'split_bank';

    public const POS_MODE_SPLIT_ALL = 'split_all';

    public static function posAccountMode(): string
    {
        $mode = Setting::get('finance.pos_account_mode');

        return in_array($mode, [self::POS_MODE_SPLIT_CASH, self::POS_MODE_SPLIT_BANK, self::POS_MODE_SPLIT_ALL], true)
            ? $mode
            : self::POS_MODE_SHARED;
    }

    public static function accountFor(string $method, ?string $currency = null, bool $pos = false): FinanceAccount
    {
        FinanceAccount::ensureDefaults();

        // 'import' = synthetic settlements from a system migration (e.g. the
        // Beds24 era): money that was collected before this PMS existed. It
        // must land on a CLEARING account — never Arka or a real bank — so
        // cash reconciliation and the bank report stay truthful. Any clearing
        // account the hotel created (renamed to taste) is honored first.
        if ($method === 'import') {
            // scope 'general' only: per-channel OTA accounts are also
            // clearing-typed but must never absorb migration settlements.
            $account = FinanceAccount::where('type', 'clearing')
                ->where('scope', 'general')
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->first();

            return $account ?? FinanceAccount::create([
                'name' => 'Import',
                'type' => 'clearing',
                'currency' => BaseCurrency::code(),
                'scope' => 'general',
                'is_active' => true,
                'is_system' => true,
            ]);
        }

        $type = $method === 'cash' ? 'cash' : 'bank';
        $currency = strtoupper($currency ?: BaseCurrency::code());

        // POS money gets its own Bar/Restorant accounts only when the hotel
        // opted in: cash in the split_cash/split_all modes, cards in the
        // split_bank/split_all modes. Scope — not the (renamable) name — is
        // the routing key.
        $mode = self::posAccountMode();
        $scope = $pos && ($type === 'cash'
                ? in_array($mode, [self::POS_MODE_SPLIT_CASH, self::POS_MODE_SPLIT_ALL], true)
                : in_array($mode, [self::POS_MODE_SPLIT_BANK, self::POS_MODE_SPLIT_ALL], true))
            ? 'pos'
            : 'general';

        // Only SYSTEM accounts may absorb automatic money. A custom account a
        // hotel added by hand (Saturn's "Menaxher") can share type+currency+
        // scope with a default, but automation must never treat it as one —
        // the desk moves money there deliberately, with a transfer.
        $account = FinanceAccount::where('type', $type)
            ->where('currency', $currency)
            ->where('scope', $scope)
            ->where('is_system', true)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        if ($account) {
            // A deliberately disabled drawer must still catch a live sale —
            // reactivate rather than fail at the till.
            if (! $account->is_active) {
                $account->update(['is_active' => true]);
            }

            return $account;
        }

        if ($scope === 'general' && $currency === BaseCurrency::code()) {
            // ensureDefaults guarantees the base accounts exist.
            return FinanceAccount::where('type', $type)
                ->where('currency', $currency)
                ->where('scope', 'general')
                ->where('is_system', true)
                ->orderBy('id')
                ->firstOrFail();
        }

        // First tender for this drawer/currency/scope: create the account on
        // the spot (mirrors ensureDefaults) so the sale never blocks on a
        // missing configuration.
        return FinanceAccount::create([
            'name' => ($type === 'cash' ? 'Arka' : 'Banka')
                .($scope === 'pos' ? ' Bar/Restorant' : '')
                .($currency === BaseCurrency::code() ? '' : ' '.$currency),
            'type' => $type,
            'currency' => $currency,
            'scope' => $scope,
            'is_active' => true,
            'is_system' => true,
        ]);
    }

    /**
     * The account for online-collected money of one channel, auto-created on
     * first use in the hotel's selling currency. Its balance reads "what this
     * OTA is holding for us"; the desk empties it with a transfer when the
     * payout really arrives in the bank.
     */
    public static function channelAccountFor(?string $channel): FinanceAccount
    {
        $label = self::CHANNEL_ACCOUNT_LABELS[strtolower(trim((string) $channel))] ?? 'OTA';

        $account = FinanceAccount::where('type', 'clearing')
            ->where('scope', 'channel')
            ->where('name', $label)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        if ($account) {
            if (! $account->is_active) {
                $account->update(['is_active' => true]);
            }

            return $account;
        }

        return FinanceAccount::create([
            'name' => $label,
            'type' => 'clearing',
            'scope' => 'channel',
            'currency' => PricingCurrency::code(),
            'is_active' => true,
            'is_system' => true,
        ]);
    }

    /** Mirror one folio payment into the ledger (or remove it when voided). */
    public function recordFolioPayment(Payment $payment): ?FinancePayment
    {
        // Voided or non-positive rows must LEAVE no ledger trace.
        if ($payment->is_voided || (float) $payment->amount <= 0) {
            $this->removeFor($payment);

            return null;
        }
        $type = $payment->type ?? 'payment';
        if (! in_array($type, ['payment', 'deposit', 'refund'], true)) {
            $this->removeFor($payment);

            return null;
        }

        $baseCurrency = BaseCurrency::code();
        $currency = strtoupper((string) ($payment->currency ?: $baseCurrency));
        $method = in_array($payment->method, ['cash', 'card', 'bank', 'pok', 'ota', 'import'], true) ? $payment->method : 'card';

        $ledger = FinancePayment::firstOrNew([
            'sourceable_type' => Payment::class,
            'sourceable_id' => $payment->id,
        ]);
        $channel = $payment->reservation?->channel;
        $ledger->fill([
            'direction' => $type === 'refund' ? 'out' : 'in',
            // Card/bank/POK money in a foreign currency lands on THAT
            // currency's bank account (auto-created: "Banka EUR"), mirroring
            // the POS tender path. Cash deliberately stays base-routed — the
            // desk runs ONE drawer; POS multicurrency covers foreign drawers.
            'account_id' => ($method === 'ota'
                ? self::channelAccountFor($channel)
                : self::accountFor(
                    $method,
                    $method !== 'cash' && $currency !== $baseCurrency ? $currency : null,
                ))->id,
            'amount' => $payment->amount,
            'currency' => $currency,
            // FinancePayment uses source units per 1 base unit; Payment stores
            // the inverse (base units per 1 source unit). Reuse the frozen
            // snapshot instead of silently taking today's rate.
            'fx_rate' => $currency === $baseCurrency
                ? null
                : round(1 / (float) ($payment->exchange_rate ?: 1 / $this->fxRate($currency)), 6),
            'method' => $method,
            'source' => 'auto',
            'description' => match (true) {
                $method === 'import' => 'Shlyerje importi — rezervimi #'.$payment->reservation_id,
                $method === 'ota' => 'Paguar online nga '
                    .(self::CHANNEL_ACCOUNT_LABELS[strtolower(trim((string) $channel))] ?? 'OTA')
                    .' — rezervimi #'.$payment->reservation_id,
                default => match ($type) {
                    'deposit' => 'Depozitë folio — rezervimi #'.$payment->reservation_id,
                    'refund' => 'Rimbursim folio — rezervimi #'.$payment->reservation_id,
                    default => 'Pagesë folio — rezervimi #'.$payment->reservation_id,
                },
            },
            'paid_at' => $payment->created_at ?? now(),
            'created_by' => $payment->created_by,
        ]);
        $ledger->withFrozenAmountBase((float) $payment->amount_base)->save();

        return $ledger;
    }

    /** Mirror one POS tender/refund. Room charges stay in the guest folio, not Arka/Banka. */
    public function recordPosOrderPayment(PosOrderPayment $payment): ?FinancePayment
    {
        if ($payment->method === 'room_charge' || (float) $payment->amount <= 0) {
            $this->removeFor($payment);

            return null;
        }

        $payment->loadMissing('order');

        $baseCurrency = BaseCurrency::code();
        $currency = strtoupper((string) ($payment->currency ?: $baseCurrency));
        // Foreign tender: the customer's money enters THAT currency's account
        // with its own amount; the frozen POS base equivalent stays authoritative.
        $foreign = $currency !== $baseCurrency
            && $payment->tendered_amount !== null
            && (float) $payment->exchange_rate > 0;

        $ledger = FinancePayment::firstOrNew([
            'sourceable_type' => PosOrderPayment::class,
            'sourceable_id' => $payment->id,
        ]);
        $ledger->fill([
            'direction' => $payment->direction,
            'account_id' => self::accountFor($payment->method, $foreign ? $currency : null, pos: true)->id,
            'amount' => $foreign ? $payment->tendered_amount : $payment->amount,
            'currency' => $foreign ? $currency : $baseCurrency,
            // FinancePayment uses source units per 1 base unit; the POS tender
            // stores base units per 1 source unit — reuse the frozen rate.
            'fx_rate' => $foreign ? round(1 / (float) $payment->exchange_rate, 6) : null,
            'method' => $payment->method,
            'source' => 'auto',
            'description' => ($payment->direction === 'out' ? 'Rimbursim' : 'Pagesë')
                .' POS — porosia #'.$payment->pos_order_id
                .($foreign ? " ({$currency})" : ''),
            'paid_at' => $payment->paid_at,
            'created_by' => $payment->created_by,
        ]);
        $ledger->withFrozenAmountBase((float) $payment->amount)->save();

        return $ledger;
    }

    /**
     * Mirror a CLOSED POS shift. For the current tender workflow this records only
     * the counted over/short adjustment; legacy shifts retain counted-yield behavior.
     */
    public function recordShiftClose(PosShift $shift): ?FinancePayment
    {
        if ($shift->status !== 'closed' || ! $shift->closed_at) {
            $this->removeFor($shift); // re-opened shift leaves no ledger row
            $shift->currencies()->get()->each(fn (PosShiftCurrency $line) => $this->removeFor($line));

            return null;
        }

        $this->recordShiftCurrencyVariances($shift);

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
                'account_id' => self::accountFor('cash', pos: true)->id,
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

    /**
     * Post each foreign currency's counted over/short into THAT currency's POS
     * cash account (mirroring how foreign tenders land there), with the base
     * equivalent frozen at the close-time rate. Zero variances leave no row.
     */
    protected function recordShiftCurrencyVariances(PosShift $shift): void
    {
        foreach ($shift->currencies()->get() as $line) {
            $variance = (float) $line->over_short;

            if ($variance == 0.0 || $line->counted_amount === null) {
                $this->removeFor($line);

                continue;
            }

            $fx = $this->fxRate($line->currency); // source units per 1 base unit

            $ledger = FinancePayment::firstOrNew([
                'sourceable_type' => PosShiftCurrency::class,
                'sourceable_id' => $line->id,
            ]);
            $ledger->fill([
                'direction' => $variance > 0 ? 'in' : 'out',
                'account_id' => self::accountFor('cash', $line->currency, pos: true)->id,
                'amount' => abs($variance),
                'currency' => $line->currency,
                'fx_rate' => round($fx, 6),
                'method' => 'cash',
                'source' => 'auto',
                'description' => 'Diferencë turni POS ('.$line->currency.') — '
                    .($shift->user?->name ?? ('turni #'.$shift->id))
                    .sprintf(' (%+.2f)', $variance),
                'paid_at' => $shift->closed_at,
                'created_by' => $shift->closed_by,
            ]);
            $ledger->withFrozenAmountBase(round(abs($variance) / $fx, 2))->save();
        }
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
