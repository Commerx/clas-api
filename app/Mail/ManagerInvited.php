<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An invitation to manage somebody's listing.
 *
 * The accept and decline links point at the old web application, which is
 * where an invitation is answered today; it requires the invited person to be
 * signed in as the account the invitation names, so the link is not something
 * a stranger can use. That also means an invitation sent to an address with
 * no account yet can never be answered, which is a gap this API cannot close
 * without an acceptance endpoint of its own — see docs/build-order.md.
 *
 * Two smaller corrections from the old mail: the subject said "his property"
 * regardless of who the owner was, and the owner and listing names were
 * interpolated into HTML unescaped.
 */
class ManagerInvited extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $managerId,
        public string $managerName,
        public string $ownerName,
        public string $listingName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            subject: 'Canadian Land Access Systems - '.$this->ownerName
                .' invites you to manage a property',
        );
    }

    public function content(): Content
    {
        $base = rtrim((string) config('clas.legacy_web_url'), '/').'/properties/manager-status';

        return new Content(
            view: 'mail.manager-invited',
            with: [
                'subject' => 'Canadian Land Access Systems - invitation to manage a property',
                'acceptUrl' => $base.'?status=accepted&id='.$this->managerId,
                'declineUrl' => $base.'?status=declined&id='.$this->managerId,
            ],
        );
    }
}
