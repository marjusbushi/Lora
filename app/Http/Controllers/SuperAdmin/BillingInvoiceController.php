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
        $query = BillingInvoice::query()->with(['tenant:id,name', 'lines', 'payments'])->latest('id');

        if (in_array($status, ['draft', 'open', 'paid', 'overdue', 'void'], true)) {
            $query->where('status', $status);
        }

        return Inertia::render('SuperAdmin/Billing/Invoices', [
            'filters' => ['status' => $status],
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
