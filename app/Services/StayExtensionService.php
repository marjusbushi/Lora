<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FiscalDocument;
use App\Models\Reservation;
use App\Models\Room;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StayExtensionService
{
    /**
     * @return array{
     *     current_check_out:string,
     *     new_check_out:string,
     *     additional_nights:int,
     *     quoted_extension:float,
     *     available:bool
     * }
     */
    public function quote(Reservation $reservation, string $newCheckOut): array
    {
        $this->assertEligible($reservation);

        $current = CarbonImmutable::parse($reservation->check_out_date)->startOfDay();
        $new = CarbonImmutable::parse($newCheckOut)->startOfDay();
        if ($new->lte($current)) {
            throw ValidationException::withMessages([
                'new_check_out_date' => 'Data e re duhet të jetë pas check-out-it aktual.',
            ]);
        }
        $this->assertFutureCheckOut($new);

        $room = Room::query()->with('roomType')->find($reservation->room_id);
        if (! $room || ! $room->roomType) {
            throw ValidationException::withMessages([
                'new_check_out_date' => 'Dhoma ose tipi i dhomës nuk ekziston më.',
            ]);
        }

        if (! Reservation::isRoomAvailable(
            $room->id,
            $current->toDateString(),
            $new->toDateString(),
            $reservation->id,
        )) {
            throw ValidationException::withMessages([
                'new_check_out_date' => "Dhoma {$room->room_number} nuk është e lirë për të gjitha netët shtesë.",
            ]);
        }

        $quote = RoomPricing::quote($room->roomType, $current, $new);

        return [
            'current_check_out' => $current->toDateString(),
            'new_check_out' => $new->toDateString(),
            'additional_nights' => $quote['nights'],
            'quoted_extension' => round((float) $quote['total'], 2),
            'available' => true,
        ];
    }

    /**
     * @param  array{new_check_out_date:string,extension_amount:float|int|string,reason:string}  $data
     * @return array{room_number:?string,additional_nights:int,extension_amount:float,new_total:float}
     */
    public function extend(Reservation $reservation, array $data, ?int $userId): array
    {
        return DB::transaction(function () use ($reservation, $data, $userId) {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $this->assertEligible($locked);

            $room = Room::query()->with('roomType')->lockForUpdate()->find($locked->room_id);
            if (! $room || ! $room->roomType) {
                throw ValidationException::withMessages([
                    'new_check_out_date' => 'Dhoma ose tipi i dhomës nuk ekziston më.',
                ]);
            }

            $current = CarbonImmutable::parse($locked->check_out_date)->startOfDay();
            $new = CarbonImmutable::parse($data['new_check_out_date'])->startOfDay();
            if ($new->lte($current)) {
                throw ValidationException::withMessages([
                    'new_check_out_date' => 'Data e re duhet të jetë pas check-out-it aktual.',
                ]);
            }
            $this->assertFutureCheckOut($new);

            if (! Reservation::isRoomAvailable(
                $room->id,
                $current->toDateString(),
                $new->toDateString(),
                $locked->id,
            )) {
                throw ValidationException::withMessages([
                    'new_check_out_date' => "Dhoma {$room->room_number} u rezervua ndërkohë. Zgjidh një datë tjetër ose ndrysho dhomën.",
                ]);
            }

            $quotedExtension = RoomPricing::total($room->roomType, $current, $new);
            $extensionAmount = round((float) $data['extension_amount'], 2);
            $oldTotal = round((float) $locked->total_amount, 2);
            $newTotal = round($oldTotal + $extensionAmount, 2);
            $additionalNights = $current->diffInDays($new);

            $locked->update([
                'check_out_date' => $new->toDateString(),
                'total_amount' => $newTotal,
            ]);

            AuditLog::record('reservation.stay_extended', $locked, [
                'original_check_out' => $current->toDateString(),
                'new_check_out' => $new->toDateString(),
                'additional_nights' => $additionalNights,
                'quoted_extension' => round($quotedExtension, 2),
                'agreed_extension' => $extensionAmount,
                'original_room_total' => $oldTotal,
                'adjusted_room_total' => $newTotal,
                'reason' => trim((string) $data['reason']),
                'inventory_reserved' => true,
                'ota_contract_unchanged' => Reservation::normalizeChannel($locked->channel) !== 'direct',
                'extended_by' => $userId,
            ]);

            return [
                'room_number' => $room->room_number,
                'additional_nights' => $additionalNights,
                'extension_amount' => $extensionAmount,
                'new_total' => $newTotal,
            ];
        });
    }

    private function assertEligible(Reservation $reservation): void
    {
        if ($reservation->status !== 'checked_in') {
            throw ValidationException::withMessages([
                'new_check_out_date' => 'Zgjatja lejohet vetëm për një mysafir që është në hotel.',
            ]);
        }

        if ($reservation->early_departure_scheduled_at && ! $reservation->early_departure_at) {
            throw ValidationException::withMessages([
                'new_check_out_date' => 'Anulo fillimisht planin e largimit të parakohshëm.',
            ]);
        }

        if (FiscalDocument::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', FiscalDocument::STATUS_FISCALIZED)
            ->exists()) {
            throw ValidationException::withMessages([
                'new_check_out_date' => 'Rezervimi ka faturë të fiskalizuar dhe nuk mund të zgjatet pa dokument korrigjues.',
            ]);
        }
    }

    private function assertFutureCheckOut(CarbonImmutable $newCheckOut): void
    {
        $timezone = app(TenantContext::class)->tenant()?->timezone ?: config('app.timezone');
        if ($newCheckOut->toDateString() <= CarbonImmutable::today($timezone)->toDateString()) {
            throw ValidationException::withMessages([
                'new_check_out_date' => 'Check-out-i i ri duhet të jetë pas ditës së sotme.',
            ]);
        }
    }
}
