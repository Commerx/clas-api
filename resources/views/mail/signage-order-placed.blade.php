{{-- Internal. The only notice that a listing's signs need making and posting. --}}
<x-mail.layout :subject="$subject">

<p>
    Signs have been ordered and paid for on listing
    <strong>#{{ $landId }}</strong>@if ($landName !== ''), {{ $landName }}@endif.
</p>

<table cellpadding="6" cellspacing="0" style="border-collapse:collapse">
    <tr><td><strong>Ordered by</strong></td><td>{{ $orderedBy }}</td></tr>
    <tr><td><strong>Paid</strong></td><td>${{ $amount }}</td></tr>
    <tr><td><strong>Reference</strong></td><td>{{ $reference }}</td></tr>
</table>

<p><strong>Signs</strong></p>

<table cellpadding="6" cellspacing="0" style="border-collapse:collapse">
    <tr>
        <td><strong>Item</strong></td>
        <td><strong>Qty</strong></td>
        <td><strong>Each</strong></td>
    </tr>
    @foreach ($lines as $line)
    <tr>
        <td>{{ $line['name'] }}</td>
        <td>{{ $line['qty'] }}</td>
        <td>${{ $line['price'] }}</td>
    </tr>
    @endforeach
</table>

<p><strong>Ship to</strong></p>

@if ($shippingTo === [])
    <p>No shipping address on the listing.</p>
@else
    <p>
        {{ $shippingTo['street'] ?? '' }}<br>
        {{ $shippingTo['city'] ?? '' }}, {{ $shippingTo['province'] ?? '' }}
        {{ $shippingTo['postal_code'] ?? '' }}<br>
        {{ $shippingTo['country'] ?? '' }}<br>
        {{ $shippingTo['phone'] ?? '' }}
    </p>
@endif

</x-mail.layout>
