<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a listing owner that someone has asked for access.
 *
 * Only sent when the visit is within three days, as before: an owner is not
 * chased about a booking two months out.
 */
class NewRequestForOwner extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public object $request,
        public string $ownerName,
        public string $requesterName,
        public string $propertyName,
        public string $requestDates,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: 'New Request For Your Property',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.new-request',
            with: ['subject' => 'New Request For Your Property'],
        );
    }
}
