<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class EmailChangeCompleted extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('account_email.notice_subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account.email-change-completed');
    }
}
