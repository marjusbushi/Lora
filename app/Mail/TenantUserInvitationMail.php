<?php

namespace App\Mail;

use App\Models\TenantUserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantUserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public TenantUserInvitation $invitation,
        public string $invitationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ftesë për t’iu bashkuar '.$this->invitation->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.tenant-user-invitation');
    }
}
