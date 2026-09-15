@php
    $logoSrc = isset($message) ? $message->embed(public_path('tx-logo.png')) : asset('tx-logo.png');
@endphp
<x-mail.branded :logo="$logoSrc" eyebrow="Invoice Uploaded" heading="Work Order #{{ $workOrder->work_order_no }}">
    <p style="margin:12px 0;">Hello Operation Accounting,</p>
    <p style="margin:12px 0;">
        An invoice has been uploaded for Turnover Work Order #{{ $workOrder->work_order_no }}.
        @if ($attached)
            The invoice file is attached to this email.
        @else
            The invoice file was too large to attach — you can download it from the work order page below.
        @endif
    </p>

    <table cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; margin:16px 0; font-size:13px;">
        @foreach ([
            'Work Order' => '#'.$workOrder->work_order_no,
            'Property' => $workOrder->building?->name ?: '—',
            'Address' => collect([$workOrder->building?->address, $workOrder->building?->city, $workOrder->building?->state_region, $workOrder->building?->postal_code])->filter()->implode(', ') ?: '—',
            'Type' => trim((string) $workOrder->type) ?: '—',
            'Category' => trim((string) $workOrder->category) ?: '—',
            'Status' => $workOrder->service_status?->name ?: $workOrder->status ?: '—',
            'Description' => $workOrder->description ?: '—',
            'Vendor' => $vendor?->name ?: '—',
            'Invoice' => $invoice->title,
            'Invoice #' => $invoice->invoice_number ?: '—',
            'Amount' => '$'.number_format((float) $invoice->amount, 2),
            'Uploaded' => $invoice->created_at?->format('M j, Y g:i A'),
        ] as $label => $value)
        <tr>
            <td style="border:1px solid #e5e7eb; padding:7px 10px; background-color:#f5f7fa; color:#0f2c66; font-weight:bold; white-space:nowrap;">{{ $label }}</td>
            <td style="border:1px solid #e5e7eb; padding:7px 10px; color:#374151;">{{ $value }}</td>
        </tr>
        @endforeach
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0;">
        <tr>
            <td style="border-radius:8px; background-color:#2563EA;">
                <a href="{{ $workOrderUrl }}" target="_blank"
                   style="display:inline-block; padding:13px 30px; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">
                    Open Work Order #{{ $workOrder->work_order_no }}
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:14px 0 2px;">Thank you,</p>
    <p style="margin:0; font-weight:bold; color:#0f2c66;">TexasRenters.com Maintenance</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 4px; background-color:#f5f7fa; border-left:4px solid #9aa5b1; border-radius:6px;">
        <tr>
            <td style="padding:10px 14px; font-size:12px; color:#6b7280;">
                This is an automated notification &mdash; please do not reply to this email. Replies are not monitored.
            </td>
        </tr>
    </table>
</x-mail.branded>
