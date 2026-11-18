# Pixelforge Studio

A web app where users generate images through the [Pixelforge](https://pixelforge.promptedit.dev/docs) API (a simulated image provider) and pay the Pixelforge cost **plus 25%**. Take-home assessment for PromptEdit (Senior Product Engineer).

- **Live:** `https://pixelforge.taila7ffb4.ts.net` (UbServer + Tailscale Funnel, see [docs/deploy.md](docs/deploy.md))
- **Decisions** (the interview script): [docs/decisions.md](docs/decisions.md)
- **Manual QA against the real API:** [docs/qa.md](docs/qa.md)
- **Design** (tokens measured on promptedit.com): [docs/design.md](docs/design.md)

What a user can do: see a live price range while writing a prompt, generate, watch the status change live, see the final price or a plain explanation of why it failed (always free), retry, and buy test credits. Admins get a daily revenue / cost / profit report checked against Pixelforge's own usage numbers.

## Stack

Laravel 13 · Inertia 3 · Vue 3 + TypeScript (strict) · Tailwind 4 · Vite+ · Pest 4 · Vitest. SQLite in development and tests, MySQL 8.4 in production. Queue, cache and sessions on the `database` driver. Built from the official Laravel Vue starter kit.

## Run it locally

Requirements: PHP 8.3+ (with `pdo_sqlite`; `bcmath`/`intl` recommended), Composer, Node 22.

```bash
cp .env.example .env
composer install && npm install
php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
composer dev   # server, queue worker, logs and Vite
```

In another terminal, run the scheduler (it polls generations whose webhook never arrived):

```bash
php artisan schedule:work
```

Set `ADMIN_PASSWORD` / `DEMO_PASSWORD` in `.env` before `migrate --seed` to get the admin and demo accounts (each starts with a $1 welcome credit), or register a new account.

### Webhooks locally

Pixelforge refuses private addresses, so webhooks need a public tunnel to your local server, for example:

```bash
ngrok http 8000            # or: cloudflared tunnel --url http://localhost:8000
```

In the Pixelforge dashboard, set your **local** key's webhook URL to `https://<tunnel>/webhooks/pixelforge` and copy its `whsec_...` secret into `PIXELFORGE_WEBHOOK_SECRET`. Without a tunnel the app still works: the scheduled reconciliation picks up results every minute (from 2 minutes after creation).

### Environment variables

| Variable                        | Purpose                                                                     |
| ------------------------------- | --------------------------------------------------------------------------- |
| `PIXELFORGE_BASE_URL`           | `https://pixelforge.promptedit.dev/v1`                                      |
| `PIXELFORGE_API_KEY`            | One key per environment (local and production); `/v1/usage` reports per key |
| `PIXELFORGE_WEBHOOK_SECRET`     | The `whsec_...` signing secret of that key's webhook                        |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Seeded admin (can open `/admin/report`); skipped if the password is empty   |
| `DEMO_EMAIL`, `DEMO_PASSWORD`   | Seeded demo user; skipped if the password is empty                          |

Production variables are documented in [`.env.sandbox.example`](.env.sandbox.example).

## Commands

| Command                                                | What it does                                                                                                                              |
| ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `composer ci`                                          | Everything CI runs: Pint, Larastan, Pest with coverage (≥ 80%), `vp check`, `vue-tsc`, Vitest, build. Pest coverage needs PCOV or Xdebug. |
| `composer test` / `php artisan test`                   | Pest suite (no coverage)                                                                                                                  |
| `npm run test`                                         | Vitest (frontend money and estimate)                                                                                                      |
| `php artisan pixelforge:reconcile-usage --from= --to=` | Compares our provider cost per UTC day with Pixelforge `GET /v1/usage`; exits non-zero on any difference                                  |

CI (GitHub Actions) runs the backend suite on SQLite with coverage, the same suite on MySQL 8.4, and the frontend checks.

## How money works

- **Every amount is an integer number of nanodollars** (1 USD = 1,000,000,000) in `bigInteger` columns, wrapped in the immutable `App\Money\Nanodollars`. That is exactly the 9 decimal places of Pixelforge's `cost_usd`, so nothing is rounded and daily sums match `/v1/usage` to the last digit. Arch tests forbid floats in `app/Money` and `app/Actions`.
- **Provider cost** = Σ tokens × rate ÷ 1,000,000, computed with `BigDecimal` from the generation's actual `usage` and the `rates` Pixelforge fixed when it was created (never today's price list). A result finer than a nanodollar throws instead of rounding.
- **User price** = cost × 1.25, rounded half up to the nanodollar (`intdiv(cost × 125 + 50, 100)`). With today's rates it is always exact. Profit is never stored: it is always price − cost.
- **Estimate before submitting**: a min–max range of the user price (text tokens `ceil(chars / 4)` ± 15%, 765 per reference image, output from the table, reasoning min–max for the quality). `EstimateGenerationCost` is the source of truth and is stored with the generation; `resources/js/lib/estimate.ts` mirrors it with `BigInt` for live feedback, and both suites run the same vectors (`tests/Fixtures/estimate-vectors.json`).
- **Charged exactly once, failures are free**: only the move into `succeeded` writes a ledger charge, under a row lock, and `ledger_entries` has a unique `(generation_id, type)` index as a second layer. A failed generation has zero cost and no ledger entry.
- **Balance**: the ledger is the truth (`users.balance` is a cache updated in the same transaction). Submitting needs _available_ balance (balance − the estimated maximum of unfinished generations) ≥ the new generation's maximum, checked under a lock on the user.

## How webhooks are handled

`POST /webhooks/pixelforge`, outside the session/CSRF/auth middleware:

1. **Signature first**: HMAC-SHA256 of `"{t}.{raw body}"` with the webhook secret, compared with `hash_equals`; missing, malformed, wrong, or more than 300 s off → `400`, nothing stored.
2. **Store and apply in one transaction**: the event goes into `webhook_events` (unique `event_id`). A redelivered event inserts nothing and answers `200 duplicate`. If applying fails, the event row rolls back and Pixelforge's retry tries again.
3. **One state machine** (`ApplyGenerationState`, also used by the send job and reconciliation): the generation row is locked and its status only moves forward (queued < processing < succeeded | failed). Late or repeated events are `stale` and change nothing; `succeeded` applies even if `processing` was never seen.
4. **Matching**: by `pixelforge_id`, else by `metadata.generation_uuid` (the webhook can arrive before the POST response). Events for another environment (`metadata.app_env`) or unknown generations are stored and ignored.
5. **Webhooks are not guaranteed**: every minute, generations still pending 2 minutes after creation are fetched with `GET /v1/generations/{id}` (at most 20 per run) and applied the same way.

Sending is idempotent too: the generation row (with its own `Idempotency-Key`) is written before any network call, and a queued job sends it, retrying `overloaded`, rate/concurrency limits, timeouts and connection errors after `Retry-After` plus backoff and jitter, with the same key, for up to 10 minutes. Anything else fails the generation at once, for free, with a plain message.

## What was left out

See the end of [docs/decisions.md](docs/decisions.md#left-out-and-why).
