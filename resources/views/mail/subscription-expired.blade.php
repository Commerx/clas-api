{{-- The grace period ran out. --}}
<x-mail.layout :subject="$subject">

<p>
    Your Canadian Land Access Systems subscription has expired.
</p>

<p>
    <strong>{{ $subscriptionName }}</strong> is no longer active, and the
    wildlife management unit and fishing lands access that came with it has
    ended too.
</p>

<p>
    You can subscribe again at any time in the app. Nothing has been deleted:
    your listings, requests and tags are all still there.
</p>

</x-mail.layout>
