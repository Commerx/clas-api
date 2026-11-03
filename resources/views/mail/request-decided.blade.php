{{--
    The owner's decision on an access request, sent to the requester.

    Ported from the old application's views/mail/request-response.php. The
    branching is theirs and is kept: whether the listing needs scanning in and
    out depends on `source` being empty, which distinguishes a listing entered
    by its owner from one imported from a provincial dataset.
--}}
<x-mail.layout :subject="$subject">

<p>
    Hi {{ $requesterName }},<br>

    @if ($request->status === 'Accepted' && $source === null)
        Your request has been accepted to access &quot;{{ $propertyName }}&quot; on
        <strong>{{ $requestDate }}</strong>, please note that you are required to scan in
        and scan out on the day you are accessing the land.
    @elseif ($request->status === 'Accepted')
        Your request has been accepted to access &quot;{{ $propertyName }}&quot; on
        <strong>{{ $requestDate }}</strong>. There is no need to scan in and scan out on
        this property, please follow the rules as outlined and enjoy freely!
    @elseif ($decision === 'deleted')
        Your request for the property &mdash; <strong>{{ $propertyName }}</strong> on
        &quot;{{ $requestDate }}&quot; has timed out and {{ $decision }} by the land owner.
    @else
        Your request for the property &mdash; <strong>{{ $propertyName }}</strong> on
        &quot;<strong>{{ $requestDate }}</strong>&quot; has been {{ $decision }} by the
        land owner.
    @endif
</p>

@if (! empty($request->landowner_comment))
    <p>
        <strong>Land owner&rsquo;s comment:</strong><br>
        {{ $request->landowner_comment }}
    </p>
@endif

@if ($request->status === 'Accepted' && ! empty($rules))
    <p>
        <strong>Land owner&rsquo;s rules &amp; regulations:</strong><br>
        {{ $rules }}
    </p>
@endif

</x-mail.layout>
