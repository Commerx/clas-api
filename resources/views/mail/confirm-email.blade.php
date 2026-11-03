{{--
    The registration confirmation link.
    Ported from views/mail/confirmation.php.
--}}
<x-mail.layout :subject="$subject">

<p>
    Welcome {{ $name }},
</p>

<p>
    Thank you for becoming a valued user at Canadian Land Access Systems.
    To complete your registration, click to verify your email address.
</p>

<p>
    <a href="{{ $url }}">{{ $url }}</a>
</p>

<p>
    If you cannot click the link, please copy and paste it into your browser.
    If you did not make this request, please ignore this email.
</p>

<p>
    Again, welcome to the gateway of adventures!
</p>

</x-mail.layout>
