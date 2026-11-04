<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A user reporting somebody else's notification, sent to the admin address.
 *
 * Same two corrections as App\Mail\IssueReported: the old version set From:
 * to the reporting user's address, which fails SPF and DKIM for any domain
 * they do not own, and echoed the reason into HTML unescaped. From: is this
 * application and the reporter is in Reply-To.
 */
class NotificationReported extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $reporterName,
        public string $reporterEmail,
        public string $posterName,
        public int $notificationId,
        public string $notificationBody,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            replyTo: [new Address($this->reporterEmail, $this->reporterName)],
            subject: 'Canadian Land Access Systems - Report Notification',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notification-reported',
            with: ['subject' => 'Canadian Land Access Systems - Report Notification'],
        );
    }
}
