@php
    $logoSrc = isset($message) ? $message->embed(public_path('tx-logo.png')) : asset('tx-logo.png');
@endphp
<x-mail.branded :logo="$logoSrc" eyebrow="Vendor Assigned" heading="Work Order #{{ $workOrder->work_order_no }}">
    <p style="margin:12px 0;">Hello {{ $owner->name ?: trim($owner->first_name.' '.$owner->last_name) }},</p>
    <p style="margin:12px 0;">
        We have assigned <strong>{{ $vendor->name }}</strong>@if ($vendor->phone) ({{ $vendor->phone }})@endif
        to handle the repairs at {{ $propertyAddress }} under Work Order #{{ $workOrder->work_order_no }}.
    </p>
    @if ($includeTenantLine)
    <p style="margin:12px 0;">The vendor will contact the tenant directly to coordinate and schedule the appointment.</p>
    @endif
    @if (!empty($portalLink ?? null))
    <p style="margin:16px 0 8px;">
        Feel free to view your request or send us a message here anytime — no login needed:
    </p>
    <p style="margin:0 0 16px;">
        <a href="{{ $portalLink }}" style="display:inline-block; background:#0f2c66; color:#ffffff; text-decoration:none; padding:10px 18px; border-radius:6px; font-weight:bold;">
            View Work Order #{{ $workOrder->work_order_no }}
        </a>
    </p>
    @endif
    <p style="margin:14px 0 2px;">Thank you,</p>
    <p style="margin:0; font-weight:bold; color:#0f2c66;">- TX Maintenance Team</p>
</x-mail.branded>
