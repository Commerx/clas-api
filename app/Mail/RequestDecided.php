<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a requester their access request was accepted or declined.
 *
 * Subject and body match the old application's, because the same people
 * receive both while the two run side by side — a decision made through
 * either should read the same way.
 */
class RequestDecided extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public object $request,
        public string $requesterName,
        public string $propertyName,
        public string $requestDate,
        public string $decision,
        public ?string $rules,
        public ?string $source,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: 'Your Property Request Status',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.request-decided',
            with: ['subject' => 'Your Property Request Status'],
        );
    }
}
