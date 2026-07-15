<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Tenant;
use App\Services\PlatformBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillingPaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantId = $request->integer('tenant_id');
        $invoiceId = $request->integer('invoice_id');
        $status = $request->string('status')->toString();
        $query = BillingPayment::query()
            ->with(['tenant:id,name', 'invoice:id,number,total_cents,amount_paid_cents,currency'])
            ->latest('paid_at');

        $query->when($tenantId > 0, fn ($builder) => $builder->where('tenant_id', $tenantId));
        $query->when($invoiceId > 0, fn ($builder) => $builder->where('billing_invoice_id', $invoiceId));
        $query->when(in_array($status, ['pending', 'completed', 'failed', 'refunded'], true), fn ($builder) => $builder->where('status', $status));

        return Inertia::render('SuperAdmin/Billing/Payments', [
            'filters' => ['tenant_id' => $tenantId ?: null, 'invoice_id' => $invoiceId ?: null, 'status' => $status],
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']),
            'payments' => $query->paginate(20)->withQueryString()
                ->through(fn (BillingPayment $payment) => [
                    'id' => $payment->id,
                    'number' => $payment->number,
                    'tenant' => ['id' => $payment->tenant->id, 'name' => $payment->tenant->name],
                    'invoice' => $payment->invoice ? ['id' => $payment->invoice->id, 'number' => $payment->invoice->number] : null,
                    'provider' => $payment->provider,
                    'method' => $payment->method,
                    'status' => $payment->status,
                    'currency' => $payment->currency,
                    'amount_cents' => $payment->amount_cents,
                    'reference' => $payment->reference,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                ]),
            'openInvoices' => BillingInvoice::query()
                ->with('tenant:id,name')
                ->whereIn('status', ['open', 'overdue'])
                ->orderBy('due_on')
                ->get()
                ->map(fn (BillingInvoice $invoice) => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'tenant_name' => $invoice->tenant->name,
                    'currency' => $invoice->currency,
                    'balance_cents' => $invoice->balance_cents,
                ]),
            'stats' => [
                'month_cents' => BillingPayment::query()->where('status', 'completed')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount_cents'),
                'manual_cents' => BillingPayment::query()->where('status', 'completed')->where('provider', 'manual')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount_cents'),
                'online_cents' => BillingPayment::query()->where('status', 'completed')->where('provider', '!=', 'manual')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount_cents'),
            ],
        ]);
    }

    public function show(BillingPayment $payment): Response
    {
        $payment->load([
            'tenant.subscription',
            'invoice.lines',
            'recorder:id,name,email',
            'attempts' => fn ($query) => $query->latest('id'),
            'providerEvents' => fn ($query) => $query->latest('id'),
        ]);

        return Inertia::render('SuperAdmin/Billing/PaymentShow', [
            'payment' => [
                'id' => $payment->id,
                'number' => $payment->number,
                'tenant' => ['id' => $payment->tenant->id, 'name' => $payment->tenant->name],
                'subscription' => $payment->tenant->subscription ? [
                    'id' => $payment->tenant->subscription->id,
                    'status' => $payment->tenant->subscription->status,
                    'billing_cycle' => $payment->tenant->subscription->billing_cycle,
                ] : null,
                'invoice' => $payment->invoice ? [
                    'id' => $payment->invoice->id,
                    'number' => $payment->invoice->number,
                    'status' => $payment->invoice->status,
                    'total_cents' => $payment->invoice->total_cents,
                    'balance_cents' => $payment->invoice->balance_cents,
                ] : null,
                'provider' => $payment->provider,
                'provider_payment_id' => $payment->provider_payment_id,
                'method' => $payment->method,
                'status' => $payment->status,
                'currency' => $payment->currency,
                'amount_cents' => $payment->amount_cents,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'recorder' => $payment->recorder ? ['name' => $payment->recorder->name, 'email' => $payment->recorder->email] : null,
                'metadata' => $payment->metadata,
                'attempts' => $payment->attempts->map(fn ($attempt) => [
                    'id' => $attempt->id,
                    'provider' => $attempt->provider,
                    'status' => $attempt->status,
                    'attempt_number' => $attempt->attempt_number,
                    'attempted_at' => $attempt->attempted_at?->toIso8601String(),
                ]),
                'events' => $payment->providerEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'provider' => $event->provider,
                    'event_type' => $event->event_type,
                    'status' => $event->status,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ]),
            ],
        ]);
    }

    public function store(Request $request, PlatformBillingService $billing, TenantContext $context): RedirectResponse
    {
        $data = $request->validate([
            'billing_invoice_id' => ['required', Rule::exists('billing_invoices', 'id')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['bank_transfer', 'cash', 'other'])],
            'reference' => ['nullable', 'string', 'max:191'],
            'paid_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $billing->registerManualPayment(
            BillingInvoice::query()->findOrFail($data['billing_invoice_id']),
            $data,
            $request->user(),
        );
        $context->run($payment->tenant, fn () => AuditLog::record('platform.payment.record', $payment, [
            'payment_number' => $payment->number,
            'invoice_id' => $payment->billing_invoice_id,
            'amount_cents' => $payment->amount_cents,
            'currency' => $payment->currency,
            'method' => $payment->method,
        ]));

        return back()->with('success', "Pagesa {$payment->number} u regjistrua.");
    }
}
