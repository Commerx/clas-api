{{-- Sent before the card is charged, not after. --}}
<x-mail.layout :subject="$subject">

<p>Hi {{ $subscriberName }},</p>

<p>
    Your Canadian Land Access Systems subscription renews on
    <strong>{{ $renewsOnLabel }}</strong>. We'll charge the card we have on
    file &mdash; there's nothing you need to do to keep your access.
</p>

<table cellpadding="6" cellspacing="0" style="border-collapse:collapse">
    <tr><td><strong>Subscription</strong></td><td>{{ $subscriptionName }}</td></tr>
    <tr><td><strong>Amount</strong></td><td>${{ $amount }}</td></tr>
    <tr><td><strong>Renews on</strong></td><td>{{ $renewsOnLabel }}</td></tr>
</table>

<p>
    <strong>Paying with a different card?</strong> You can update the card on
    your account in the CLAS app. Do it before {{ $renewsOnLabel }} and the
    renewal will use the new one.
</p>

{{--
    There is no self-serve way to cancel a subscription in either
    application: `auto_renew` is only ever set to 0 on the free-promocode
    path, and nothing in the app or the website turns renewal off. So the
    honest instruction is to ask a person. Change this the day a cancel
    endpoint exists, and not before — telling somebody to use a button that
    isn't there is how a reminder email becomes a chargeback.
--}}
<p>
    If you'd rather not renew, email us at
    <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a> before
    {{ $renewsOnLabel }} and we'll take care of it.
</p>

<p>Thank you for being part of the CLAS community.</p>

</x-mail.layout>
