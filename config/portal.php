<?php

/*
| Sign-in through the TR Staff Portal (OpenID Connect). See docs/portal-sso.md.
| With PORTAL_ENABLED=false Maintenance keeps its own staff login exactly as before. Vendors, tenants and owners never use the portal.
| (Not related to PORTAL_API_TOKEN, which guards the tenant/vendor portals.)
*/

return [

    'enabled' => (bool) env('PORTAL_ENABLED', false),

    'issuer' => rtrim((string) env('PORTAL_ISSUER', ''), '/'),

    'client_id' => env('PORTAL_CLIENT_ID'),

    'client_secret' => env('PORTAL_CLIENT_SECRET'),

    // Must match the app's Short ID in the portal; the portal lists it in the `apps` claim.
    'app_slug' => env('PORTAL_APP_SLUG', 'maintenance'),

    // Only these email domains may be linked to or create accounts here.
    'allowed_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('PORTAL_ALLOWED_DOMAINS', 'texasrenters.com,texra.net'))))),

    // How often a signed-in session re-checks with the portal that access is still allowed.
    'recheck_minutes' => (int) env('PORTAL_RECHECK_MINUTES', 5),

    // Emergency switch: allow the old email/password form while the portal is enabled.
    'allow_password_login' => (bool) env('PORTAL_ALLOW_PASSWORD_LOGIN', false),

];
