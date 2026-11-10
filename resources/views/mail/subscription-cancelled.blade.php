{{-- Confirms a cancellation, and that nothing is lost today. --}}
<x-mail.layout :subject="$subject">

<p>
    Your Canadian Land Access Systems subscription will not renew. We won't
    charge your card again.
</p>

<table cellpadding="6" cellspacing="0" style="border-collapse:collapse">
    <tr><td><strong>Subscription</strong></td><td>{{ $subscriptionName }}</td></tr>
    <tr><td><strong>Access until</strong></td><td>{{ $accessUntilLabel }}</td></tr>
</table>

<p>
    You keep everything you've paid for until <strong>{{ $accessUntilLabel }}</strong>
    &mdash; nothing changes before then.
</p>

<p>
    Changed your mind? You can turn renewal back on in the CLAS app any time
    before that date, or email us at
    <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.
</p>

<p>Thank you for being part of the CLAS community.</p>

</x-mail.layout>
