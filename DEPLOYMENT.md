# Deployment checklist (Hostinger)

Steps for shipping a CSS/JS change (or any front-end asset change) to the live Hostinger site.

1. Build assets locally: `npm run build`.
2. Upload the changed files (`public/build/**`, plus any changed PHP/Blade) to Hostinger.
3. **Purge the LiteSpeed cache in hPanel's Cache Manager.** Hostinger runs a server-level LiteSpeed cache in front of the site. It will keep serving the previous page/assets after a deploy — even when the uploaded files are correct — until this is purged. Skipping this step is indistinguishable from the deploy having failed, and has previously cost significant time misdiagnosing a "broken deploy" that was actually just a stale cache.
4. Reload the live site in a private/incognito window (avoids browser-side caching on top of LiteSpeed's) and confirm the change is visible before considering the deploy done.

## Why this exists

During the theming work in commits `2ef30f1`–`bbfce0b`, a deploy was hard to verify because LiteSpeed kept serving stale pages after the purge step was missed, which led to an extended, confusing troubleshooting session. This file exists so that step doesn't get dropped again.
