<?php

namespace App\Http\Requests\Beach;

use App\Models\BeachReservation;
use App\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBeachReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create_beach') ?? false;
    }

    public function rules(): array
    {
        return [
            'beach_unit_id' => ['required', 'integer', TenantRule::exists('beach_units')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'guest_name' => ['required', 'string', 'max:150'],
            'guest_phone' => ['required', 'string', 'max:50'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'reservation_id' => ['nullable', 'integer', TenantRule::exists('reservations')],
            'status' => ['sometimes', Rule::in([BeachReservation::STATUS_PENDING, BeachReservation::STATUS_CONFIRMED])],
        ];
    }
}
