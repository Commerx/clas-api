{{--
    A visitor checking in or out, sent to the listing owner.
    Ported from views/mail/checkin_checkout.php, where `$status` was the
    preposition: "to" for a check-in, "from" for a check-out.
--}}
<x-mail.layout :subject="$subject">

<p>
    Hi {{ $ownerName }},<br>

    {{ $requesterName }} has been <strong>{{ $request->status }}</strong> {{ $preposition }}
    your property {{ $propertyName }} now.
</p>

@if ($request->harvest_animal === 'Yes' || ! empty($request->suspicious_activity))
    <p>Please see their comments below.</p>

    <p>Did you harvest an animal? <strong>{{ $request->harvest_animal ?? 'No' }}</strong></p>

    @if (! empty($request->suspicious_activity))
        <p>
            Did you see any suspicious activity on the property?<br>
            <strong>{{ $request->suspicious_activity }}</strong>
        </p>
    @endif

    @if (! empty($huntingCategory))
        <p>Category: <strong>{{ $huntingCategory }}</strong></p>
    @endif

    @if (! empty($huntingSubCategory))
        <p>Sub category: <strong>{{ $huntingSubCategory }}</strong></p>
    @endif
@endif

</x-mail.layout>
