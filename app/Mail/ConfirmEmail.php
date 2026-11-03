<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The link that completes a registration.
 *
 * The link points at the Yii application, which serves the confirmation page
 * and owns the `token` table both applications share. Ported from
 * views/mail/confirmation.php.
 */
class ConfirmEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: 'Confirm your Canadian Land Access Systems account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.confirm-email',
            with: ['subject' => 'Confirm your Canadian Land Access Systems account'],
        );
    }
}
