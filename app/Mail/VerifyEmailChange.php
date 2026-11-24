<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

final class VerifyEmailChange extends Mailable
{
    public function __construct(#[SensitiveParameter] public readonly string $verificationUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('account_email.verify_subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account.verify-email-change');
    }
}
