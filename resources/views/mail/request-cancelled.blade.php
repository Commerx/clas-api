{{--
    A requester cancelling, sent to the listing owner.
    Ported from views/mail/request-cancel.php.
--}}
<x-mail.layout :subject="$subject">

<p>
    Hi {{ $ownerName }},<br>

    {{ $requesterName }} has cancelled their request, which was sent to your property
    &quot;{{ $propertyName }}&quot; for &quot;{{ $requestDate }}&quot;.
</p>

@if (! empty($request->requester_comment))
    <p>
        <strong>{{ $requesterName }}&rsquo;s comment:</strong><br>
        {{ $request->requester_comment }}
    </p>
@endif

</x-mail.layout>
