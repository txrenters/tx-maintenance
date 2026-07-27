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
    <title>New Job Assignment</title>
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
                            <div style="font-size:12px; color:#2563EA; font-weight:bold; letter-spacing:0.6px; text-transform:uppercase;">New Job Assignment</div>
                            <div style="font-size:22px; font-weight:bold; color:#0f2c66; margin-top:2px;">Job #{{ $jobNumber }}</div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:10px 28px 4px; font-size:14px; line-height:1.55; color:#374151;">
                            <p style="margin:12px 0;">Dear {{ $vendorName }},</p>
                            <p style="margin:12px 0;">You have been assigned the job below. Please contact the client within <strong>2 business days</strong> to schedule a time for the work, and keep us updated on scheduling, progress and estimates.</p>
                            <p style="margin:12px 0;">Please take <strong>before and after pictures of ALL repairs</strong> and upload them, along with your invoice, using the link below. We cannot process an invoice for payment without photos.</p>
                        </td>
                    </tr>

                    <!-- Job details -->
                    <tr>
                        <td style="padding:6px 28px 4px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7eb; border-radius:8px; font-size:14px; color:#374151;">
                                <tr>
                                    <td style="padding:10px 14px; background-color:#f8fafc; font-weight:bold; width:38%; border-bottom:1px solid #e4e7eb;">Job</td>
                                    <td style="padding:10px 14px; border-bottom:1px solid #e4e7eb;">{{ $title }}</td>
                                </tr>
                                @if($propertyAddress)
                                    <tr>
                                        <td style="padding:10px 14px; background-color:#f8fafc; font-weight:bold; border-bottom:1px solid #e4e7eb;">Property</td>
                                        <td style="padding:10px 14px; border-bottom:1px solid #e4e7eb;">{{ $propertyAddress }}</td>
                                    </tr>
                                @endif
                                @if($clientName)
                                    <tr>
                                        <td style="padding:10px 14px; background-color:#f8fafc; font-weight:bold; border-bottom:1px solid #e4e7eb;">Client</td>
                                        <td style="padding:10px 14px; border-bottom:1px solid #e4e7eb;">
                                            {{ $clientName }}@if($clientPhone) &middot; {{ $clientPhone }}@endif
                                        </td>
                                    </tr>
                                @endif
                                @if($startAt)
                                    <tr>
                                        <td style="padding:10px 14px; background-color:#f8fafc; font-weight:bold; border-bottom:1px solid #e4e7eb;">Scheduled</td>
                                        <td style="padding:10px 14px; border-bottom:1px solid #e4e7eb;">{{ $startAt }}</td>
                                    </tr>
                                @endif
                                @if($instructions)
                                    <tr>
                                        <td style="padding:10px 14px; background-color:#f8fafc; font-weight:bold; vertical-align:top;">Scope of work</td>
                                        <td style="padding:10px 14px;">{{ strip_tags($instructions) }}</td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                    </tr>

                    <!-- CTA button -->
                    @if($portalUrl)
                        <tr>
                            <td align="center" style="padding:20px 28px 6px;">
                                <a href="{{ $portalUrl }}" style="display:inline-block; background-color:#2563EA; color:#ffffff; text-decoration:none; padding:12px 26px; border-radius:6px; font-size:15px; font-weight:bold;">Open Job &amp; Upload Photos</a>
                                <div style="font-size:12px; color:#6b7280; margin-top:10px;">No login required &mdash; this link is just for you.</div>
                            </td>
                        </tr>
                    @endif

                    <!-- Footer -->
                    <tr>
                        <td style="padding:22px 28px 26px; font-size:13px; line-height:1.55; color:#374151;">
                            <p style="margin:12px 0;">Thank you,<br>Texas Renters Maintenance</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
