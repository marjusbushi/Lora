<?php

namespace App\Http\Controllers;

use App\Jobs\PushRoomTypeAri;
use App\Jobs\ReconcileOtaSellWindow;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\ChannexClient;
use App\Services\OtaSellWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChannexController extends Controller
{
    /**
     * Manual "Sync now": queue a full availability + rate push for every
     * Channex-mapped room type. The actual pushes run on the queue worker.
     */
    public function sync(OtaSellWindow $sellWindow, ChannexClient $channex): RedirectResponse
    {
        if ($sellWindow->configuredUntil()) {
            $count = $sellWindow->summary()['room_type_count'];
            if (! $channex->configured() || $count === 0) {
                return back()->with('error', 'Channex nuk eshte konfiguruar ose s\'ka dhoma te lidhura me kanalin.');
            }

            ReconcileOtaSellWindow::dispatch(
                $sellWindow->version(),
                $sellWindow->effectiveUntil()->toDateString(),
            );

            return back()->with('success', "Rikontrolli i kufirit OTA u nis per {$count} tipe dhomash.");
        }

        $count = PushRoomTypeAri::dispatchAllMapped();

        if ($count === 0) {
            return back()->with('error', 'Channex nuk eshte konfiguruar ose s\'ka dhoma te lidhura me kanalin.');
        }

        return back()->with('success', "Sinkronizimi me Channex u nis per {$count} tipe dhomash.");
    }

    /** Read-only impact preview; the route is mounted inside the admin group. */
    public function previewSellWindow(Request $request, OtaSellWindow $sellWindow): JsonResponse
    {
        $data = $request->validate([
            'sell_until_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$sellWindow->today()->toDateString(),
                'before_or_equal:'.$sellWindow->maxUntil()->toDateString(),
            ],
            'expected_version' => ['required', 'integer', 'min:0'],
        ]);

        if ((int) $data['expected_version'] !== $sellWindow->version()) {
            return response()->json([
                'message' => 'Ky konfigurim u ndryshua. Rifresko faqen para kontrollit paraprak.',
                'version' => $sellWindow->version(),
            ], 409);
        }

        return response()->json($this->sellWindowPreview(
            $sellWindow,
            CarbonImmutable::createFromFormat('!Y-m-d', $data['sell_until_date']),
        ));
    }

    /**
     * Persist an explicitly confirmed cutoff and queue reconciliation only
     * after the strict audit + setting transaction has committed.
     */
    public function updateSellWindow(Request $request, OtaSellWindow $sellWindow): JsonResponse
    {
        $data = $request->validate([
            'sell_until_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$sellWindow->today()->toDateString(),
                'before_or_equal:'.$sellWindow->maxUntil()->toDateString(),
            ],
            'confirmed' => ['required', 'accepted'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ]);

        $requested = CarbonImmutable::createFromFormat('!Y-m-d', $data['sell_until_date']);
        $result = $sellWindow->withAriLock(function () use ($data, $requested, $sellWindow) {
            return DB::transaction(function () use ($data, $requested, $sellWindow) {
                $versionRow = $sellWindow->lockVersion();
                $currentVersion = max(0, (int) $versionRow->value);
                if ($currentVersion !== (int) $data['expected_version']) {
                    return ['state' => 'conflict', 'version' => $currentVersion];
                }

                $configured = $sellWindow->configuredUntil();
                $current = $configured ?? $sellWindow->defaultUntil();
                if ($configured?->isSameDay($requested)) {
                    $applied = $sellWindow->appliedUntil();

                    return [
                        'state' => $applied?->isSameDay($requested) ? 'unchanged' : 'retry',
                        'version' => $currentVersion,
                    ];
                }

                // Capture the old legacy/applied horizon before replacing the
                // effective setting; reconciliation must explicitly close it.
                $knownHorizon = $sellWindow->knownHorizon()->max($requested);
                $isPin = $current->isSameDay($requested);
                $nextVersion = $currentVersion + 1;
                Setting::set(OtaSellWindow::SELL_UNTIL_KEY, $requested->toDateString());
                Setting::set(OtaSellWindow::MAX_PUBLISHED_KEY, $knownHorizon->toDateString());
                // A date alone cannot identify which revision was fully
                // reconciled. Clear the marker until Channex readback verifies
                // availability and rates, including a rolling->fixed pin.
                Setting::query()
                    ->where('group', 'channex')
                    ->where('key', 'sell_window_applied_until')
                    ->delete();
                $versionRow->update(['value' => (string) $nextVersion, 'type' => 'number']);

                // Strict audit: create(), not the best-effort record() helper.
                // Any audit failure rolls the setting and revision back too.
                AuditLog::create([
                    'causer_id' => auth()->id(),
                    'action' => 'channex.sell_window_update',
                    'subject_type' => null,
                    'subject_id' => null,
                    'properties' => [
                        'old_until' => $current->toDateString(),
                        'sell_until_date' => $requested->toDateString(),
                        'old_version' => $currentVersion,
                        'version' => $nextVersion,
                        'known_horizon' => $knownHorizon->toDateString(),
                        'mode_change_only' => $isPin,
                    ],
                    'created_at' => now(),
                ]);

                return [
                    'state' => 'changed',
                    'version' => $nextVersion,
                ];
            }, 3);
        });

        if ($result['state'] === 'conflict') {
            return response()->json([
                'message' => 'Ky konfigurim u ndryshua nga një veprim tjetër. Rifresko faqen dhe provo përsëri.',
                'version' => $result['version'],
            ], 409);
        }

        if ($result['state'] === 'unchanged') {
            return response()->json([
                'status' => 'unchanged',
                'queued' => false,
                'sell_window' => $sellWindow->summary(),
            ]);
        }

        // The DB transaction has committed before dispatch. A same-date retry
        // also reaches this path when a prior reconciliation is still pending.
        ReconcileOtaSellWindow::dispatch($result['version'], $requested->toDateString());

        return response()->json([
            'status' => 'queued',
            'queued' => true,
            'sell_window' => $sellWindow->summary(),
        ]);
    }

    /**
     * @return array{current_until:string,requested_until:string,action:string,range_from:?string,range_to:?string,nights:int,room_type_count:int,version:int}
     */
    private function sellWindowPreview(OtaSellWindow $sellWindow, CarbonImmutable $requested): array
    {
        $configured = $sellWindow->configuredUntil();
        $current = $configured ?? $sellWindow->defaultUntil();
        $action = $requested->gt($current)
            ? 'extend'
            : ($requested->lt($current)
                ? 'shorten'
                : ($configured === null ? 'pin' : 'unchanged'));

        return [
            'current_until' => $current->toDateString(),
            'requested_until' => $requested->toDateString(),
            'action' => $action,
            'range_from' => match ($action) {
                'extend' => $current->addDay()->toDateString(),
                'shorten' => $requested->addDay()->toDateString(),
                default => null,
            },
            'range_to' => match ($action) {
                'extend' => $requested->toDateString(),
                'shorten' => $current->toDateString(),
                default => null,
            },
            'nights' => abs((int) $current->diffInDays($requested, false)),
            'room_type_count' => $sellWindow->summary()['room_type_count'],
            'version' => $sellWindow->version(),
        ];
    }
}
