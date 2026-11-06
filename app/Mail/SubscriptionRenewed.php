<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A renewal went through. */
class SubscriptionRenewed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subscriptionName,
        public string $amount,
        public string $expiresOn,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: 'Canadian Land Access Systems - your subscription has renewed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.subscription-renewed',
            with: ['subject' => 'Your subscription has renewed'],
        );
    }
}
