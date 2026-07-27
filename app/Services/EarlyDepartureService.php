<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CleaningTask;
use App\Models\FiscalDocument;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Setting;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EarlyDepartureService
{
    /**
     * @param  array{
     *     departure_date:string,
     *     policy:string,
     *     penalty_amount?:float|int|string|null,
     *     reason:string,
     *     settle_method?:string|null,
     *     refund_method?:string|null
     * }  $data
     * @return array{mode:string,room_number:?string,adjusted_room_total:float,payment:float,refund:float}
     */
    public function handle(Reservation $reservation, array $data, ?int $userId): array
    {
        return DB::transaction(function () use ($reservation, $data, $userId) {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $context = $this->context($locked, $data['departure_date']);

            if (FiscalDocument::query()
                ->where('reservation_id', $locked->id)
                ->where('status', FiscalDocument::STATUS_FISCALIZED)
                ->exists()) {
                throw ValidationException::withMessages([
                    'departure_date' => 'Rezervimi ka faturë të fiskalizuar dhe nuk mund të ndryshohet pa dokument korrigjues.',
                ]);
            }

            $amounts = $this->amounts($locked, $data, $context);
            if ($context['departure']->gt($context['today'])) {
                return $this->schedule($locked, $data, $context, $amounts, $userId);
            }

            return $this->complete($locked, $data, $context, $amounts, $userId);
        });
    }

    /** @return array{room_number:?string} */
    public function cancelPlan(Reservation $reservation, ?int $userId): array
    {
        return DB::transaction(function () use ($reservation, $userId) {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->status !== 'checked_in'
                || ! $locked->early_departure_scheduled_at
                || $locked->early_departure_at
                || ! $locked->original_check_out_date
                || $locked->early_departure_original_room_total === null) {
                throw ValidationException::withMessages([
                    'departure_date' => 'Ky rezervim nuk ka një largim të parakohshëm të planifikuar.',
                ]);
            }

            if (FiscalDocument::query()
                ->where('reservation_id', $locked->id)
                ->where('status', FiscalDocument::STATUS_FISCALIZED)
                ->exists()) {
                throw ValidationException::withMessages([
                    'departure_date' => 'Plani nuk mund të anulohet pasi fatura është fiskalizuar.',
                ]);
            }

            $plannedCheckOut = $locked->check_out_date->toDateString();
            $originalCheckOut = $locked->original_check_out_date->toDateString();
            $originalRoomTotal = round((float) $locked->early_departure_original_room_total, 2);

            $locked->update([
                'check_out_date' => $originalCheckOut,
                'total_amount' => $originalRoomTotal,
                'commission_amount' => $this->channelCommission($locked, $originalRoomTotal),
                'original_check_out_date' => null,
                'early_departure_original_room_total' => null,
                'early_departure_scheduled_at' => null,
                'early_departure_scheduled_by' => null,
                'early_departure_policy' => null,
                'early_departure_penalty_amount' => null,
                'early_departure_reason' => null,
            ]);

            AuditLog::record('reservation.early_departure_plan_cancelled', $locked, [
                'planned_check_out' => $plannedCheckOut,
                'restored_check_out' => $originalCheckOut,
                'restored_room_total' => $originalRoomTotal,
                'cancelled_by' => $userId,
            ]);

            return [
                'room_number' => Room::query()->whereKey($locked->room_id)->value('room_number'),
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array{departure:CarbonImmutable,check_in:CarbonImmutable,contractual_check_out:CarbonImmutable,today:CarbonImmutable,original_room_total:float}  $context
     * @param  array{policy:string,penalty:float,adjusted_room_total:float}  $amounts
     * @return array{mode:string,room_number:?string,adjusted_room_total:float,payment:float,refund:float}
     */
    private function schedule(
        Reservation $locked,
        array $data,
        array $context,
        array $amounts,
        ?int $userId,
    ): array {
        $locked->update([
            'original_check_out_date' => $context['contractual_check_out']->toDateString(),
            'early_departure_original_room_total' => $context['original_room_total'],
            'check_out_date' => $context['departure']->toDateString(),
            'total_amount' => $amounts['adjusted_room_total'],
            'commission_amount' => $this->channelCommission($locked, $amounts['adjusted_room_total']),
            'early_departure_scheduled_at' => now(),
            'early_departure_scheduled_by' => $userId,
            'early_departure_at' => null,
            'early_departure_by' => null,
            'early_departure_policy' => $amounts['policy'],
            'early_departure_penalty_amount' => $amounts['penalty'],
            'early_departure_reason' => trim((string) $data['reason']),
        ]);

        AuditLog::record('reservation.early_departure_scheduled', $locked, [
            'original_check_out' => $context['contractual_check_out']->toDateString(),
            'planned_check_out' => $context['departure']->toDateString(),
            'original_room_total' => $context['original_room_total'],
            'adjusted_room_total' => $amounts['adjusted_room_total'],
            'policy' => $amounts['policy'],
            'penalty_amount' => $amounts['penalty'],
            'reason' => trim((string) $data['reason']),
            'inventory_released' => true,
        ]);

        return [
            'mode' => 'scheduled',
            'room_number' => Room::query()->whereKey($locked->room_id)->value('room_number'),
            'adjusted_room_total' => $amounts['adjusted_room_total'],
            'payment' => 0.0,
            'refund' => 0.0,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array{departure:CarbonImmutable,check_in:CarbonImmutable,contractual_check_out:CarbonImmutable,today:CarbonImmutable,original_room_total:float}  $context
     * @param  array{policy:string,penalty:float,adjusted_room_total:float}  $amounts
     * @return array{mode:string,room_number:?string,adjusted_room_total:float,payment:float,refund:float}
     */
    private function complete(
        Reservation $locked,
        array $data,
        array $context,
        array $amounts,
        ?int $userId,
    ): array {
        if (PosOrder::query()
            ->where('reservation_id', $locked->id)
            ->where('status', 'open')
            ->lockForUpdate()
            ->exists()) {
            throw ValidationException::withMessages([
                'departure_date' => 'Ka porosi POS të hapura. Mbylli përpara largimit.',
            ]);
        }

        $locked->loadMissing(['folioItems', 'payments']);
        $current = ReservationMoney::totals($locked);
        $adjustedGross = round($amounts['adjusted_room_total'] + $current['charges'] - $current['discounts'], 2);
        $balance = round($adjustedGross - $current['paid'], 2);
        $payment = $balance > 0.005 ? $balance : 0.0;
        $refund = $balance < -0.005 ? abs($balance) : 0.0;

        if ($payment > 0 && empty($data['settle_method'])) {
            throw ValidationException::withMessages([
                'settle_method' => 'Mbeten '.number_format($payment, 2).' '.ReservationMoney::currency($locked)
                    .' për t’u paguar. Zgjidh mënyrën e pagesës.',
            ]);
        }
        if ($refund > 0 && empty($data['refund_method'])) {
            throw ValidationException::withMessages([
                'refund_method' => 'Duhet të rimbursohen '.number_format($refund, 2).' '.ReservationMoney::currency($locked)
                    .'. Zgjidh mënyrën e rimbursimit.',
            ]);
        }

        $room = Room::query()->lockForUpdate()->find($locked->room_id);
        $currency = ReservationMoney::currency($locked);
        $exchangeRate = ReservationMoney::exchangeRate($locked);

        $locked->update([
            'original_check_out_date' => $context['contractual_check_out']->toDateString(),
            'early_departure_original_room_total' => $context['original_room_total'],
            'check_out_date' => $context['departure']->toDateString(),
            'status' => 'checked_out',
            'total_amount' => $amounts['adjusted_room_total'],
            'commission_amount' => $this->channelCommission($locked, $amounts['adjusted_room_total']),
            'early_departure_at' => now(),
            'early_departure_by' => $userId,
            'early_departure_policy' => $amounts['policy'],
            'early_departure_penalty_amount' => $amounts['penalty'],
            'early_departure_reason' => trim((string) $data['reason']),
        ]);

        if ($payment > 0) {
            $locked->payments()->create([
                'amount' => $payment,
                'method' => $data['settle_method'],
                'type' => 'payment',
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'created_by' => $userId,
            ]);
            AuditLog::record('payment.record', $locked, [
                'amount' => $payment,
                'method' => $data['settle_method'],
                'context' => 'early_departure_settle',
            ]);
        }

        if ($refund > 0) {
            $locked->payments()->create([
                'amount' => $refund,
                'method' => $data['refund_method'],
                'type' => 'refund',
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'created_by' => $userId,
            ]);
            AuditLog::record('payment.refund', $locked, [
                'amount' => $refund,
                'method' => $data['refund_method'],
                'context' => 'early_departure_refund',
            ]);
        }

        AuditLog::record('reservation.early_departure', $locked, [
            'scheduled_check_out' => $context['contractual_check_out']->toDateString(),
            'actual_check_out' => $context['departure']->toDateString(),
            'original_room_total' => $context['original_room_total'],
            'adjusted_room_total' => $amounts['adjusted_room_total'],
            'policy' => $amounts['policy'],
            'penalty_amount' => $amounts['penalty'],
            'reason' => trim((string) $data['reason']),
        ]);

        $room?->update(['status' => 'cleaning']);
        $this->createCleaningTask($locked, $room);

        return [
            'mode' => 'completed',
            'room_number' => $room?->room_number,
            'adjusted_room_total' => $amounts['adjusted_room_total'],
            'payment' => $payment,
            'refund' => $refund,
        ];
    }

    /**
     * @return array{departure:CarbonImmutable,check_in:CarbonImmutable,contractual_check_out:CarbonImmutable,today:CarbonImmutable,original_room_total:float}
     */
    private function context(Reservation $reservation, string $departureDate): array
    {
        if ($reservation->status !== 'checked_in') {
            throw ValidationException::withMessages([
                'departure_date' => 'Largimi i parakohshëm lejohet vetëm për një mysafir që është në hotel.',
            ]);
        }

        $departure = CarbonImmutable::parse($departureDate)->startOfDay();
        $checkIn = CarbonImmutable::parse($reservation->check_in_date)->startOfDay();
        $contractualCheckOut = CarbonImmutable::parse(
            $reservation->original_check_out_date ?: $reservation->check_out_date
        )->startOfDay();
        $timezone = app(TenantContext::class)->tenant()?->timezone ?: config('app.timezone');
        $today = CarbonImmutable::today($timezone);

        if ($departure->lte($checkIn) || $departure->gte($contractualCheckOut)) {
            throw ValidationException::withMessages([
                'departure_date' => 'Data duhet të jetë pas check-in dhe para check-out-it fillestar.',
            ]);
        }

        return [
            'departure' => $departure,
            'check_in' => $checkIn,
            'contractual_check_out' => $contractualCheckOut,
            'today' => $today,
            'original_room_total' => round((float) (
                $reservation->early_departure_original_room_total ?? $reservation->total_amount
            ), 2),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array{departure:CarbonImmutable,check_in:CarbonImmutable,contractual_check_out:CarbonImmutable,today:CarbonImmutable,original_room_total:float}  $context
     * @return array{policy:string,penalty:float,adjusted_room_total:float}
     */
    private function amounts(Reservation $reservation, array $data, array $context): array
    {
        $originalNights = max(1, $context['check_in']->diffInDays($context['contractual_check_out']));
        $usedNights = $context['check_in']->diffInDays($context['departure']);
        $usedRoomTotal = round($context['original_room_total'] * $usedNights / $originalNights, 2);
        $unusedRoomValue = round(max(0, $context['original_room_total'] - $usedRoomTotal), 2);
        $policy = (string) $data['policy'];
        $penalty = match ($policy) {
            'full' => $unusedRoomValue,
            'partial' => round((float) ($data['penalty_amount'] ?? 0), 2),
            default => 0.0,
        };

        if ($penalty > $unusedRoomValue + 0.005) {
            throw ValidationException::withMessages([
                'penalty_amount' => 'Penaliteti nuk mund të kalojë vlerën e netëve të papërdorura: '
                    .number_format($unusedRoomValue, 2).' '.ReservationMoney::currency($reservation).'.',
            ]);
        }

        return [
            'policy' => $policy,
            'penalty' => $penalty,
            'adjusted_room_total' => $policy === 'full'
                ? $context['original_room_total']
                : round($usedRoomTotal + $penalty, 2),
        ];
    }

    private function channelCommission(Reservation $reservation, float $total): float
    {
        $channel = Reservation::normalizeChannel($reservation->channel);
        if ($channel === 'direct') {
            return 0.0;
        }

        $fees = (array) Setting::get('financial.channel_fees', []);
        $percentage = isset($fees[$channel]) && is_numeric($fees[$channel]) ? (float) $fees[$channel] : 0.0;

        return round($total * $percentage / 100, 2);
    }

    private function createCleaningTask(Reservation $reservation, ?Room $room): void
    {
        if (! $room || ! Setting::get('housekeeping.auto_create_on_checkout', true)) {
            return;
        }

        $alreadyOpen = CleaningTask::query()
            ->where('room_id', $reservation->room_id)
            ->where('type', 'checkout_clean')
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists();

        if (! $alreadyOpen) {
            CleaningTask::create([
                'room_id' => $reservation->room_id,
                'type' => 'checkout_clean',
                'status' => 'pending',
                'priority' => Setting::get('housekeeping.default_priority', 'normal'),
            ]);
        }
    }
}
