{{-- A renewal that went through. --}}
<x-mail.layout :subject="$subject">

<p>
    Your Canadian Land Access Systems subscription has renewed.
</p>

<table cellpadding="6" cellspacing="0" style="border-collapse:collapse">
    <tr><td><strong>Subscription</strong></td><td>{{ $subscriptionName }}</td></tr>
    <tr><td><strong>Amount</strong></td><td>${{ $amount }}</td></tr>
    <tr><td><strong>Valid until</strong></td><td>{{ $expiresOn }}</td></tr>
</table>

<p>No action is needed. Thank you for staying with us.</p>

</x-mail.layout>
