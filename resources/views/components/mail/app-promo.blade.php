{{-- The TexasRenters app for owners and tenants: where to download it and the video on signing up. --}}
@php
    $appStoreUrl = config('client_app.app_store_url');
    $playStoreUrl = config('client_app.play_store_url');
    $appVideoUrl = config('client_app.app_video_url');
    $signupVideoUrl = config('client_app.signup_video_url');
@endphp
@if($appStoreUrl || $playStoreUrl)
<tr>
    <td style="padding:8px 28px 22px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7fa; border-radius:8px;">
            <tr>
                <td style="padding:16px 18px; font-size:14px; line-height:1.55; color:#374151;">
                    <div style="font-size:12px; font-weight:bold; color:#2563EA; letter-spacing:0.6px; text-transform:uppercase;">Now available</div>
                    <div style="font-size:16px; font-weight:bold; color:#0f2c66; margin-top:2px;">Get the TexasRenters app</div>
                    <p style="margin:8px 0 12px;">
                        Follow your maintenance requests, message our team and find your
                        documents from your phone.
                    </p>
                    <table role="presentation" cellpadding="0" cellspacing="0">
                        <tr>
                            @if($appStoreUrl)
                            <td style="border-radius:6px; background-color:#0f2c66;">
                                <a href="{{ $appStoreUrl }}" target="_blank"
                                   style="display:inline-block; padding:10px 18px; font-size:13px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:6px;">
                                    App Store (iPhone)
                                </a>
                            </td>
                            @endif
                            @if($appStoreUrl && $playStoreUrl)
                            <td style="width:10px; font-size:0; line-height:0;">&nbsp;</td>
                            @endif
                            @if($playStoreUrl)
                            <td style="border-radius:6px; background-color:#0f2c66;">
                                <a href="{{ $playStoreUrl }}" target="_blank"
                                   style="display:inline-block; padding:10px 18px; font-size:13px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:6px;">
                                    Google Play (Android)
                                </a>
                            </td>
                            @endif
                        </tr>
                    </table>
                    @if($appVideoUrl)
                    <p style="margin:12px 0 0; font-size:13px;">
                        Want a quick look first?
                        <a href="{{ $appVideoUrl }}" target="_blank" style="color:#2563EA; font-weight:bold; text-decoration:none;">Watch the app video</a>
                    </p>
                    @endif
                    @if($signupVideoUrl)
                    <p style="margin:{{ $appVideoUrl ? '4px' : '12px' }} 0 0; font-size:13px;">
                        New to the app?
                        <a href="{{ $signupVideoUrl }}" target="_blank" style="color:#2563EA; font-weight:bold; text-decoration:none;">Watch how to sign up</a>
                    </p>
                    @endif
                </td>
            </tr>
        </table>
    </td>
</tr>
@endif
