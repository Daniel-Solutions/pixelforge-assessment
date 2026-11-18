# Deploy

Pixelforge Studio runs on the UbServer (home server, repo `Daniel-Solutions/ubserver`) and is published by the server's `deploy` command, the same way as `247squad`. Unlike `247squad`, it must be **public on the internet**: PromptEdit opens the URL to evaluate it, and Pixelforge delivers webhooks to it (Pixelforge refuses private addresses). The `tailscale` sidecar therefore uses **Funnel**: the public URL is `https://pixelforge.<tailnet>.ts.net` (today `https://pixelforge.taila7ffb4.ts.net`), with a certificate issued by Tailscale. No ngrok, no own domain, no port published on the host or the router.

## What runs

`docker-compose.prod.yml`, project name set by `deploy`:

| Service     | What it does                                                                                            |
| ----------- | ------------------------------------------------------------------------------------------------------- |
| `app`       | PHP-FPM 8.4. Caches config, routes and views on start. Healthcheck on port 9000.                        |
| `web`       | nginx with `public/`. The only service on the Traefik network (`http://pixelforge.test` on the LAN).    |
| `tailscale` | Its own machine `pixelforge` on the tailnet. Funnel on 443 proxies to `http://web:80` and nothing else. |
| `queue`     | `queue:work`: sends generations to Pixelforge and retries them.                                         |
| `scheduler` | `schedule:work`: polls overdue generations every minute (webhooks are not guaranteed).                  |
| `mysql`     | MySQL 8.4, `TZ=UTC`, data in the `mysql-data` volume. No published port.                                |

The image (`docker/php/Dockerfile.prod`) has three targets: `assets` (PHP + Node, because Wayfinder generates the TypeScript routes with `artisan` during the Vite build), `app` and `web`. Node never runs in production.

On every deploy, `.deploy/before-up` runs `php artisan migrate --force` and `php artisan db:seed --force` (idempotent: it creates the admin and demo accounts only if their passwords are set), and `.deploy/after-up` runs `php artisan queue:restart`.

In this page, `dc` means `docker compose -p pixelforge-assessment --project-directory /opt/projects/pixelforge-assessment/current -f /opt/projects/pixelforge-assessment/current/docker-compose.prod.yml`.

## Steps, in this order

Everything here runs on the server or in admin consoles, by Daniel.

1. **Tailscale admin** (login.tailscale.com):
    - DNS: **MagicDNS** and **HTTPS Certificates** on (already on for `247squad`).
    - Access controls: allow Funnel for the node. Add to the policy file:
        ```json
        "nodeAttrs": [{ "target": ["autogroup:member"], "attr": ["funnel"] }]
        ```
        (or a narrower target). Without it, Funnel does not come up.
2. **Auth key** (Settings > Keys > Generate auth key): **Reusable off**, **Ephemeral off**, **Pre-approved on**, no tags, description `pixelforge`. Put it in `/opt/projects/pixelforge-assessment/shared/.env` as `TS_AUTHKEY=tskey-auth-...` (the file exists after the first deploy; on the very first run, create it from `.env.sandbox.example` or run step 4 once and then edit it). Never in git.
3. **Pixelforge production key** (separate from the local one), in the same `shared/.env`:
    - `PIXELFORGE_API_KEY=` the production key;
    - in the Pixelforge dashboard, that key's webhook URL: `https://pixelforge.<tailnet>.ts.net/webhooks/pixelforge`;
    - `PIXELFORGE_WEBHOOK_SECRET=` that key's `whsec_...` secret.
