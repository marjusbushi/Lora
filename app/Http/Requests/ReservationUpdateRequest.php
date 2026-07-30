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

        // Clients that predate the ref field never send it — keep their edits
        // working by carrying the reservation's current value through.
        if (! $this->exists('channel_ref')) {
            $this->merge(['channel_ref' => $this->route('reservation')?->channel_ref]);
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
            'channel_ref' => $this->channelRefRules(),
            'total_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ];
    }

    /**
     * Strict ref rules apply only to NEW debt: a changed source or a changed
     * reference. An untouched legacy value (including a missing one) keeps the
     * reservation editable — old rows are repaired via the audit tools, not by
     * blocking an unrelated date change at the desk.
     */
    private function channelRefRules(): array
    {
        $reservation = $this->route('reservation');

        $requested = $this->input('channel');
        if (! is_string($requested) && $requested !== null) {
            // The channel field's own rule rejects malformed payloads — answer
            // with a 422 there instead of a TypeError here.
            return ['nullable', 'string', 'max:120'];
        }

        $channel = Reservation::normalizeChannel($requested ?? $reservation?->channel);

        $channelUnchanged = $reservation
            && $channel === Reservation::normalizeChannel($reservation->channel);
        $refUnchanged = $reservation
            && (string) $this->input('channel_ref') === (string) $reservation->channel_ref;

        if ($channelUnchanged && $refUnchanged) {
            return ['nullable', 'string', 'max:120'];
        }

        return Reservation::channelRefRules($channel, $reservation?->id);
    }

    public function messages(): array
    {
        return [
            'channel_ref.required' => 'Per rezervimet nga OTA numri i rezervimit eshte i detyrueshem — e gjen te extranet-i ose email-i i konfirmimit.',
            'channel_ref.regex' => 'Numri i rezervimit nuk ka formatin e pritur per kete kanal.',
            'channel_ref.unique' => 'Ky numer rezervimi ekziston tashme ne sistem per kete kanal.',
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
