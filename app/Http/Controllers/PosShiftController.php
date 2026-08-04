<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PosShift;
use App\Services\BaseCurrency;
use App\Services\CurrencyRates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PosShiftController extends Controller
{
    /**
     * Open a cash-drawer shift for the current user (per-user model: at most one open).
     */
    public function open(Request $request): RedirectResponse
    {
        $foreignPayable = collect(CurrencyRates::payable())
            ->pluck('code')
            ->reject(fn (string $code) => $code === BaseCurrency::code())
            ->values()
            ->all();

        $data = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'currencies' => ['nullable', 'array', 'max:10'],
            'currencies.*.currency' => ['required', 'string', Rule::in($foreignPayable), 'distinct'],
            'currencies.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
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

            $shift = PosShift::create([
                'user_id' => $userId,
                'status' => 'open',
                'opening_float' => $data['opening_float'],
                'opened_at' => now(),
            ]);

            // Declared foreign floats (a zero row adds nothing the close
            // screen wouldn't derive from the shift's payments anyway).
            foreach ($data['currencies'] ?? [] as $line) {
                if ((float) $line['amount'] > 0) {
                    $shift->currencies()->create([
                        'currency' => strtoupper($line['currency']),
                        'opening_amount' => round((float) $line['amount'], 2),
                    ]);
                }
            }

            return $shift;
        });

        if (! $shift) {
            return back()->with('error', 'Ke nje turn te hapur tashme.');
        }

        AuditLog::record('pos.shift.open', $shift, [
            'opening_float' => $data['opening_float'],
            'opening_currencies' => $shift->currencies->map(fn ($line) => [
                'currency' => $line->currency,
                'amount' => (float) $line->opening_amount,
            ])->values()->all(),
        ]);

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
            'counted_currencies' => ['nullable', 'array', 'max:10'],
            'counted_currencies.*.currency' => ['required', 'string', 'size:3', 'distinct'],
            'counted_currencies.*.counted' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        // Every foreign currency with a declared float or cash taken during the
        // shift must be counted at close — and only those may be submitted.
        $lines = collect($posShift->currencyLines());
        $counted = collect($data['counted_currencies'] ?? [])
            ->mapWithKeys(fn (array $line) => [strtoupper($line['currency']) => round((float) $line['counted'], 2)]);

        $missing = $lines->pluck('currency')->reject(fn (string $code) => $counted->has($code));
        if ($missing->isNotEmpty()) {
            return back()->with('error', 'Numëro edhe monedhat: '.$missing->join(', ').' para mbylljes së turnit.');
        }

        $unknown = $counted->keys()->diff($lines->pluck('currency'));
        if ($unknown->isNotEmpty()) {
            return back()->with('error', 'Ky turn nuk ka gjendje apo pagesa në: '.$unknown->join(', ').'.');
        }

        DB::transaction(function () use ($posShift, $data, $lines, $counted) {
            // Freeze each foreign currency's drawer line BEFORE the shift row
            // saves, so the finance observer sees the final variances.
            foreach ($lines as $line) {
                $expected = round($line['opening_amount'] + $line['cash_received'], 2);
                $posShift->currencies()->updateOrCreate(
                    ['currency' => $line['currency']],
                    [
                        'opening_amount' => $line['opening_amount'],
                        'expected_amount' => $expected,
                        'counted_amount' => $counted[$line['currency']],
                        'over_short' => round($counted[$line['currency']] - $expected, 2),
                    ],
                );
            }
            $posShift->unsetRelation('currencies');

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
            'currencies' => $posShift->currencies->map(fn ($line) => [
                'currency' => $line->currency,
                'expected' => (float) $line->expected_amount,
                'counted' => (float) $line->counted_amount,
                'over_short' => (float) $line->over_short,
            ])->values()->all(),
            'cash_sales' => $posShift->cash_sales,
            'card_sales' => $posShift->card_sales,
            'room_charge_sales' => $posShift->room_charge_sales,
            'total_sales' => $posShift->total_sales,
            'total_orders' => $posShift->total_orders,
        ]);

        return back()->with('success', 'Turni u mbyll.');
    }
}
