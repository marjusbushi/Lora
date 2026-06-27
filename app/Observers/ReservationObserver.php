<?php

namespace App\Observers;

use App\Mail\NewReservationMail;
use App\Models\Reservation;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ReservationObserver
{
    /**
     * Email the hotel on every new reservation. Wrapped so a mail failure
     * (no SMTP, server down, etc.) NEVER breaks the booking.
     */
    public function created(Reservation $reservation): void
    {
        $to = Setting::get('hotel.email');
        if (! $to) {
            return;
        }

        try {
            $reservation->loadMissing(['guest', 'room.roomType']);
            Mail::to($to)->send(new NewReservationMail($reservation));
        } catch (\Throwable $e) {
            Log::warning('New-reservation email failed: ' . $e->getMessage());
        }
    }
}
