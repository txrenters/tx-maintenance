@php
    $logoSrc = isset($message) ? $message->embed(public_path('tx-logo.png')) : asset('tx-logo.png');
@endphp
<x-mail.branded :logo="$logoSrc" eyebrow="Approval Needed" heading="Work Order #{{ $workOrder->work_order_no }}">
    {{-- The same editable wording the text message carries, paragraph for paragraph. --}}
    <div style="margin:12px 0;">{!! nl2br(e($body)) !!}</div>
    @if (!empty($portalLink ?? null))
    <p style="margin:16px 0 10px;">
        Review and approve it here - no login needed:
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
        <tr>
            <td style="border-radius:6px; background-color:#0f2c66;">
                <a href="{{ $portalLink }}" target="_blank"
                   style="display:inline-block; padding:12px 26px; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:6px;">
                    Review Work Order #{{ $workOrder->work_order_no }}
                </a>
            </td>
        </tr>
    </table>
    @endif
    <p style="margin:14px 0 2px;">Thank you,</p>
    <p style="margin:0; font-weight:bold; color:#0f2c66;">- TX Maintenance Team</p>
    <p style="margin:10px 0 0; font-size:12px; color:#9aa5b1;">(Ref: WO#{{ $workOrder->work_order_no }})</p>
</x-mail.branded>
