{{--
    An invitation to manage a listing.
    Ported from views/mail/manager-invite.php.
--}}
<x-mail.layout :subject="$subject">

<p>
    Hi {{ $managerName }},<br>

    You have received an invitation from {{ $ownerName }} to help manage
    their property, <strong>{{ $listingName }}</strong>.
</p>

<p>Please accept or decline the invitation using one of the links below.</p>

<p>
    <a href="{{ $acceptUrl }}"
       style="background-color:#28a745;padding:10px;border-radius:5px;font-size:16px;color:#ffffff;text-decoration:none;display:inline-block">Accept</a>
    &nbsp;or&nbsp;
    <a href="{{ $declineUrl }}"
       style="background-color:#dc3545;padding:10px;border-radius:5px;font-size:16px;color:#ffffff;text-decoration:none;display:inline-block">Decline</a>
</p>

<p>
    You will need to be signed in to the account this invitation was sent to.
    If you do not have one yet, please create it with this email address
    first.
</p>

</x-mail.layout>
