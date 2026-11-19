<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WeeklySummaryMail extends Mailable
{
    public function __construct(public array $data) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ringkasan mingguan kehadiran · '.$this->data['organization']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.weekly-summary', with: ['d' => $this->data]);
    }
}
