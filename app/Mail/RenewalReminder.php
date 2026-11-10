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
 * A renewal is coming up, sent before the card is charged.
 *
 * Deliberately says nothing about which gateway takes the money: the same
 * mail goes to a subscriber on Stripe and to one still on Moneris, and the
 * difference is not theirs to care about.
 */
class RenewalReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subscriberName,
        public string $subscriptionName,
        public string $amount,
        /** The renewal date, as Y-m-d. */
        public string $renewsOn,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: 'Canadian Land Access Systems - your subscription renews on '
                .$this->renewsOnLabel(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.renewal-reminder',
            with: [
                'subject' => 'Your subscription renews soon',
                'renewsOnLabel' => $this->renewsOnLabel(),
                'supportEmail' => config('clas.admin_email'),
            ],
        );
    }

    /**
     * The date as a person reads it.
     *
     * In the subject line as well as the body, because the whole point of
     * this mail is that the date is not a surprise — and a subject line is
     * the part that gets read.
     */
    private function renewsOnLabel(): string
    {
        return Carbon::parse($this->renewsOn)->format('M j, Y');
    }
}
