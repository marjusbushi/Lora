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

            $linePayloads = collect($summary['modules'])
                ->filter(fn (array $module) => $module['enabled'] && $module['monthly_cents'] > 0)
                ->map(function (array $module) use ($annual) {
                    $amount = $annual ? $module['monthly_cents'] * 12 : $module['monthly_cents'];

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

            $invoice = BillingInvoice::query()->create([
                'tenant_id' => $tenant->id,
                'tenant_subscription_id' => $tenant->subscription?->id,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'status' => ($data['issue_now'] ?? false) ? 'open' : 'draft',
                'currency' => $summary['currency'],
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

        $invoice->update(['status' => 'open', 'issued_at' => now()]);
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
