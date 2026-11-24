<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

final class AccountPasswordMail extends Mailable
{
    public function __construct(
        #[SensitiveParameter] public readonly ?string $resetUrl = null,
        public readonly ?string $changedAt = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __($this->resetUrl === null ? 'password_reset.mail.changed_subject' : 'password_reset.mail.reset_subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account.password-security');
    }
}
