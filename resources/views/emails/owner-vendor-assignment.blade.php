@php
    // Inline-embed the logo (CID) on a real send so it always displays; fall
    // back to the hosted URL when the view is rendered without a mail message.
    $logoSrc = isset($message) ? $message->embed(public_path('tx-logo.png')) : asset('tx-logo.png');
    $ownerName = $owner->name ?: trim($owner->first_name.' '.$owner->last_name);
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Assigned</title>
</head>
<body style="margin:0; padding:0; background-color:#eef1f6; font-family:Arial, Helvetica, sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef1f6;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:100%; background-color:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 1px 4px rgba(15,44,102,0.12);">

                    <!-- Header -->
                    <tr>
                        <td style="background-color:#ffffff; padding:24px 28px 18px; text-align:center; border-bottom:3px solid #6cbf3f;">
                            <img src="{{ $logoSrc }}" alt="TexasRenters.com" style="height:38px; width:auto;">
                        </td>
                    </tr>

                    <!-- Title band -->
                    <tr>
                        <td style="padding:22px 28px 6px;">
                            <div style="font-size:12px; color:#2563EA; font-weight:bold; letter-spacing:0.6px; text-transform:uppercase;">Vendor Assigned</div>
                            <div style="font-size:22px; font-weight:bold; color:#0f2c66; margin-top:2px;">Work Order #{{ $workOrder->work_order_no }}</div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:10px 28px 4px; font-size:14px; line-height:1.55; color:#374151;">
                            <p style="margin:12px 0;">Dear {{ $ownerName }},</p>
                            <p style="margin:12px 0;">We have assigned a vendor to handle the repairs to your property at <strong>{{ $propertyAddress }}</strong> under Work Order #{{ $workOrder->work_order_no }}.</p>
                            <p style="margin:12px 0;">The vendor will contact the tenant directly to coordinate and schedule the appointment. We will keep you updated as the work progresses.</p>
                        </td>
                    </tr>

                    <!-- Assignment details callout -->
                    <tr>
                        <td style="padding:4px 28px 8px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7fa; border-left:4px solid #2563EA; border-radius:6px;">
                                <tr>
                                    <td style="padding:14px 16px; font-size:13px; color:#374151;">
                                        <div style="font-size:11px; color:#0f2c66; font-weight:bold; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:8px;">Assignment Details</div>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="padding:3px 0; font-size:13px; color:#6b7280; width:120px; vertical-align:top;">Vendor</td>
                                                <td style="padding:3px 0; font-size:13px; color:#1f2933; font-weight:bold;">{{ $vendor->name }}</td>
                                            </tr>
                                            @if($vendor->phone)
                                            <tr>
                                                <td style="padding:3px 0; font-size:13px; color:#6b7280; vertical-align:top;">Vendor Phone</td>
                                                <td style="padding:3px 0; font-size:13px; color:#1f2933;">{{ $vendor->phone }}</td>
                                            </tr>
                                            @endif
                                            <tr>
                                                <td style="padding:3px 0; font-size:13px; color:#6b7280; vertical-align:top;">Property</td>
                                                <td style="padding:3px 0; font-size:13px; color:#1f2933;">{{ $propertyAddress }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:3px 0; font-size:13px; color:#6b7280; vertical-align:top;">Work Order</td>
                                                <td style="padding:3px 0; font-size:13px; color:#1f2933;">#{{ $workOrder->work_order_no }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Sign off -->
                    <tr>
                        <td style="padding:8px 28px 20px; font-size:14px; line-height:1.55; color:#374151;">
                            <p style="margin:14px 0 2px;">Thank you,</p>
                            <p style="margin:0; font-weight:bold; color:#0f2c66;">The TexasRenters.com Maintenance Team</p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f5f7fa; padding:16px 28px; border-top:1px solid #e5e7eb;">
                            <div style="font-size:11px; color:#9aa5b1; line-height:1.5;">
                                <a href="https://www.texasrenters.com/" style="color:#2563EA; text-decoration:none;">TexasRenters.com</a>
                                &middot; 5225 Katy Freeway, Ste 545, Houston, TX 77007
                            </div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
