@php
    // Inline-embed the logo (CID) on a real send so it always displays; fall
    // back to the hosted URL when the view is rendered without a mail message.
    $logoSrc = isset($message) ? $message->embed(public_path('tx-logo.png')) : asset('tx-logo.png');
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Service Request</title>
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
                            <div style="font-size:12px; color:#2563EA; font-weight:bold; letter-spacing:0.6px; text-transform:uppercase;">New Service Request</div>
                            <div style="font-size:22px; font-weight:bold; color:#0f2c66; margin-top:2px;">Work Order #{{ $workOrderNo }}</div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:10px 28px 4px; font-size:14px; line-height:1.55; color:#374151;">
                            <p style="margin:12px 0;">Dear {{ $vendorName }},</p>
                            <p style="margin:12px 0;">Please see the attached service request.</p>
                            <p style="margin:12px 0;">Please be sure to contact the tenants within <strong>2 business hours</strong> of receipt of this request to schedule a time for repairs. To avoid trip charges, we advise scheduling with the tenants via email so you have written proof they confirmed the appointment time. If written proof cannot be provided and tenants are not home at the scheduled time, we cannot charge trip charges. As always, please keep Texas Renters updated on all progress (scheduling, progress, estimates, etc.).</p>
                            <p style="margin:12px 0;">Please take <strong>before and after pictures of ALL repairs</strong>. Upload them through your maintenance dashboard upon completion, and title each photo with the property address. We cannot process an invoice for payment without photos.</p>
                        </td>
                    </tr>

                    <!-- CTA button -->
                    @if($portalUrl)
                    <tr>
                        <td align="center" style="padding:16px 28px 8px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-radius:8px; background-color:#2563EA;">
                                        <a href="{{ $portalUrl }}" target="_blank"
                                           style="display:inline-block; padding:13px 30px; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">
                                            Open Work Order &amp; Upload Photos
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <div style="font-size:11px; color:#9aa5b1; margin-top:8px;">Manage this work order in your vendor portal</div>
                        </td>
                    </tr>
                    @endif

                    <!-- Secondary notes -->
                    <tr>
                        <td style="padding:8px 28px 4px; font-size:14px; line-height:1.55; color:#374151;">
                            <p style="margin:12px 0;">If you have any questions or issues reaching or communicating with the tenants, please contact us and let us know.</p>
                        </td>
                    </tr>

                    <!-- Payment policy callout -->
                    <tr>
                        <td style="padding:4px 28px 8px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7fa; border-left:4px solid #2563EA; border-radius:6px;">
                                <tr>
                                    <td style="padding:12px 16px; font-size:13px; color:#374151;">
                                        <strong style="color:#0f2c66;">NOTE TO OUR VENDORS:</strong>
                                        Texas Renters payment policy — invoices are paid on the <strong>1st and 15th</strong> of the month. Thank you.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Sign off -->
                    <tr>
                        <td style="padding:8px 28px 20px; font-size:14px; line-height:1.55; color:#374151;">
                            <p style="margin:14px 0 2px;">Best regards,</p>
                            <p style="margin:0; font-weight:bold; color:#0f2c66;">The TexasRenters.com Property Management Team</p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f5f7fa; padding:16px 28px; border-top:1px solid #e5e7eb;">
                            <div style="font-size:11px; color:#9aa5b1; line-height:1.5;">
                                This is an automated email notification, please do not respond.<br>
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
