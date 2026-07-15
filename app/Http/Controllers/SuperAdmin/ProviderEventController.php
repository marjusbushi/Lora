<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ProviderEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProviderEventController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SuperAdmin/Billing/ProviderEvents', [
            'events' => ProviderEvent::query()
                ->with('tenant:id,name')
                ->latest('id')
                ->paginate(25)
                ->through(fn (ProviderEvent $event) => [
                    'id' => $event->id,
                    'tenant_name' => $event->tenant?->name,
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
