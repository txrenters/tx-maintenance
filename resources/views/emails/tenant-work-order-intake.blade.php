@php
    $logoSrc = isset($message) ? $message->embed(public_path('tx-logo.png')) : asset('tx-logo.png');
    $reference = $workOrder->work_order_no ?? $workOrder->id;
@endphp
<x-mail.branded :logo="$logoSrc" eyebrow="Service Request Received" heading="Work Order #{{ $reference }}">
    <p style="margin:12px 0;">{{ $tenantName ? 'Hi '.$tenantName.',' : 'Hello,' }}</p>
    <p style="margin:12px 0;">
        This is TexasRenters.com Maintenance confirming that we have received
        your service request{{ $property ? ' for '.$property : '' }}. We are
        reviewing it now and will keep you updated as it moves forward.
    </p>

    @if($workOrder->description)
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:16px 0; background-color:#f5f7fa; border-radius:8px;">
        <tr>
            <td style="padding:14px 16px;">
                <div style="font-size:12px; color:#6b7280; font-weight:bold; text-transform:uppercase; letter-spacing:0.4px;">What you told us</div>
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

    <p style="margin:12px 0;">
        Photos of the issue help us get the right person out the first time, so
        please add them if you can.
    </p>

    @if($coordinator)
    <p style="margin:12px 0; font-size:13px; color:#6b7280;">
        Your work order coordinator is {{ $coordinator }}.
    </p>
    @endif

    <p style="margin:14px 0 2px;">Thank you,</p>
    <p style="margin:0; font-weight:bold; color:#0f2c66;">TexasRenters.com Maintenance</p>
</x-mail.branded>
