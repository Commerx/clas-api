{{--
    A reported notification, sent to the admin address.
    Ported from views/mail/report-notification-to-admin.php.
--}}
<x-mail.layout :subject="$subject">

<p>
    Hi CLAS Admin,<br>

    {{ $reporterName }} has reported a notification sent by
    &quot;{{ $posterName }}&quot;.
</p>

<p>
    <strong>Reason:</strong><br>
    {!! nl2br(e($reason)) !!}
</p>

{{-- The old mail named the poster but not the message, so an administrator
     had to go looking for which notification was meant. --}}
<p>
    <strong>Reported notification (#{{ $notificationId }}):</strong><br>
    {!! nl2br(e($notificationBody)) !!}
</p>

<p>
    Please review this report in detail by logging into the admin panel.
</p>

</x-mail.layout>
