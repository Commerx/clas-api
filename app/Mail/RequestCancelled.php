<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a listing owner that a requester has cancelled, so they know the day
 * is free again.
 *
 * The old subject interpolated the requester's name, and is kept.
 */
class RequestCancelled extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public object $request,
        public string $requesterName,
        public string $ownerName,
        public string $propertyName,
        public string $requestDate,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: $this->requesterName.' has cancelled their request',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.request-cancelled',
            with: ['subject' => $this->requesterName.' has cancelled their request'],
        );
    }
}
