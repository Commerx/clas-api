{{--
    The receipt for a new subscription.
    Ported from views/mail/subscription-notification.php.
--}}
<x-mail.layout :subject="$subject">

<p>
    Hi {{ $customerName }},<br>

    Thank you for subscribing to Canadian Land Access Systems.
</p>

<table cellpadding="6" cellspacing="0" style="border-collapse:collapse">
    <tr>
        <td><strong>Subscription</strong></td>
        <td>{{ $subscriptionName }}</td>
    </tr>
    @if ($discount !== null)
    <tr>
        <td><strong>Price</strong></td>
        <td>${{ $discount->subscriptionAmount }}</td>
    </tr>
    <tr>
        <td><strong>Promo code</strong></td>
        <td>{{ $discount->code }} ({{ $discount->rate }}% off)</td>
    </tr>
    <tr>
        <td><strong>Discount</strong></td>
        <td>&minus;${{ $discount->discountAmount }}</td>
    </tr>
    @endif
    <tr>
        <td><strong>{{ $amountPaid === '0.00' ? 'Total' : 'Amount paid' }}</strong></td>
        <td>${{ $amountPaid }}</td>
    </tr>
    <tr>
        <td><strong>Valid until</strong></td>
        <td>{{ $expiresOn }}</td>
    </tr>
    <tr>
        <td><strong>Reference</strong></td>
        <td>{{ $subscriptionId }}</td>
    </tr>
</table>

{{-- Both paths include these, which the old receipt never mentioned, so
     subscribers had no idea they had them. --}}
<p>
    Your subscription also includes a free year of wildlife management unit
    access and the fishing lands regulations.
</p>

<p>
    You can view your subscription in the app at any time.
</p>

</x-mail.layout>
