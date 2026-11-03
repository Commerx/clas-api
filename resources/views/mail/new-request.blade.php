{{--
    Ported from views/mail/send-request.php.

    The old template had Accept and Deny buttons linking into the web
    application. They are left out deliberately: those links act on behalf of
    whoever opens them with no further check, which is the same weakness as the
    claim-code flow. An owner decides through the app.
--}}
<x-mail.layout :subject="$subject">

<p>
    Hi {{ $ownerName }},<br>
    You have received a request for your property &mdash; <strong>{{ $propertyName }}</strong>
    from {{ $requesterName }}. The details are below.
</p>

<p><strong>Reason for requesting:</strong> {{ $request->reason }}</p>

@if (! empty($request->description))
    <p><strong>Description of the request:</strong><br>{{ $request->description }}</p>
@endif

<p><strong>Request date:</strong> {{ $requestDates }}</p>

@if (! empty($request->people_count))
    <p><strong>Number of people the requester is bringing:</strong> {{ $request->people_count }}</p>
@endif

@if (! empty($request->people_details))
    <p><strong>People details:</strong><br>{!! nl2br(e($request->people_details)) !!}</p>
@endif

<p>Open the app to accept or decline this request.</p>

</x-mail.layout>
