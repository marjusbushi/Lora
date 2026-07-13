<?php

namespace App\Mail;

use App\Models\Reservation;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewReservationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Reservation $reservation)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Rezervim i ri #'.$this->reservation->id.' — '.$this->hotelName(),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.new-reservation', with: [
            'hotelName' => $this->hotelName(),
        ]);
    }

    private function hotelName(): string
    {
        return (string) (Setting::get('hotel.name') ?: config('app.name'));
    }
}
