<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The grace period ran out and the subscription has lapsed. */
class SubscriptionExpired extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subscriptionName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: 'Canadian Land Access Systems - your subscription has expired',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.subscription-expired',
            with: ['subject' => 'Your subscription has expired'],
        );
    }
}
