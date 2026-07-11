<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use App\Models\Room;
use App\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReservationUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $channel = $this->input('channel');
        if ($this->exists('channel') && (is_string($channel) || $channel === null)) {
            $this->merge(['channel' => Reservation::normalizeChannel($channel)]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('update_reservations');
    }

    public function rules(): array
    {
        return [
            'room_id' => ['required', TenantRule::exists('rooms')],
            'guest_id' => ['required', TenantRule::exists('guests')],
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'status' => ['sometimes', 'in:pending,confirmed,checked_in,checked_out,cancelled'],
            'adults' => ['required', 'integer', 'min:1', 'max:10'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'channel' => ['sometimes', 'nullable', Rule::in(Reservation::CHANNELS)],
            'total_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->room_id && $this->check_in_date && $this->check_out_date) {
                $excludeId = $this->route('reservation')->id;
                $room = Room::find($this->room_id);
                if ($room && $room->status === 'maintenance') {
                    $validator->errors()->add('room_id', 'Kjo dhome eshte ne mirembajtje. Ndrysho statusin te Dhomat per ta perdorur.');
                } elseif (! Reservation::isRoomAvailable($this->room_id, $this->check_in_date, $this->check_out_date, $excludeId)) {
                    $validator->errors()->add('room_id', 'Kjo dhome eshte e zene per keto data (ka nje rezervim tjeter).');
                }
            }

            if ($this->room_id) {
                $maxOccupancy = Room::with('roomType:id,max_occupancy')
                    ->find($this->room_id)?->roomType?->max_occupancy;
                $guests = (int) $this->adults + (int) $this->children;
                if ($maxOccupancy && $guests > $maxOccupancy) {
                    $validator->errors()->add('adults', "Kjo dhome lejon maksimumi {$maxOccupancy} persona.");
                }
            }
        });
    }
}
