# Staff sign-in through the TR Staff Portal

When `PORTAL_ENABLED=true`, Maintenance **staff** (`User::STAFF_ROLES`: admin, woc, accounting) sign in through the TR Staff Portal (OpenID Connect). **Vendors, tenants and owners are not affected:** their password login, magic-link portals (`vendor/…`, `tenant-portal/…`, `owner-portal/…`, `jobber-portal/…`) and registration stay exactly as they are. Not related to `PORTAL_API_TOKEN` (the tenant/vendor portal API).

## Settings (`/opt/tx-maintenance/.env.docker`)

```
PORTAL_ENABLED=true
PORTAL_ISSUER=https://login.texasrenters.com
PORTAL_CLIENT_ID=<from the portal: Apps → Maintenance>
PORTAL_CLIENT_SECRET=<shown once when the app was registered>
PORTAL_APP_SLUG=maintenance
PORTAL_RECHECK_MINUTES=5
PORTAL_ALLOW_PASSWORD_LOGIN=true              # false = staff may only use the portal
```

Register Maintenance in the portal with:

- **Tile address:** `https://maintenance.texasrenters.com/auth/portal/redirect`
- **Callback:** `https://maintenance.texasrenters.com/auth/portal/callback`
- **Logout address:** `https://maintenance.texasrenters.com/auth/portal/backchannel-logout`

## What happens

- **Linking:** the first portal sign-in links to the existing **staff** account with the same verified @texasrenters.com / @texra.net email. Roles and data stay as they were.
- **No automatic accounts (owner decision):** someone without a Maintenance staff account is refused with "Ask an admin to add you". An admin creates the account in Maintenance first; the next portal sign-in links it.
- A vendor/tenant/owner account with a company email is never linked.
- **Staff password login** is refused while the portal is on (unless `PORTAL_ALLOW_PASSWORD_LOGIN=true`); every failure shows Fortify's usual message, so it never reveals who is staff. A staff session that did not come through the portal is ended on its next request.
- **Every 5 minutes** staff sessions re-check with the portal (one refresh per session even when requests overlap); a portal outage does not sign anyone out.
- **Back-channel logout** (deactivation, "sign out everywhere") ends the person's web sessions. **The desktop app is not part of portal sign-in** (owner decision).
- **Signing out:** staff who came through the portal go back to its app list; everyone else as before. Accounting lands on Waiting on payment, as with the password login.
- `/login` keeps the password form for vendors, tenants and owners, with a **Staff: sign in with TR Staff Portal** button above it.
