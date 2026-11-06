{{--
    A declined renewal. The old mail said the same thing on the first and last
    attempt, so a subscriber could not tell whether anything would be retried.
--}}
<x-mail.layout :subject="$subject">

<p>
    We were not able to renew your Canadian Land Access Systems subscription.
</p>

<table cellpadding="6" cellspacing="0" style="border-collapse:collapse">
    <tr><td><strong>Subscription</strong></td><td>{{ $subscriptionName }}</td></tr>
    <tr><td><strong>Amount</strong></td><td>${{ $amount }}</td></tr>
    <tr><td><strong>Reason</strong></td><td>{{ $reason }}</td></tr>
</table>

@if ($finalAttempt)
<p>
    <strong>This was our last attempt.</strong> Your subscription will lapse
    unless you update your payment details in the app.
</p>
@else
<p>
    We will try once more in a few days. If your card has changed, you can
    update it in the app and save us both the wait.
</p>
@endif

</x-mail.layout>
