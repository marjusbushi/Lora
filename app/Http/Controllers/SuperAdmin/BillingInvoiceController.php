<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\Tenant;
use App\Services\PlatformBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillingInvoiceController extends Controller
{
    public function index(Request $request, PlatformBillingService $billing): Response
    {
        $billing->markOverdue();
        $status = $request->string('status')->toString();
        $tenantId = $request->integer('tenant_id');
        $query = BillingInvoice::query()
            ->with(['tenant:id,name', 'subscription:id,status,billing_cycle', 'lines', 'payments'])
            ->withCount('paymentAttempts')
            ->latest('id');

        if (in_array($status, ['draft', 'open', 'paid', 'overdue', 'void'], true)) {
            $query->where('status', $status);
        }

        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }

        return Inertia::render('SuperAdmin/Billing/Invoices', [
            'filters' => ['status' => $status, 'tenant_id' => $tenantId ?: null],
            'invoices' => $query->paginate(20)->withQueryString()->through(fn (BillingInvoice $invoice) => $this->invoiceData($invoice)),
            'tenants' => Tenant::query()->with('subscription')->orderBy('name')->get(['id', 'name', 'currency'])->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'currency' => $tenant->currency,
                'has_subscription' => (bool) $tenant->subscription,
            ]),
            'stats' => [
                'paid_cents' => BillingInvoice::query()->where('status', 'paid')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('total_cents'),
                'open_cents' => (int) BillingInvoice::query()->where('status', 'open')->selectRaw('COALESCE(SUM(total_cents - amount_paid_cents), 0) as aggregate')->value('aggregate'),
                'overdue_cents' => (int) BillingInvoice::query()->where('status', 'overdue')->selectRaw('COALESCE(SUM(total_cents - amount_paid_cents), 0) as aggregate')->value('aggregate'),
            ],
        ]);
    }

    public function show(BillingInvoice $invoice): Response
    {
        $invoice->load([
            'tenant:id,name,currency',
            'subscription:id,tenant_id,status,billing_cycle,next_billing_at,current_period_ends_at',
            'lines',
            'payments' => fn ($query) => $query->with('recorder:id,name')->latest('paid_at'),
            'paymentAttempts' => fn ($query) => $query->latest('id'),
            'providerEvents' => fn ($query) => $query->latest('id'),
        ]);

        return Inertia::render('SuperAdmin/Billing/InvoiceShow', [
            'invoice' => array_merge($this->invoiceData($invoice), [
                'subscription' => $invoice->subscription ? [
                    'id' => $invoice->subscription->id,
                    'status' => $invoice->subscription->status,
                    'billing_cycle' => $invoice->subscription->billing_cycle,
                    'next_billing_at' => $invoice->subscription->next_billing_at?->toIso8601String(),
                ] : null,
                'payments' => $invoice->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'number' => $payment->number,
                    'provider' => $payment->provider,
                    'method' => $payment->method,
                    'status' => $payment->status,
                    'amount_cents' => $payment->amount_cents,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'recorder' => $payment->recorder?->name,
                ]),
                'attempts' => $invoice->paymentAttempts->map(fn ($attempt) => [
                    'id' => $attempt->id,
                    'provider' => $attempt->provider,
                    'provider_attempt_id' => $attempt->provider_attempt_id,
                    'status' => $attempt->status,
                    'attempt_number' => $attempt->attempt_number,
                    'amount_cents' => $attempt->amount_cents,
                    'attempted_at' => $attempt->attempted_at?->toIso8601String(),
                    'failure_message' => $attempt->failure_message,
                ]),
                'events' => $invoice->providerEvents->map(fn ($event) => [
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

    public function bill(BillingInvoice $invoice): Response
    {
        $invoice->load([
            'tenant:id,name,currency',
            'subscription:id,tenant_id,status,billing_cycle,next_billing_at,current_period_ends_at',
            'lines',
            'payments' => fn ($query) => $query->latest('paid_at'),
        ]);

        return Inertia::render('SuperAdmin/Billing/BillShow', [
            'bill' => [
                'number' => str_replace('INV-', 'BILL-', $invoice->number),
                'invoice' => $this->invoiceData($invoice),
                'subscription' => $invoice->subscription ? [
                    'id' => $invoice->subscription->id,
                    'status' => $invoice->subscription->status,
                    'billing_cycle' => $invoice->subscription->billing_cycle,
                    'next_billing_at' => $invoice->subscription->next_billing_at?->toIso8601String(),
                ] : null,
                'payments' => $invoice->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'number' => $payment->number,
                    'status' => $payment->status,
                    'amount_cents' => $payment->amount_cents,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                ]),
            ],
        ]);
    }

    public function store(Request $request, PlatformBillingService $billing, TenantContext $context): RedirectResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', Rule::exists('tenants', 'id')->whereNull('deleted_at')],
            'period_starts_on' => ['required', 'date'],
            'period_ends_on' => ['nullable', 'date', 'after_or_equal:period_starts_on'],
            'due_on' => ['required', 'date', 'after_or_equal:period_starts_on'],
            'issue_now' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $tenant = Tenant::query()->findOrFail($data['tenant_id']);
        $invoice = $billing->createInvoice($tenant, $data);
        $context->run($tenant, fn () => AuditLog::record('platform.invoice.create', $invoice, [
            'invoice_number' => $invoice->number,
            'status' => $invoice->status,
            'total_cents' => $invoice->total_cents,
            'currency' => $invoice->currency,
        ]));

        return back()->with('success', "Fatura {$invoice->number} u krijua.");
    }

    public function publish(BillingInvoice $invoice, PlatformBillingService $billing, TenantContext $context): RedirectResponse
    {
        $billing->publish($invoice);
        $context->run($invoice->tenant, fn () => AuditLog::record('platform.invoice.publish', $invoice, [
            'invoice_number' => $invoice->number,
        ]));

        return back()->with('success', "Fatura {$invoice->number} u publikua.");
    }

    public function void(BillingInvoice $invoice, PlatformBillingService $billing, TenantContext $context): RedirectResponse
    {
        $billing->void($invoice);
        $context->run($invoice->tenant, fn () => AuditLog::record('platform.invoice.void', $invoice, [
            'invoice_number' => $invoice->number,
        ]));

        return back()->with('success', "Fatura {$invoice->number} u anulua.");
    }

    private function invoiceData(BillingInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'tenant' => ['id' => $invoice->tenant->id, 'name' => $invoice->tenant->name],
            'subscription_id' => $invoice->tenant_subscription_id,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'subtotal_cents' => $invoice->subtotal_cents,
            'discount_cents' => $invoice->discount_cents,
            'tax_cents' => $invoice->tax_cents,
            'total_cents' => $invoice->total_cents,
            'amount_paid_cents' => $invoice->amount_paid_cents,
            'balance_cents' => $invoice->balance_cents,
            'period_starts_on' => $invoice->period_starts_on?->toDateString(),
            'period_ends_on' => $invoice->period_ends_on?->toDateString(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_on' => $invoice->due_on?->toDateString(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'notes' => $invoice->notes,
            'source' => $invoice->metadata['source'] ?? 'manual',
            'payments_count' => $invoice->payments->count(),
            'attempts_count' => $invoice->payment_attempts_count ?? ($invoice->relationLoaded('paymentAttempts') ? $invoice->paymentAttempts->count() : 0),
            'lines' => $invoice->lines->map(fn ($line) => [
                'id' => $line->id,
                'module_code' => $line->module_code,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_amount_cents' => $line->unit_amount_cents,
                'amount_cents' => $line->amount_cents,
            ]),
        ];
    }
}
