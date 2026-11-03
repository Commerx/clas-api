<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The password reset link, served by the Yii application.
 *
 * Ported from views/mail/recovery.php. The code expires after
 * config('clas.recover_within') seconds, which the message now states — the
 * old one did not, so a user coming back to an old mail got an error page
 * with no explanation.
 */
class ResetPassword extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $url,
        public int $hours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: 'Reset your Canadian Land Access Systems password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.reset-password',
            with: ['subject' => 'Reset your Canadian Land Access Systems password'],
        );
    }
}
