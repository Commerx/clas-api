<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A renewal was declined.
 *
 * `finalAttempt` is what the old mail could not say: it sent the same
 * "payment declined" message for a first attempt and a last one, so a
 * subscriber had no way to tell whether anything would be tried again.
 */
class RenewalFailed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subscriptionName,
        public string $amount,
        public string $reason,
        public int $attempt,
        public bool $finalAttempt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: $this->finalAttempt
                ? 'Canadian Land Access Systems - action needed to keep your subscription'
                : 'Canadian Land Access Systems - we could not renew your subscription',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.renewal-failed',
            with: ['subject' => 'We could not renew your subscription'],
        );
    }
}
