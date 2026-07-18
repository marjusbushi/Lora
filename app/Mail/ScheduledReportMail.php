<?php

namespace App\Mail;

use App\Models\SavedReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ScheduledReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SavedReport $savedReport, public string $reportUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Raporti periodik: '.$this->savedReport->name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.scheduled-report');
    }
}
