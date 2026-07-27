@props([
    'logo' => null,
    'eyebrow' => null,
    'heading' => null,
])
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading ?: 'TexasRenters.com Maintenance' }}</title>
</head>
<body style="margin:0; padding:0; background-color:#eef1f6; font-family:Arial, Helvetica, sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef1f6;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:100%; background-color:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 1px 4px rgba(15,44,102,0.12);">

                    <!-- Header -->
                    <tr>
                        <td style="background-color:#ffffff; padding:24px 28px 18px; text-align:center; border-bottom:3px solid #6cbf3f;">
                            @if($logo)
                                <img src="{{ $logo }}" alt="TexasRenters.com" style="height:38px; width:auto;">
                            @else
                                <div style="font-size:20px; font-weight:bold; color:#0f2c66;">TexasRenters.com <span style="color:#6cbf3f;">Maintenance</span></div>
                            @endif
                        </td>
                    </tr>

                    <!-- Title band -->
                    @if($eyebrow || $heading)
                    <tr>
                        <td style="padding:22px 28px 6px;">
                            @if($eyebrow)
                                <div style="font-size:12px; color:#2563EA; font-weight:bold; letter-spacing:0.6px; text-transform:uppercase;">{{ $eyebrow }}</div>
                            @endif
                            @if($heading)
                                <div style="font-size:22px; font-weight:bold; color:#0f2c66; margin-top:2px;">{{ $heading }}</div>
                            @endif
                        </td>
                    </tr>
                    @endif

                    <!-- Body -->
                    <tr>
                        <td style="padding:12px 28px 4px; font-size:14px; line-height:1.55; color:#374151;">
                            {{ $slot }}
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
