{{--
    A reported issue, sent to the admin address.
    Ported from views/mail/report-issue.php, which echoed every field
    unescaped; Blade escapes these, which is the point.
--}}
<x-mail.layout :subject="$subject">

<p>
    Hi CLAS Admin,<br>

    {{ $reporterName }} ({{ $reporterEmail }}) has reported the issue below
    today ({{ now()->format('M d, Y') }}).
</p>

<p>
    <strong>Subject:</strong> {{ $issueSubject }}<br>
    <strong>Issue Type:</strong> {{ $issueType }}<br>
    <strong>Message Body:</strong><br>
    {!! nl2br(e($body)) !!}
</p>

</x-mail.layout>
