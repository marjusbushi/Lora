<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StayExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update_reservations') === true;
    }

    public function rules(): array
    {
        return [
            'new_check_out_date' => ['required', 'date_format:Y-m-d'],
            'extension_amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $reservation = $this->route('reservation');
            if (! $reservation instanceof Reservation || ! $this->filled('new_check_out_date')) {
                return;
            }

            if ($reservation->status !== 'checked_in') {
                $validator->errors()->add(
                    'new_check_out_date',
                    'Zgjatja e qëndrimit lejohet vetëm për një mysafir që është në hotel.'
                );

                return;
            }

            if ($reservation->early_departure_scheduled_at && ! $reservation->early_departure_at) {
                $validator->errors()->add(
                    'new_check_out_date',
                    'Anulo fillimisht planin e largimit të parakohshëm.'
                );

                return;
            }

            try {
                $newCheckOut = CarbonImmutable::createFromFormat(
                    '!Y-m-d',
                    (string) $this->input('new_check_out_date')
                );
            } catch (\Throwable) {
                return;
            }

            if (! $newCheckOut) {
                return;
            }

            $currentCheckOut = CarbonImmutable::parse($reservation->check_out_date)->startOfDay();
            if ($newCheckOut->lte($currentCheckOut)) {
                $validator->errors()->add(
                    'new_check_out_date',
                    'Data e re duhet të jetë pas check-out-it aktual.'
                );
            }

            $timezone = app(TenantContext::class)->tenant()?->timezone
                ?: config('app.timezone');
            if ($newCheckOut->toDateString() <= CarbonImmutable::today($timezone)->toDateString()) {
                $validator->errors()->add(
                    'new_check_out_date',
                    'Check-out-i i ri duhet të jetë pas ditës së sotme.'
                );
            }
        });
    }
}
