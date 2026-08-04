@php
    $logoSrc = isset($message) ? $message->embed(public_path('tx-logo.png')) : asset('tx-logo.png');
@endphp
<x-mail.branded :logo="$logoSrc" eyebrow="Vendor Assigned" heading="Work Order #{{ $workOrder->work_order_no }}">
    <p style="margin:12px 0;">Hello {{ $owner->name ?: trim($owner->first_name.' '.$owner->last_name) }},</p>
    <p style="margin:12px 0;">
        We have assigned <strong>{{ $vendor->name }}</strong>@if ($vendor->phone) ({{ $vendor->phone }})@endif
        to take care of the repairs at {{ $propertyAddress }} under Work Order #{{ $workOrder->work_order_no }}.
    </p>
    @if (filled($description ?? null))
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;">
        <tr>
            <td style="background-color:#f5f7fa; border-left:4px solid #6cbf3f; border-radius:6px; padding:14px 16px;">
                <div style="font-size:12px; font-weight:bold; color:#0f2c66; letter-spacing:0.5px; text-transform:uppercase; margin:0 0 6px;">Request Details</div>
                <div style="font-size:14px; line-height:1.55; color:#374151;">{!! nl2br(e($description)) !!}</div>
            </td>
        </tr>
    </table>
    @endif
    @if ($includeTenantLine)
    <p style="margin:12px 0;">The vendor will contact the tenant directly to coordinate and schedule the appointment.</p>
    @endif
    @if (!empty($portalLink ?? null))
    <p style="margin:16px 0 10px;">
        Feel free to view your request or send us a message here anytime — no login needed:
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
        <tr>
            <td style="border-radius:6px; background-color:#0f2c66;">
                <a href="{{ $portalLink }}" target="_blank"
                   style="display:inline-block; padding:12px 26px; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:6px;">
                    View Work Order #{{ $workOrder->work_order_no }}
                </a>
            </td>
        </tr>
    </table>
    @endif
    <p style="margin:14px 0 2px;">Thank you,</p>
    <p style="margin:0; font-weight:bold; color:#0f2c66;">- TX Maintenance Team</p>
</x-mail.branded>
