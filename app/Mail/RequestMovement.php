<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a listing owner that a visitor has checked in or out.
 *
 * One mailable for both, as the old application used one template and varied
 * a preposition: "to" for a check-in, "from" for a check-out.
 *
 * These are the notices that were blind-copied to two named staff addresses.
 * That list now comes from config and is empty unless set.
 */
class RequestMovement extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public object $request,
        public string $requesterName,
        public string $ownerName,
        public string $propertyName,
        public string $movement,
        public ?string $huntingCategory = null,
        public ?string $huntingSubCategory = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: $this->subjectLine(),
            bcc: config('notifications.movement_bcc', []),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.request-movement',
            with: [
                'subject' => $this->subjectLine(),
                'preposition' => $this->movement === 'in' ? 'to' : 'from',
            ],
        );
    }

    private function subjectLine(): string
    {
        return $this->movement === 'in'
            ? 'Your Property Checkin Status'
            : 'Your Property Checkout Status';
    }
}
