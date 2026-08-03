@php
    $logoSrc = isset($message) ? $message->embed(public_path('tx-logo.png')) : asset('tx-logo.png');
@endphp
<x-mail.branded :logo="$logoSrc" eyebrow="HOA Violation Corrected" heading="Work Order #{{ $workOrder->work_order_no ?? $workOrder->id }}">
    <p style="margin:12px 0;">Hello,</p>
    <p style="margin:12px 0;">
        This is a confirmation from TexasRenters.com Maintenance that the HOA
        violation {{ $property ? 'at '.$property.' ' : '' }}(Work Order
        #{{ $workOrder->work_order_no ?? $workOrder->id }}) has been
        <strong style="color:#0f2c66;">corrected</strong>.
    </p>
    <p style="margin:12px 0;">You can view the photos of the completed work using the secure link below:</p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0;">
        <tr>
            <td style="border-radius:8px; background-color:#2563EA;">
                <a href="{{ $galleryUrl }}" target="_blank"
                   style="display:inline-block; padding:13px 30px; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">
                    View the photos
                </a>
            </td>
        </tr>
    </table>

    <p style="font-size:12px; color:#6b7280; margin:12px 0;">
        If the button does not work, copy and paste this link into your browser:<br>
        {{ $galleryUrl }}
    </p>

    @if(!empty($portalLink ?? null))
    <p style="margin:16px 0 8px; border-top:1px solid #e5e7eb; padding-top:14px;">
        You can also view this request or send us a message here anytime — no login needed:
    </p>
    <p style="margin:0 0 12px;">
        <a href="{{ $portalLink }}" style="color:#2563EA; text-decoration:none; font-weight:bold;">
            View Work Order #{{ $workOrder->work_order_no ?? $workOrder->id }}
        </a>
    </p>
    @endif

    <p style="margin:14px 0 2px;">Thank you,</p>
    <p style="margin:0; font-weight:bold; color:#0f2c66;">TexasRenters.com Maintenance</p>
</x-mail.branded>
