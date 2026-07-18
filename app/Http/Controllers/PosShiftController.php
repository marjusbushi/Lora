<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PosShift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosShiftController extends Controller
{
    /**
     * Open a cash-drawer shift for the current user (per-user model: at most one open).
     */
    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $userId = auth()->id();

        $shift = DB::transaction(function () use ($userId, $data) {
            // Lock this user's open shifts and re-check inside the transaction so a fast
            // double-tap / two tabs can't open two shifts at once.
            $existing = PosShift::where('user_id', $userId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return null;
            }

            return PosShift::create([
                'user_id' => $userId,
                'status' => 'open',
                'opening_float' => $data['opening_float'],
                'opened_at' => now(),
            ]);
        });

        if (! $shift) {
            return back()->with('error', 'Ke nje turn te hapur tashme.');
        }

        AuditLog::record('pos.shift.open', $shift, ['opening_float' => $data['opening_float']]);

        return back()->with('success', 'Turni u hap.');
    }

    /**
     * Close a shift: count the drawer (mandatory), freeze the Z-report totals, and seal it.
     * A user closes their OWN shift; a manager/admin with close_any_pos_shift can force-close.
     */
    public function close(Request $request, PosShift $posShift): RedirectResponse
    {
        if ($posShift->user_id !== auth()->id() && ! $request->user()->can('close_any_pos_shift')) {
            abort(403);
        }

        if ($posShift->status !== 'open') {
            return back()->with('error', 'Ky turn eshte tashme i mbyllur.');
        }

        $openOrders = $posShift->orders()->where('status', 'open')->count();
        if ($openOrders > 0) {
            AuditLog::record('pos.shift.close_blocked', $posShift, ['open_orders' => $openOrders]);

            return back()->with('error', "Mbyll ose anulo {$openOrders} porosi të hapura para mbylljes së turnit.");
        }

        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'closing_note' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($posShift, $data) {
            // Freeze the sales snapshot from this shift's completed orders.
            $posShift->computeTotals();

            $posShift->counted_cash = $data['counted_cash'];
            $posShift->over_short = round((float) $data['counted_cash'] - (float) $posShift->expected_cash, 2);
            $posShift->closing_note = $data['closing_note'] ?? null;
            $posShift->closed_at = now();
            $posShift->closed_by = auth()->id();
            $posShift->status = 'closed';
            $posShift->save();
        });

        AuditLog::record('pos.shift.close', $posShift, [
            'expected_cash' => $posShift->expected_cash,
            'counted_cash' => $posShift->counted_cash,
            'over_short' => $posShift->over_short,
            'cash_sales' => $posShift->cash_sales,
            'card_sales' => $posShift->card_sales,
            'room_charge_sales' => $posShift->room_charge_sales,
            'total_sales' => $posShift->total_sales,
            'total_orders' => $posShift->total_orders,
        ]);

        return back()->with('success', 'Turni u mbyll.');
    }
}
