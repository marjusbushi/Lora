<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReservationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create_reservations');
    }

    public function rules(): array
    {
        return [
            'room_id' => ['required', 'exists:rooms,id'],
            'guest_id' => ['required', 'exists:guests,id'],
            'check_in_date' => ['required', 'date', 'after_or_equal:today'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'status' => ['sometimes', 'in:pending,confirmed'],
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
                if (!Reservation::isRoomAvailable($this->room_id, $this->check_in_date, $this->check_out_date)) {
                    $validator->errors()->add('room_id', 'Kjo dhome eshte e zene per keto data.');
                }
            }

            $this->validateOccupancy($validator);
        });
    }

    protected function validateOccupancy($validator): void
    {
        if (!$this->room_id) {
            return;
        }

        $maxOccupancy = Room::with('roomType:id,max_occupancy')
            ->find($this->room_id)?->roomType?->max_occupancy;

        $guests = (int) $this->adults + (int) $this->children;

        if ($maxOccupancy && $guests > $maxOccupancy) {
            $validator->errors()->add('adults', "Kjo dhome lejon maksimumi {$maxOccupancy} persona.");
        }
    }

    public function messages(): array
    {
        return [
            'check_out_date.after' => 'Data e daljes duhet te jete pas dates se hyrjes.',
            'check_in_date.after_or_equal' => 'Data e hyrjes nuk mund te jete ne te shkuaren.',
        ];
    }
}
