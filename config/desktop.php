<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Desktop Client Identity
    |--------------------------------------------------------------------------
    |
    | Shown by the TexasRenters Desktop client as the name and icon of this
    | connection. Both fall back to the application's own name and logo.
    |
    */

    'name' => env('DESKTOP_APP_NAME'),

    'icon_url' => env('DESKTOP_ICON_URL', '/logo.png'),

    /*
    |--------------------------------------------------------------------------
    | Setup Token Lifetime
    |--------------------------------------------------------------------------
    |
    | Minutes a "desktop:setup" connection code stays valid. The handshake
    | immediately swaps it for a long-lived device token, so a leaked code is
    | only briefly useful.
    |
    */

    'setup_token_ttl' => (int) env('DESKTOP_SETUP_TOKEN_TTL', 15),

    /*
    |--------------------------------------------------------------------------
    | WebSocket Details Handed To The Client
    |--------------------------------------------------------------------------
    |
    | IMPORTANT: "host" is resolved on the user's laptop, not on the web
    | server, so it must be publicly reachable over TLS.
    |
    | Unset, it falls back to the host in APP_URL — deliberately NOT to
    | REVERB_HOST, which is the address this server uses to publish events to
    | Reverb and is frequently internal. That mirrors resources/js/echo.js,
    | which points the browser at window.location.hostname on 443. Set
    | DESKTOP_REVERB_HOST only when Reverb lives somewhere other than the
    | application's own domain.
    |
    */

    'websocket' => [
        'key' => env('DESKTOP_REVERB_APP_KEY') ?: env('REVERB_APP_KEY') ?: null,
        'host' => env('DESKTOP_REVERB_HOST') ?: parse_url((string) env('APP_URL'), PHP_URL_HOST) ?: null,
        'port' => (int) (env('DESKTOP_REVERB_PORT') ?: 443),
        'scheme' => env('DESKTOP_REVERB_SCHEME') ?: 'https',
    ],

];
