<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BeachShift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BeachShiftController extends Controller
{
    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $userId = auth()->id();

        $shift = DB::transaction(function () use ($userId, $data) {
            // Kyç turnet e hapura të userit + re-check brenda transaksionit —
            // dy klikime të shpejta / dy tab-e s'hapin dot dy turne.
            $existing = BeachShift::where('user_id', $userId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return null;
            }

            return BeachShift::create([
                'user_id' => $userId,
                'status' => 'open',
                'opening_float' => $data['opening_float'],
                'opened_at' => now(),
            ]);
        });

        if (! $shift) {
            return back()->with('error', 'Ke një turn plazhi të hapur tashmë.');
        }

        AuditLog::record('beach.shift.open', $shift, ['opening_float' => $data['opening_float']]);

        return back()->with('success', 'Turni i plazhit u hap.');
    }

    /** Mbyllja: numëro sirtarin (i detyrueshëm), ngri Z-raportin, vulose. */
    public function close(Request $request, BeachShift $beachShift): RedirectResponse
    {
        if ($beachShift->user_id !== auth()->id() && ! $request->user()->can('close_any_beach_shift')) {
            abort(403);
        }

        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'closing_note' => ['nullable', 'string', 'max:500'],
        ]);

        // Kyçja mbi rreshtin e turnit e serializon mbylljen me markPaid — asnjë pagesë
        // s'futet dot MES llogaritjes së totaleve dhe ngrirjes së Z-raportit.
        $closed = DB::transaction(function () use ($beachShift, $data) {
            $shift = BeachShift::whereKey($beachShift->id)->lockForUpdate()->first();

            if ($shift->status !== 'open') {
                return null;
            }

            $shift->computeTotals();
            $shift->counted_cash = round((float) $data['counted_cash'], 2);
            $shift->over_short = round((float) $shift->counted_cash - (float) $shift->expected_cash, 2);
            $shift->closing_note = $data['closing_note'] ?? null;
            $shift->status = 'closed';
            $shift->closed_at = now();
            $shift->closed_by = auth()->id();
            $shift->save();

            return $shift;
        });

        if (! $closed) {
            return back()->with('error', 'Ky turn është tashmë i mbyllur.');
        }

        $beachShift = $closed;

        AuditLog::record('beach.shift.close', $beachShift, [
            'expected_cash' => (float) $beachShift->expected_cash,
            'counted_cash' => (float) $beachShift->counted_cash,
            'over_short' => (float) $beachShift->over_short,
        ]);

        return back()->with('success', 'Turni i plazhit u mbyll.');
    }
}
