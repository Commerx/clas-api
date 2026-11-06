<?php

namespace App\Mail;

use App\Services\Payments\Discount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The receipt for a new subscription.
 *
 * The old version set both From: and Bcc: to the admin address, which meant
 * every receipt was blind-copied to the office and sent as though the office
 * had written it. From: is this application, and the admin copy is a
 * configured address rather than a hardcoded one, so it can be turned off
 * without a code change.
 */
class SubscriptionStarted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $customerName,
        public string $subscriptionName,
        public string $amountPaid,
        public ?Discount $discount,
        public string $expiresOn,
        public int $subscriptionId,
    ) {}

    public function envelope(): Envelope
    {
        $admin = (string) config('clas.admin_email');

        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            bcc: $admin !== '' ? [new Address($admin)] : [],
            subject: $this->customerName.' - Your CLAS Member Subscription',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.subscription-started',
            with: ['subject' => 'Your CLAS Member Subscription'],
        );
    }
}
