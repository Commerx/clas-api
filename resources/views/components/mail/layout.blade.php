<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <title>{{ $subject ?? 'Canadian Land Access' }}</title>
</head>
{{--
    Inline styles only: mail clients discard a <style> block often enough that
    anything structural has to live on the elements themselves.
--}}
<body style="margin: 0; padding: 0; background: #f4f4f4;
             font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
             font-size: 15px; line-height: 1.6; color: #333;">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: #f4f4f4;">
        <tr>
            <td align="center" style="padding: 24px 12px;">

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="max-width: 600px; background: #ffffff; border-radius: 4px;">
                    <tr>
                        <td style="padding: 24px 28px;">
                            {{ $slot }}
                        </td>
                    </tr>
                </table>

                <p style="max-width: 600px; margin: 16px auto 0; font-size: 12px; color: #888;">
                    Canadian Land Access
                </p>

            </td>
        </tr>
    </table>

</body>
</html>
