<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformBillingService
{
    public function __construct(
        private TenantBillingService $tenantBilling,
        private TenantContext $tenantContext,
        private PlatformBillingCurrency $billingCurrency,
    ) {}

    public function createInvoice(Tenant $tenant, array $data): BillingInvoice
    {
        return DB::transaction(function () use ($tenant, $data) {
            $tenant->loadMissing(['subscription', 'moduleEntitlements']);
            $summary = $this->tenantBilling->summary($tenant);
            $annual = $summary['billing_cycle'] === 'annual';
            $startsOn = Carbon::parse($data['period_starts_on'] ?? now()->startOfDay());
            $endsOn = isset($data['period_ends_on'])
                ? Carbon::parse($data['period_ends_on'])
                : ($annual ? $startsOn->copy()->addYear()->subDay() : $startsOn->copy()->addMonth()->subDay());

            // Katalogu është në cent EURO. Nëse hoteli faturohet në monedhë
            // tjetër, kursi merret NJË HERË këtu dhe ngrihet mbi faturë — një
            // dokument i lëshuar nuk guxon të ndryshojë vlerë kur kursi lëviz.
            $currency = $summary['billing_currency'];
            $rate = $this->billingCurrency->rateFor($tenant->subscription, $currency);

            $linePayloads = collect($summary['modules'])
                ->filter(fn (array $module) => $module['enabled'] && $module['monthly_cents'] > 0)
                ->map(function (array $module) use ($annual, $rate) {
                    $baseAmount = $annual ? $module['monthly_cents'] * 12 : $module['monthly_cents'];
                    // Rrumbullakimi bëhet PËR RRESHT dhe nëntotali është shuma e
                    // rreshtave — ndryshe fatura s'do të mblidhej me sytë e klientit.
                    $amount = $this->billingCurrency->convertCents($baseAmount, $rate);

                    return [
                        'type' => 'module',
                        'module_code' => $module['code'],
                        'description' => $module['name'].($annual ? ' · 12 muaj' : ' · 1 muaj'),
                        'quantity' => 1,
                        'unit_amount_cents' => $amount,
                        'amount_cents' => $amount,
                        'metadata' => [
                            'billing_model' => $module['billing_model'],
                            'source_quantity' => $module['quantity'],
                            'monthly_cents' => $module['monthly_cents'],
                            // Gjurma e vlerës origjinale, që një faturë në lek të
                            // mund të rilexohet gjithmonë kundrejt katalogut.
                            'base_amount_cents' => $baseAmount,
                            'base_currency' => PlatformBillingCurrency::BASE,
                        ],
                    ];
                })
                ->values();

            if ($linePayloads->isEmpty()) {
                throw ValidationException::withMessages([
                    'tenant_id' => 'Ky hotel nuk ka module me tarifë fikse për t’u faturuar.',
                ]);
            }

            $subtotal = (int) $linePayloads->sum('amount_cents');
            $discount = $annual
                ? (int) round($subtotal * ($summary['annual_discount_percent'] / 100))
                : 0;

            // Zbritja del si përqindje e nëntotalit të konvertuar, ndaj duhet
            // rrumbullakosur në të njëjtin hap si rreshtat.
            if ($discount > 0 && $rate !== 1.0) {
                $discount = $this->billingCurrency->roundToStep($discount);
            }

            $invoice = BillingInvoice::query()->create([
                'tenant_id' => $tenant->id,
                'tenant_subscription_id' => $tenant->subscription?->id,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'status' => ($data['issue_now'] ?? false) ? 'open' : 'draft',
                'currency' => $currency,
                'fx_rate' => $rate,
                'fx_base' => PlatformBillingCurrency::BASE,
                'subtotal_cents' => $subtotal,
                'discount_cents' => $discount,
                'tax_cents' => 0,
                'total_cents' => $subtotal - $discount,
                'period_starts_on' => $startsOn,
                'period_ends_on' => $endsOn,
                'issued_at' => ($data['issue_now'] ?? false) ? now() : null,
                'due_on' => $data['due_on'],
                'notes' => $data['notes'] ?? null,
                'metadata' => [
                    'billing_cycle' => $summary['billing_cycle'],
                    'annual_discount_percent' => $summary['annual_discount_percent'],
                    'source' => $data['source'] ?? 'manual',
                ],
            ]);

            $invoice->forceFill(['number' => sprintf('INV-%s-%05d', now()->format('Y'), $invoice->id)])->save();
            $invoice->lines()->createMany($linePayloads->all());

            return $invoice->load('lines');
        });
    }

    public function publish(BillingInvoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages(['invoice' => 'Vetëm një faturë Draft mund të publikohet.']);
        }

        DB::transaction(function () use ($invoice) {
            $this->refreshFxSnapshot($invoice);
            $invoice->update(['status' => 'open', 'issued_at' => now()]);
        });
    }

    /**
     * Kursi ngrihet në çastin e LËSHIMIT, jo kur u shkrua drafti.
     *
     * Një draft mund të rrijë me ditë; po ta mbante kursin e ditës kur u shkrua,
     * dokumenti do të mbante datë lëshimi të sotme me kurs të djeshëm. Rreshtat
     * rindërtohen nga vlera ORIGJINALE në euro (metadata.base_amount_cents) —
     * kurrë nga shuma e konvertuar, që do ta përsëriste gabimin e rrumbullakimit.
     */
    private function refreshFxSnapshot(BillingInvoice $invoice): void
    {
        if ($invoice->currency === PlatformBillingCurrency::BASE) {
            return;
        }

        $invoice->loadMissing('lines', 'subscription');
        $rate = $this->billingCurrency->rateFor($invoice->subscription, $invoice->currency);

        if ((float) $invoice->fx_rate === $rate) {
            return;
        }

        // Llogaritet e GJITHA para se të shkruhet: një faturë e vjetër pa gjurmën
        // e vlerës bazë nuk guxon të mbetet gjysmë e rishkruar.
        $amounts = [];
        $subtotal = 0;

        foreach ($invoice->lines as $line) {
            $base = $line->metadata['base_amount_cents'] ?? null;

            if (! is_numeric($base)) {
                return;
            }

            $amount = $this->billingCurrency->convertCents((int) $base, $rate);
            $amounts[$line->id] = $amount;
            $subtotal += $amount;
        }

        if ($amounts === []) {
            return;
        }

        $annual = ($invoice->metadata['billing_cycle'] ?? 'monthly') === 'annual';
        $discountPercent = (int) ($invoice->metadata['annual_discount_percent'] ?? 0);
        $discount = $annual
            ? $this->billingCurrency->roundToStep((int) round($subtotal * ($discountPercent / 100)))
            : 0;

        foreach ($invoice->lines as $line) {
            $line->update([
                'unit_amount_cents' => $amounts[$line->id],
                'amount_cents' => $amounts[$line->id],
            ]);
        }

        $invoice->update([
            'fx_rate' => $rate,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'total_cents' => $subtotal - $discount,
        ]);
    }

    /**
     * KPI-t e faturave, GJITHMONË në cent EURO.
     *
     * Faturat në lek ruajnë cent lekë; po t'i mblidhje drejtpërdrejt me ato në
     * euro, kartat e të ardhurave do të shfaqnin shuma qesharake. Normalizimi
     * bëhet me kursin e NGRIRË të secilit dokument.
     *
     * @return array{paid_cents:int, open_cents:int, overdue_cents:int, currency:string}
     */
    public function invoiceStatsInBase(): array
    {
        $paid = 0;
        $open = 0;
        $overdue = 0;
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        BillingInvoice::query()
            ->whereIn('status', ['paid', 'open', 'overdue'])
            ->select(['id', 'status', 'currency', 'fx_rate', 'total_cents', 'amount_paid_cents', 'paid_at'])
            ->chunkById(500, function ($invoices) use (&$paid, &$open, &$overdue, $monthStart, $monthEnd) {
                foreach ($invoices as $invoice) {
                    $rate = $invoice->fx_rate !== null ? (float) $invoice->fx_rate : null;

                    if ($invoice->status === 'paid') {
                        if ($invoice->paid_at?->betweenIncluded($monthStart, $monthEnd)) {
                            $paid += $this->billingCurrency->toBaseCents($invoice->total_cents, $rate);
                        }

                        continue;
                    }

                    $balance = max(0, $invoice->total_cents - $invoice->amount_paid_cents);
                    $normalized = $this->billingCurrency->toBaseCents($balance, $rate);

                    if ($invoice->status === 'overdue') {
                        $overdue += $normalized;
                    } else {
                        $open += $normalized;
                    }
                }
            });

        return [
            'paid_cents' => $paid,
            'open_cents' => $open,
            'overdue_cents' => $overdue,
            'currency' => PlatformBillingCurrency::BASE,
        ];
    }

    /**
     * KPI-t e pagesave të muajit, GJITHMONË në cent EURO. Pagesa e trashëgon
     * monedhën nga fatura, ndaj normalizohet me kursin e ngrirë të asaj fature.
     *
     * @return array{month_cents:int, manual_cents:int, online_cents:int, currency:string}
     */
    public function paymentStatsInBase(): array
    {
        $month = 0;
        $manual = 0;
        $online = 0;

        BillingPayment::query()
            ->where('status', 'completed')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->with('invoice:id,fx_rate')
            ->select(['id', 'provider', 'amount_cents', 'billing_invoice_id'])
            ->chunkById(500, function ($payments) use (&$month, &$manual, &$online) {
                foreach ($payments as $payment) {
                    $rate = $payment->invoice?->fx_rate !== null
                        ? (float) $payment->invoice->fx_rate
                        : null;
                    $base = $this->billingCurrency->toBaseCents($payment->amount_cents, $rate);

                    $month += $base;

                    if ($payment->provider === 'manual') {
                        $manual += $base;
                    } else {
                        $online += $base;
                    }
                }
            });

        return [
            'month_cents' => $month,
            'manual_cents' => $manual,
            'online_cents' => $online,
            'currency' => PlatformBillingCurrency::BASE,
        ];
    }

    public function void(BillingInvoice $invoice): void
    {
        if ($invoice->amount_paid_cents > 0 || $invoice->status === 'paid') {
            throw ValidationException::withMessages(['invoice' => 'Fatura me pagesë nuk mund të anulohet.']);
        }

        $invoice->update(['status' => 'void']);
    }

    public function registerManualPayment(BillingInvoice $invoice, array $data, User $user): BillingPayment
    {
        return DB::transaction(function () use ($invoice, $data, $user) {
            /** @var BillingInvoice $locked */
            $locked = BillingInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($locked->status, ['open', 'overdue'], true)) {
                throw ValidationException::withMessages(['billing_invoice_id' => 'Pagesa lejohet vetëm për faturat Open ose Overdue.']);
            }

            $amountCents = (int) round(((float) $data['amount']) * 100);
            if ($amountCents < 1 || $amountCents > $locked->balance_cents) {
                throw ValidationException::withMessages(['amount' => 'Shuma duhet të jetë brenda bilancit të mbetur të faturës.']);
            }

            $payment = BillingPayment::query()->create([
                'tenant_id' => $locked->tenant_id,
                'billing_invoice_id' => $locked->id,
                'recorded_by' => $user->id,
                'provider' => 'manual',
                'method' => $data['method'],
                'status' => 'completed',
                'currency' => $locked->currency,
                'amount_cents' => $amountCents,
                'reference' => $data['reference'] ?? null,
                'paid_at' => $data['paid_at'] ?? now(),
                'metadata' => ['note' => $data['note'] ?? null],
            ]);
            $payment->forceFill(['number' => sprintf('PAY-%s-%05d', now()->format('Y'), $payment->id)])->save();

            $paid = $locked->amount_paid_cents + $amountCents;
            $fullyPaid = $paid >= $locked->total_cents;
            $locked->update([
                'amount_paid_cents' => $paid,
                'status' => $fullyPaid ? 'paid' : 'open',
                'paid_at' => $fullyPaid ? now() : null,
            ]);

            return $payment;
        });
    }

    public function markOverdue(): void
    {
        BillingInvoice::query()
            ->where('status', 'open')
            ->whereDate('due_on', '<', today())
            ->update(['status' => 'overdue']);
    }

    /**
     * Generate every cycle currently due, with a safety cap for stale subscriptions.
     *
     * @return array{created: Collection<int, BillingInvoice>, failed: int}
     */
    public function processDueSubscriptions(?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $created = collect();
        $failed = 0;

        $subscriptionIds = TenantSubscription::query()
            ->where('status', 'active')
            ->whereNotNull('next_billing_at')
            ->where('next_billing_at', '<=', $asOf)
            ->orderBy('next_billing_at')
            ->pluck('id');

        foreach ($subscriptionIds as $subscriptionId) {
            try {
                for ($cycle = 0; $cycle < 24; $cycle++) {
                    $invoice = $this->createNextRecurringInvoice((int) $subscriptionId, $asOf);

                    if (! $invoice) {
                        break;
                    }

                    $created->push($invoice);
                }
            } catch (\Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    private function createNextRecurringInvoice(int $subscriptionId, Carbon $asOf): ?BillingInvoice
    {
        return DB::transaction(function () use ($subscriptionId, $asOf) {
            /** @var TenantSubscription|null $subscription */
            $subscription = TenantSubscription::query()->lockForUpdate()->find($subscriptionId);

            if (! $subscription
                || $subscription->status !== 'active'
                || ! $subscription->next_billing_at
                || $subscription->next_billing_at->isAfter($asOf)) {
                return null;
            }

            $periodStartsOn = $subscription->next_billing_at->copy()->startOfDay();
            $nextBillingAt = $this->nextBillingDate($periodStartsOn, $subscription);
            $periodEndsOn = $nextBillingAt->copy()->subDay();
            $idempotencyKey = "subscription:{$subscription->id}:{$periodStartsOn->toDateString()}";

            $invoice = BillingInvoice::query()->where('idempotency_key', $idempotencyKey)->first();

            if (! $invoice) {
                $invoice = $this->createInvoice($subscription->tenant, [
                    'period_starts_on' => $periodStartsOn,
                    'period_ends_on' => $periodEndsOn,
                    'due_on' => $periodStartsOn->copy()->addDays(max(0, (int) config('lora.platform_billing_due_days', 14))),
                    'issue_now' => true,
                    'idempotency_key' => $idempotencyKey,
                    'source' => 'subscription_schedule',
                    'notes' => 'Faturë e krijuar automatikisht nga abonimi.',
                ]);
            }

            $subscription->update([
                'current_period_ends_at' => $periodEndsOn->copy()->endOfDay(),
                'next_billing_at' => $nextBillingAt,
                'last_billed_at' => $asOf,
            ]);

            $this->tenantContext->run($subscription->tenant, fn () => AuditLog::record(
                'platform.invoice.recurring',
                $invoice,
                [
                    'subscription_id' => $subscription->id,
                    'period_starts_on' => $periodStartsOn->toDateString(),
                    'period_ends_on' => $periodEndsOn->toDateString(),
                    'total_cents' => $invoice->total_cents,
                ],
                'system',
            ));

            return $invoice;
        }, 3);
    }

    private function nextBillingDate(Carbon $periodStartsOn, TenantSubscription $subscription): Carbon
    {
        $anchorDay = min(31, max(1, $subscription->billing_anchor_day));
        $next = $subscription->billing_cycle === 'annual'
            ? $periodStartsOn->copy()->startOfMonth()->addYear()
            : $periodStartsOn->copy()->startOfMonth()->addMonth();

        return $next->day(min($anchorDay, $next->daysInMonth))->startOfDay();
    }
}
