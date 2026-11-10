<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An internal notice that signs have been ordered and paid for.
 *
 * Goes to the admin address, not the customer. Nobody finds out a listing
 * needs signs any other way, so this is the whole of the fulfilment trigger.
 */
class SignageOrderPlaced extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array<string,mixed>>  $lines
     * @param  array<string,mixed>  $shippingTo
     */
    public function __construct(
        public int $landId,
        public string $landName,
        public string $orderedBy,
        public string $amount,
        public string $reference,
        public array $lines,
        public array $shippingTo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: sprintf('CLAS signage order - listing %d', $this->landId),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.signage-order-placed',
            with: ['subject' => 'Signage order'],
        );
    }
}
