@php
    // Rendered to HTML and sent through Graph as a reply, so there is never a
    // mail message to embed the logo into; the hosted URL is the only option.
    $logoSrc = asset('tx-logo.png');
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Please upload invoices in the Vendor Portal</title>
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
                            <div style="font-size:12px; color:#2563EA; font-weight:bold; letter-spacing:0.6px; text-transform:uppercase;">Vendor Invoices</div>
                            <div style="font-size:22px; font-weight:bold; color:#0f2c66; margin-top:2px;">Please upload invoices in the Vendor Portal</div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:10px 28px 4px; font-size:16px; line-height:1.55; color:#374151;">
                            <p style="margin:12px 0;">Hello{{ filled($vendorName) ? ' '.$vendorName : '' }},</p>
                            <p style="margin:12px 0;">Thank you for sending your invoice. <strong>This mailbox does not process invoices</strong>, and files sent here are not forwarded for payment.</p>
                            <p style="margin:12px 0;">Please upload your invoice through the TexasRenters Vendor Portal instead. <strong>No login is needed.</strong>
                                @if($dashboardUrl)
                                    Your personal link below lists all of your work orders, and each one has an Upload Invoice button.
                                @else
                                    Open the work order link from the service request email or text you received for that job, then use its Upload Invoice button.
                                @endif
                            </p>
                        </td>
                    </tr>

                    <!-- CTA button -->
                    @if($dashboardUrl)
                    <tr>
                        <td align="center" style="padding:16px 28px 8px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-radius:8px; background-color:#2563EA;">
                                        <a href="{{ $dashboardUrl }}" target="_blank"
                                           style="display:inline-block; padding:13px 30px; font-size:16px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">
                                            Open My Vendor Portal
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <div style="font-size:12px; color:#6b7280; margin-top:10px; word-break:break-all;">
                                Or copy this link: <a href="{{ $dashboardUrl }}" style="color:#2563EA;">{{ $dashboardUrl }}</a>
                            </div>
                        </td>
                    </tr>
                    @else
                    <tr>
                        <td style="padding:8px 28px 4px; font-size:16px; line-height:1.55; color:#374151;">
                            <p style="margin:12px 0;">If you cannot find your work order link, reply to that service request email or text and we will send it again.</p>
                        </td>
                    </tr>
                    @endif

                    <!-- Going forward -->
                    <tr>
                        <td style="padding:4px 28px 8px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fff7ed; border-left:4px solid #f97316; border-radius:6px;">
                                <tr>
                                    <td style="padding:12px 16px; font-size:15px; color:#374151;">
                                        <strong style="color:#9a3412;">Going forward:</strong>
                                        please use the Vendor Portal for all invoices rather than this email address. Invoices are paid on the <strong>1st and 15th</strong> of the month.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Sign off -->
                    <tr>
                        <td style="padding:8px 28px 20px; font-size:16px; line-height:1.55; color:#374151;">
                            <p style="margin:14px 0 0; font-weight:bold; color:#0f2c66;">texasrenters.com</p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f5f7fa; padding:16px 28px; border-top:1px solid #e5e7eb;">
                            <div style="font-size:11px; color:#9aa5b1; line-height:1.5;">
                                This is an automated reply from the TexasRenters invoices mailbox.<br>
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
