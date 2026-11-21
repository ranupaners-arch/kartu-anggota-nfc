<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Email pemberitahuan singkat (judul, isi, tombol tautan). */
class NoticeMail extends Mailable
{
    public function __construct(public string $title, public string $body, public string $url, public string $action = 'Buka') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.notice');
    }
}
