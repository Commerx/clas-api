{{--
    The password reset link.
    Ported from views/mail/recovery.php.
--}}
<x-mail.layout :subject="$subject">

<p>
    Hi {{ $name }},
</p>

<p>
    We have received a request to reset the password for your Canadian Land
    Access Systems account. Click the link below to choose a new one.
</p>

<p>
    <a href="{{ $url }}">{{ $url }}</a>
</p>

<p>
    This link stops working in {{ $hours }} hours. If you cannot click it,
    please copy and paste it into your browser.
</p>

<p>
    If you did not ask to reset your password, you can ignore this email and
    your password will stay as it is.
</p>

</x-mail.layout>
