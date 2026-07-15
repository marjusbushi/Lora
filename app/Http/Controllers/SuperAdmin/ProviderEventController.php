<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ProviderEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProviderEventController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantId = $request->integer('tenant_id');
        $attemptId = $request->integer('attempt_id');
        $invoiceId = $request->integer('invoice_id');
        $paymentId = $request->integer('payment_id');
        $query = ProviderEvent::query()
            ->with(['tenant:id,name', 'paymentAttempt:id,provider_attempt_id', 'invoice:id,number', 'payment:id,number'])
            ->latest('id');
        $query->when($tenantId > 0, fn ($builder) => $builder->where('tenant_id', $tenantId));
        $query->when($attemptId > 0, fn ($builder) => $builder->where('billing_payment_attempt_id', $attemptId));
        $query->when($invoiceId > 0, fn ($builder) => $builder->where('billing_invoice_id', $invoiceId));
        $query->when($paymentId > 0, fn ($builder) => $builder->where('billing_payment_id', $paymentId));

        return Inertia::render('SuperAdmin/Billing/ProviderEvents', [
            'filters' => ['tenant_id' => $tenantId ?: null, 'attempt_id' => $attemptId ?: null, 'invoice_id' => $invoiceId ?: null, 'payment_id' => $paymentId ?: null],
            'events' => $query->paginate(25)->withQueryString()
                ->through(fn (ProviderEvent $event) => [
                    'id' => $event->id,
                    'tenant' => $event->tenant ? ['id' => $event->tenant->id, 'name' => $event->tenant->name] : null,
                    'attempt' => $event->paymentAttempt ? ['id' => $event->paymentAttempt->id, 'provider_attempt_id' => $event->paymentAttempt->provider_attempt_id] : null,
                    'invoice' => $event->invoice ? ['id' => $event->invoice->id, 'number' => $event->invoice->number] : null,
                    'payment' => $event->payment ? ['id' => $event->payment->id, 'number' => $event->payment->number] : null,
                    'provider' => $event->provider,
                    'external_id' => $event->external_id,
                    'event_type' => $event->event_type,
                    'status' => $event->status,
                    'attempt_count' => $event->attempt_count,
                    'last_error' => $event->last_error,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                    'processed_at' => $event->processed_at?->toIso8601String(),
                ]),
            'stats' => [
                'processed' => ProviderEvent::query()->where('status', 'processed')->count(),
                'failed' => ProviderEvent::query()->where('status', 'failed')->count(),
                'duplicates' => ProviderEvent::query()->where('status', 'duplicate')->count(),
            ],
        ]);
    }

    public function show(ProviderEvent $providerEvent): Response
    {
        $providerEvent->load(['tenant.subscription', 'paymentAttempt', 'invoice', 'payment']);

        return Inertia::render('SuperAdmin/Billing/ProviderEventShow', [
            'event' => [
                'id' => $providerEvent->id,
                'tenant' => $providerEvent->tenant ? ['id' => $providerEvent->tenant->id, 'name' => $providerEvent->tenant->name] : null,
                'subscription_id' => $providerEvent->tenant?->subscription?->id,
                'attempt' => $providerEvent->paymentAttempt ? ['id' => $providerEvent->paymentAttempt->id, 'provider_attempt_id' => $providerEvent->paymentAttempt->provider_attempt_id] : null,
                'invoice' => $providerEvent->invoice ? ['id' => $providerEvent->invoice->id, 'number' => $providerEvent->invoice->number] : null,
                'payment' => $providerEvent->payment ? ['id' => $providerEvent->payment->id, 'number' => $providerEvent->payment->number] : null,
                'provider' => $providerEvent->provider,
                'external_id' => $providerEvent->external_id,
                'event_type' => $providerEvent->event_type,
                'status' => $providerEvent->status,
                'attempt_count' => $providerEvent->attempt_count,
                'last_error' => $providerEvent->last_error,
                'payload' => $providerEvent->payload,
                'occurred_at' => $providerEvent->occurred_at?->toIso8601String(),
                'processed_at' => $providerEvent->processed_at?->toIso8601String(),
            ],
        ]);
    }

    public function retry(ProviderEvent $providerEvent): RedirectResponse
    {
        if ($providerEvent->status !== 'failed') {
            throw ValidationException::withMessages(['event' => 'Vetëm eventet Failed mund të vendosen për retry.']);
        }

        $providerEvent->update([
            'status' => 'pending',
            'attempt_count' => $providerEvent->attempt_count + 1,
            'last_error' => null,
        ]);

        return back()->with('success', 'Eventi u vendos në radhë për retry.');
    }
}
