@component('mail::message')
@php
    $cleanBody = preg_replace('/^[ \t]+/m', '', trim($body));
@endphp
<div style="white-space: pre-line; font-family: inherit;">{{ $cleanBody }}</div>

---

<small><em>This is an automated email. Please do not reply.</em></small>
@endcomponent
