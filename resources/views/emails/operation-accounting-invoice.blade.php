<!DOCTYPE html>
<html lang="en">
<body>
    <p>Hello Operation Accounting,</p>
    <p>
        An invoice has been uploaded for Turnover Work Order #{{ $workOrder->work_order_no }}.
        @if ($attached)
            The invoice file is attached to this email.
        @else
            The invoice file was too large to attach — you can download it from the work order page below.
        @endif
    </p>

    <table cellpadding="6" cellspacing="0" border="0" style="border-collapse: collapse;">
        <tr>
            <td style="border: 1px solid #ddd;"><strong>Work Order</strong></td>
            <td style="border: 1px solid #ddd;">#{{ $workOrder->work_order_no }}</td>
        </tr>
        <tr>
            <td style="border: 1px solid #ddd;"><strong>Property</strong></td>
            <td style="border: 1px solid #ddd;">{{ $workOrder->building?->name ?: '—' }}</td>
        </tr>
        <tr>
            <td style="border: 1px solid #ddd;"><strong>Address</strong></td>
            <td style="border: 1px solid #ddd;">{{ collect([$workOrder->building?->address, $workOrder->building?->city, $workOrder->building?->state_region, $workOrder->building?->postal_code])->filter()->implode(', ') ?: '—' }}</td>
        </tr>
        <tr>
            <td style="border: 1px solid #ddd;"><strong>Type</strong></td>
            <td style="border: 1px solid #ddd;">{{ trim((string) $workOrder->type) ?: '—' }}</td>
        </tr>
        <tr>
            <td style="border: 1px solid #ddd;"><strong>Category</strong></td>
            <td style="border: 1px solid #ddd;">{{ trim((string) $workOrder->category) ?: '—' }}</td>
        </tr>
        <tr>
            <td style="border: 1px solid #ddd;"><strong>Status</strong></td>
            <td style="border: 1px solid #ddd;">{{ $workOrder->service_status?->name ?: $workOrder->status ?: '—' }}</td>
        </tr>
        <tr>
            <td style="border: 1px solid #ddd;"><strong>Description</strong></td>
            <td style="border: 1px solid #ddd;">{{ $workOrder->description ?: '—' }}</td>
        </tr>
        <tr>
            <td style="border: 1px solid #ddd;"><strong>Vendor</strong></td>
            <td style="border: 1px solid #ddd;">{{ $vendor?->name ?: '—' }}</td>
        </tr>
        <tr>
            <td style="border: 1px solid #ddd;"><strong>Invoice</strong></td>
            <td style="border: 1px solid #ddd;">{{ $invoice->title }}</td>
        </tr>
        <tr>
            <td style="border: 1px solid #ddd;"><strong>Amount</strong></td>
            <td style="border: 1px solid #ddd;">${{ number_format((float) $invoice->amount, 2) }}</td>
        </tr>
        <tr>
            <td style="border: 1px solid #ddd;"><strong>Uploaded</strong></td>
            <td style="border: 1px solid #ddd;">{{ $invoice->created_at?->format('M j, Y g:i A') }}</td>
        </tr>
    </table>

    <p><a href="{{ $workOrderUrl }}">Open Work Order #{{ $workOrder->work_order_no }}</a></p>

    <p>Thank you,<br>TexasRenters.com Maintenance</p>
</body>
</html>
