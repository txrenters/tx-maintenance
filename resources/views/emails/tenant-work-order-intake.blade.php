@php
    $logoSrc = isset($message) ? $message->embed(public_path('tx-logo.png')) : asset('tx-logo.png');
    $reference = $workOrder->work_order_no ?? $workOrder->id;
    // Our team entered the work order in PropertyWare, rather than the tenant
    // submitting it: say so instead of confirming a request they never sent.
    $staffCreated = $staffCreated ?? false;
    // A tenant easy fix ({kind: easy_fix, label, video_url, tip}) or a
    // tenant-owned appliance ({kind: appliance, label}): the how-to video or
    // the responsibility note instead of "we received your request".
    $easyFix = $easyFix ?? null;
    $isEasyFix = $easyFix !== null && ($easyFix['kind'] ?? null) === 'easy_fix';
    $isAppliance = $easyFix !== null && ($easyFix['kind'] ?? null) === 'appliance';
    $eyebrow = $staffCreated ? 'New Work Order' : ($isEasyFix ? 'A Quick Fix You Can Try' : ($isAppliance ? 'About Your Service Request' : 'Service Request Received'));
@endphp
<x-mail.branded :logo="$logoSrc" :eyebrow="$eyebrow" heading="Work Order #{{ $reference }}">
    <p style="margin:12px 0;">{{ $tenantName ? 'Hi '.$tenantName.',' : 'Hello,' }}</p>
    @if($staffCreated)
    <p style="margin:12px 0;">
        This is TexasRenters.com Maintenance. A work order has been created{{ $property ? ' for '.$property : '' }}
        by our team. Our team will review the request and coordinate the
        necessary next steps, and we will contact you regarding scheduling or
        access if needed.
    </p>
    @elseif($isEasyFix)
    <p style="margin:12px 0;">
        This is TexasRenters.com Maintenance. We received your request about the
        {{ $easyFix['label'] }}{{ $property ? ' at '.$property : '' }}. This is
        usually a quick fix tenants can take care of themselves, so we wanted to
        save you the wait (and a service call charge).
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0;">
        <tr>
            <td style="border-radius:8px; background-color:#0284c7;">
                <a href="{{ $easyFix['video_url'] }}" target="_blank"
                   style="display:inline-block; padding:13px 30px; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">
                    Watch the how-to video
                </a>
            </td>
        </tr>
    </table>

    <p style="font-size:12px; color:#6b7280; margin:12px 0;">
        If the button does not work, copy and paste this link into your browser:<br>
        {{ $easyFix['video_url'] }}
    </p>

    @if(!empty($easyFix['tip']))
    <p style="margin:12px 0;">{{ $easyFix['tip'] }}</p>
    @endif

    <p style="margin:12px 0;">
        If it still is not working after you try this, reply to this email or
        add a photo through your link below and we will take it from there.
    </p>
    @elseif($isAppliance)
    <p style="margin:12px 0;">
        This is TexasRenters.com Maintenance. We received your request about the
        {{ $easyFix['label'] }}{{ $property ? ' at '.$property : '' }}. Our
        records show it is not a property-provided appliance, so its repair or
        replacement is the tenant's responsibility under the lease.
    </p>
    <p style="margin:12px 0;">
        If you believe this is incorrect, reply to this email and we will
        double-check.
    </p>
    @else
    <p style="margin:12px 0;">
        This is TexasRenters.com Maintenance confirming that we have received
        your service request{{ $property ? ' for '.$property : '' }}. We are
        reviewing it now and will keep you updated as it moves forward.
    </p>
    @endif

    @if($workOrder->description)
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:16px 0; background-color:#f5f7fa; border-radius:8px;">
        <tr>
            <td style="padding:14px 16px;">
                <div style="font-size:12px; color:#6b7280; font-weight:bold; text-transform:uppercase; letter-spacing:0.4px;">{{ $staffCreated ? 'Work order description' : 'What you told us' }}</div>
                <div style="margin-top:6px; font-size:14px; color:#374151; white-space:pre-line;">{{ $workOrder->description }}</div>
            </td>
        </tr>
    </table>
    @endif

    @if($portalLink)
    <p style="margin:12px 0;">
        You can check on your request, send us a message, or add photos of the
        issue at any time — no login needed:
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0;">
        <tr>
            <td style="border-radius:8px; background-color:#2563EA;">
                <a href="{{ $portalLink }}" target="_blank"
                   style="display:inline-block; padding:13px 30px; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">
                    View Work Order #{{ $reference }}
                </a>
            </td>
        </tr>
    </table>

    <p style="font-size:12px; color:#6b7280; margin:12px 0;">
        If the button does not work, copy and paste this link into your browser:<br>
        {{ $portalLink }}
    </p>
    @endif

    @if($staffCreated)
    <p style="margin:12px 0;">
        If you have noticed the issue yourself, photos help us get the right
        person out the first time — you can add them through the link above.
    </p>
    @elseif(!$isEasyFix && !$isAppliance)
    <p style="margin:12px 0;">
        Photos of the issue help us get the right person out the first time, so
        please add them if you can.
    </p>
    @endif

    @if($coordinator)
    <p style="margin:12px 0; font-size:13px; color:#6b7280;">
        Your work order coordinator is {{ $coordinator }}.
    </p>
    @endif

    <p style="margin:14px 0 2px;">Thank you,</p>
    <p style="margin:0; font-weight:bold; color:#0f2c66;">TexasRenters.com Maintenance</p>
</x-mail.branded>
