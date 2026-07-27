<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EarlyDepartureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update_reservations') === true;
    }

    public function rules(): array
    {
        return [
            'departure_date' => ['required', 'date_format:Y-m-d'],
            'policy' => ['required', Rule::in(['waive', 'partial', 'full'])],
            'penalty_amount' => ['nullable', 'required_if:policy,partial', 'numeric', 'min:0.01', 'max:9999999'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'settle_method' => ['nullable', Rule::in(['cash', 'card'])],
            'refund_method' => ['nullable', Rule::in(['cash', 'card'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $reservation = $this->route('reservation');
            if (! $reservation instanceof Reservation || ! $this->filled('departure_date')) {
                return;
            }

            if ($reservation->status !== 'checked_in') {
                $validator->errors()->add('departure_date', 'Largimi i parakohshëm lejohet vetëm për një mysafir që është në hotel.');

                return;
            }

            try {
                $departure = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('departure_date'));
            } catch (\Throwable) {
                return;
            }

            if (! $departure) {
                return;
            }

            $checkIn = CarbonImmutable::parse($reservation->check_in_date)->startOfDay();
            $contractualCheckOut = CarbonImmutable::parse(
                $reservation->original_check_out_date ?: $reservation->check_out_date
            )->startOfDay();

            if ($departure->lte($checkIn)) {
                $validator->errors()->add('departure_date', 'Data e largimit duhet të jetë pas datës së check-in.');
            }
            if ($departure->gte($contractualCheckOut)) {
                $validator->errors()->add('departure_date', 'Data duhet të jetë para check-out-it fillestar.');
            }
        });
    }
}
