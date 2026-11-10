<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * A subscription will not renew.
 *
 * Says plainly that access continues to the date already paid for, because
 * the thing people fear when they cancel is losing what they have bought.
 */
class SubscriptionCancelled extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subscriptionName,
        /** The end of the paid period, as Y-m-d. */
        public string $accessUntil,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: 'Canadian Land Access Systems - your subscription will not renew',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.subscription-cancelled',
            with: [
                'subject' => 'Your subscription will not renew',
                'accessUntilLabel' => Carbon::parse($this->accessUntil)->format('M j, Y'),
                'supportEmail' => config('clas.admin_email'),
            ],
        );
    }
}
