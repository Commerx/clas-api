{{--
    The CLAS email shell: black header band, white body, logo, contact block
    and social links, as the Yii application has sent for years.

    Ported from `views/mail/layouts/html.php` in that application so mail from
    this API is indistinguishable from mail already in people's inboxes —
    recipients should not be able to tell which system sent them something.

    **The images are served by the old application.** They live in its webroot
    at /img/mailer/, and the absolute URLs below are built from
    `clas.legacy_web_url`. That holds for as long as that application is up,
    which covers the overlap — but the day it is retired, every one of these
    turns into a broken image in mail already delivered. Moving them onto this
    API's host, or a CDN, belongs with the sunset work, and it has to happen
    before the old site goes dark rather than after.

    Inline styles and tables throughout: mail clients discard <style> blocks
    and ignore most modern layout, so nothing structural can live in CSS.
--}}
@php
    $base = rtrim((string) config('clas.legacy_web_url'), '/');
    $support = 'support@canadianlandaccess.com';
@endphp
<!DOCTYPE html>
<html lang="en" style="font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 100%; line-height: 1.6; margin: 0; padding: 0;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <title>{{ $subject ?? 'Canadian Land Access' }}</title>
</head>
<body style="margin: 0; padding: 0; background: #F6F6F6;">

<table width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#F6F6F6" align="center">
<tbody>
<tr>
<td>

    <table width="600" border="0" cellspacing="0" cellpadding="0" align="center" style="max-width: 600px; padding: 50px 0;">

        <thead>
        <tr>
            <td height="130" bgcolor="#000000" valign="middle"
                style="border: 1px solid #F0F0F0; text-align: center;">
                <img src="{{ $base }}/img/mailer/clas-email-header.png"
                     alt="Canadian Land Access" style="border: 0;">
            </td>
        </tr>
        </thead>

        <tbody>
        <tr>
            <td bgcolor="#FFFFFF"
                style="padding: 45px; border: 1px solid #F0F0F0;
                       font-family: 'Lucida Grande', 'Lucida Sans Unicode', 'Lucida Sans', 'DejaVu Sans', Verdana, sans-serif;
                       color: #4F4F4F; font-size: 14px; line-height: 20px;">

                {{ $slot }}

                <p>
                    <img src="{{ $base }}/img/logo.jpg" alt="CLAS"
                         style="width: 60px; padding: 10px 23px; background: #000000; border: 0;">
                    <br>
                    Find your Place.
                </p>

                <p>
                    <a href="mailto:{{ $support }}" style="color: #4F4F4F;">{{ $support }}</a><br>
                    1-844-857-2527 (CLAS)<br>
                    CLAS.APP<br>
                </p>

                <table border="0" cellspacing="0" cellpadding="0">
                <tbody>
                <tr>
                    <td style="padding-right: 20px;">
                        <a href="https://www.facebook.com/CLASCANADA/?fref=ts" target="_blank">
                            <img src="{{ $base }}/img/mailer/facebook-2.png" width="40" alt="Facebook" style="border: 0;">
                        </a>
                    </td>
                    <td style="padding-right: 20px;">
                        <a href="https://www.instagram.com/CLASCANADA/" target="_blank">
                            <img src="{{ $base }}/img/mailer/instagram-2.png" width="40" alt="Instagram" style="border: 0;">
                        </a>
                    </td>
                    <td style="padding-right: 20px;">
                        <a href="https://twitter.com/CLASCANADA" target="_blank">
                            <img src="{{ $base }}/img/mailer/twitter-2.png" width="40" alt="Twitter" style="border: 0;">
                        </a>
                    </td>
                </tr>
                </tbody>
                </table>

            </td>
        </tr>
        </tbody>

        <tfoot>
        <tr>
            <td align="center"
                style="padding: 50px 0;
                       font-family: 'Lucida Grande', 'Lucida Sans Unicode', 'Lucida Sans', 'DejaVu Sans', Verdana, sans-serif;
                       color: #4F4F4F; font-size: 13px; line-height: 20px;">
                &copy; Canadian Land Access {{ date('Y') }}.
            </td>
        </tr>
        </tfoot>

    </table>

</td>
</tr>
</tbody>
</table>

</body>
</html>
