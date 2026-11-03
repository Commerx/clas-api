<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A user reporting a problem, sent to the admin address.
 *
 * Two things changed from views/mail/report-issue.php, both because the old
 * version put user-supplied text into an administrator's inbox:
 *
 *  - The old code set From: to the reporting user's own address. Mail sent
 *    that way fails SPF and DKIM for every domain the user does not own, so
 *    it lands in spam or is rejected outright. This sends from the
 *    application's own address and puts the user in Reply-To, which is what
 *    From: was being misused to achieve.
 *  - The old template echoed the subject, issue type and message straight
 *    into HTML with no escaping, so a report could inject markup — or a
 *    link — into the mail an administrator reads. Blade escapes by default.
 */
class IssueReported extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $reporterName,
        public string $reporterEmail,
        public string $issueSubject,
        public string $issueType,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('notifications.from.address'),
                config('notifications.from.name'),
            ),
            replyTo: [new Address($this->reporterEmail, $this->reporterName)],
            subject: 'Canadian Land Access Systems - Report an Issue',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.issue-reported',
            with: ['subject' => 'Canadian Land Access Systems - Report an Issue'],
        );
    }
}