4. **Deploy**: `deploy pixelforge-assessment <branch>` (`main`). The first run creates `shared/.env`, replaces every `troque-...` value with a random one (database passwords, `ADMIN_PASSWORD`, `DEMO_PASSWORD`) and generates `APP_KEY`. Read the admin and demo passwords in `shared/.env`. If you edited `shared/.env` after the first run, deploy again.
5. **Disable key expiry**: in the Tailscale admin, Machines > `pixelforge` > **Disable key expiry**. Otherwise the URL dies in 180 days, possibly in the middle of the evaluation.
6. **Check**:
    - `dc exec tailscale tailscale funnel status` shows `https://pixelforge.<tailnet>.ts.net (Funnel on)` with `/ proxy http://web:80`.
    - From an outside network (phone on 4G, Tailscale off), the URL opens the login page with a padlock.
    - In the Pixelforge dashboard, **Send test event** gets a 200 (`dc logs web` shows `POST /webhooks/pixelforge 200`).
    - `curl -s -o /dev/null -w '%{http_code}\n' -X POST https://pixelforge.<tailnet>.ts.net/webhooks/pixelforge` prints **400** (unsigned).
    - `dc exec app php artisan schedule:list` lists `ReconcilePendingGenerations` every minute.
7. **Uptime Kuma**: add an HTTP monitor for `https://pixelforge.<tailnet>.ts.net/up`.
8. Run the manual QA script in `docs/qa.md` and record the results there.

Update later: `deploy pixelforge-assessment main`. Logs: `deploy logs pixelforge-assessment queue` (or `app`, `web`, `scheduler`). Usage check: `dc exec app php artisan pixelforge:reconcile-usage`.

## Going back

- **Bad release**: `deploy rollback pixelforge-assessment` puts the previous release back. Migrations are not undone; every migration so far only creates tables.
- **Close the public URL** (keep the app on the LAN): `dc stop tailscale`. To remove it for good, also delete the machine `pixelforge` in the Tailscale admin and remove the `funnel` `nodeAttrs` entry if nothing else uses it.
- **Stop everything but keep the data**: `deploy stop pixelforge-assessment`; `deploy start pixelforge-assessment` brings it back.
- **Lost Tailscale state**: if the `tailscale-state` volume is deleted, the node loses its identity and the single-use auth key is spent. Generate a new key, update `TS_AUTHKEY`, deploy. Until then the `tailscale` container restarts in a loop; the app on the LAN is not affected.

## Points of attention

- `deploy` only clones repositories from the `Daniel-Solutions` organization (`ORG` in `scripts/deploy` of `ubserver`), which is why the repository is `Daniel-Solutions/pixelforge-assessment`.
- Funnel exposes **only this app**: `docker/tailscale/serve.json` proxies `/` to `web:80`, with `AllowFunnel` on 443 only. Traefik, Portainer and the other apps stay closed. Never add `AllowFunnel` to another port and never point the proxy at Traefik.
- The home server must stay on until PromptEdit finishes the evaluation.
- Because the app is on the internet: `APP_DEBUG=false`; every Fortify route (login, register, password reset) is throttled to 30 requests per minute per IP, on top of Fortify's 5 login attempts per minute per email and IP; generation submits are limited to 10 per minute per user; the webhook only accepts signed requests (HMAC, 300 s tolerance).
- nginx treats a `Host` ending in `.ts.net` as HTTPS and passes `X-Forwarded-Proto: https` to PHP; `bootstrap/app.php` trusts the proxies, so URLs and cookies are `https` (`SESSION_SECURE_COOKIE=true`). With that setting, logging in over `http://pixelforge.test` does not work (the browser drops the Secure cookie): use the `.ts.net` URL.
- nginx raises `fastcgi_buffer_size` to 32k: Laravel's `Link` preload header lists every Vite chunk and overflows the 4k default (502 on every Inertia page).
- **Leave some generations in place** after QA (including a failure) so PromptEdit can compare them with Pixelforge's records.

## Verified locally before the first deploy

- `docker compose -f docker-compose.prod.yml config` is valid and `app`/`web` build.
- The stack (without `tailscale`) started from the built images: `/up` 200, `/login` with `Host: pixelforge.taila7ffb4.ts.net` renders `https://` asset URLs, an unsigned `POST /webhooks/pixelforge` gets 400, and `schedule:list` shows the reconciliation job.
- The whole Pest suite passed against MySQL 8.4 (`mysql:8.4` container) as well as SQLite; CI runs both.
