<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\BillingPaymentAttempt;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingPaymentAttemptController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantId = $request->integer('tenant_id');
        $invoiceId = $request->integer('invoice_id');
        $paymentId = $request->integer('payment_id');
        $status = $request->string('status')->toString();
        $query = BillingPaymentAttempt::query()
            ->with(['tenant:id,name', 'subscription:id,status,billing_cycle', 'invoice:id,number', 'payment:id,number'])
            ->withCount('providerEvents')
            ->latest('id');

        $query->when($tenantId > 0, fn ($builder) => $builder->where('tenant_id', $tenantId));
        $query->when($invoiceId > 0, fn ($builder) => $builder->where('billing_invoice_id', $invoiceId));
        $query->when($paymentId > 0, fn ($builder) => $builder->where('billing_payment_id', $paymentId));
        $query->when(in_array($status, ['pending', 'processing', 'requires_action', 'succeeded', 'failed', 'canceled'], true), fn ($builder) => $builder->where('status', $status));

        return Inertia::render('SuperAdmin/Billing/PaymentAttempts', [
            'filters' => [
                'tenant_id' => $tenantId ?: null,
                'invoice_id' => $invoiceId ?: null,
                'payment_id' => $paymentId ?: null,
                'status' => $status,
            ],
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']),
            'attempts' => $query->paginate(25)->withQueryString()->through(fn (BillingPaymentAttempt $attempt) => $this->data($attempt)),
            'stats' => [
                'pending' => BillingPaymentAttempt::query()->whereIn('status', ['pending', 'processing', 'requires_action'])->count(),
                'succeeded' => BillingPaymentAttempt::query()->where('status', 'succeeded')->count(),
                'failed' => BillingPaymentAttempt::query()->where('status', 'failed')->count(),
            ],
        ]);
    }

    public function show(BillingPaymentAttempt $paymentAttempt): Response
    {
        $paymentAttempt->load([
            'tenant.subscription',
            'subscription',
            'invoice',
            'payment',
            'providerEvents' => fn ($query) => $query->latest('id'),
        ]);

        return Inertia::render('SuperAdmin/Billing/PaymentAttemptShow', [
            'attempt' => array_merge($this->data($paymentAttempt), [
                'failure_code' => $paymentAttempt->failure_code,
                'failure_message' => $paymentAttempt->failure_message,
                'resolved_at' => $paymentAttempt->resolved_at?->toIso8601String(),
                'metadata' => $paymentAttempt->metadata,
                'events' => $paymentAttempt->providerEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'provider' => $event->provider,
                    'external_id' => $event->external_id,
                    'event_type' => $event->event_type,
                    'status' => $event->status,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ]),
            ]),
        ]);
    }

    private function data(BillingPaymentAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'tenant' => ['id' => $attempt->tenant->id, 'name' => $attempt->tenant->name],
            'subscription_id' => $attempt->tenant_subscription_id,
            'invoice' => $attempt->invoice ? ['id' => $attempt->invoice->id, 'number' => $attempt->invoice->number] : null,
            'payment' => $attempt->payment ? ['id' => $attempt->payment->id, 'number' => $attempt->payment->number] : null,
            'provider' => $attempt->provider,
            'provider_attempt_id' => $attempt->provider_attempt_id,
            'status' => $attempt->status,
            'currency' => $attempt->currency,
            'amount_cents' => $attempt->amount_cents,
            'attempt_number' => $attempt->attempt_number,
            'attempted_at' => $attempt->attempted_at?->toIso8601String(),
            'events_count' => $attempt->provider_events_count ?? $attempt->providerEvents->count(),
        ];
    }
}
