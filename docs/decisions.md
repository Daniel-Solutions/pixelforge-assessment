# Decisions

Each entry: the problem, the choice, and the alternative we rejected. This file is the script for the technical interview.

## Stack and tooling

**Starter kit from `dev-main`.** The tagged `laravel/vue-starter-kit` releases (v1.0.x) still ship Laravel 12 and Inertia 2. The official installer creates the kit from `dev-main`, which gives Laravel 13, Inertia 3 and Tailwind 4, so we did the same. Two-factor auth, passkeys and email verification were removed with the kit's own `chisel` script: none of them is needed for the assessment.

**Vite+ instead of ESLint and Prettier.** The current starter kit replaced ESLint and Prettier with Vite+ (`vp check`: Oxlint, Oxfmt and type-aware lint) and runs Vitest as `vp test`. The prompt asked for "the starter kit's ESLint + Prettier"; we kept what the kit ships instead of adding a second toolchain.

**Pest instead of PHPUnit.** The kit ships PHPUnit tests. Pest 4 runs them unchanged; new tests are written in Pest, and arch tests (`tests/Unit/ArchTest.php`) enforce the code rules.

**Design tokens from the studio page, not the landing page.** promptedit.com's global theme variables are shadcn defaults, while its image studio (`/ai/image`) uses its own surfaces (`#fbfaf9` page, `#262626` text, lime `#9ae600` CTA). Our Generate and History screens are a studio, so the tokens come from there. Details in `docs/design.md`.

## Pixelforge docs vs. the build prompt

The docs at https://pixelforge.promptedit.dev/docs were read on 2026-11-17. They match the prompt, with these additions:

- A failed generation carries `error.code` `generation_failed` or `internal_error`; neither is billed. We store the code and show a plain message.
- `daily_limit_exceeded` documents no `Retry-After`. We never retry it.
- `GET /v1/usage` returns `422 date_range_too_long` past 92 days; the reconcile command splits nothing and just reports the error.
- Every authenticated response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`; we log them, but do not throttle on them.

## Money

**Integer nanodollars everywhere.** Every amount is an `int` of nanodollars (1 USD = 10⁹) in `bigInteger` columns, wrapped in `App\Money\Nanodollars`. That is exactly the 9 decimal places of Pixelforge's `cost_usd`, so provider costs are stored without rounding and our daily sums can match `/v1/usage` to the last digit. Rejected: `decimal(18,9)` columns (exact, but PHP reads them as strings and every sum needs care) and floats (wrong by design).

**Provider cost is computed with `BigDecimal` and must be exact.** Cost = Σ tokens × rate ÷ 1,000,000 using the `rates` stored on the generation, never the current price list. If the result has more than 9 decimals (a future rate with many decimals), `InexactAmount` is thrown instead of rounding silently. With today's rates every cost is a multiple of 500 nanodollars.

**User price = cost × 1.25, rounded half up to the nanodollar.** `intdiv(cost × (100 + markup) + 50, 100)`, which for 25% equals `intdiv(cost × 5 + 2, 4)`. The error is at most half a nanodollar per generation, always in a documented direction. With today's rates the result is always exact (500 × 5 / 4 is an integer); the rounding rule exists for future rates and is unit-tested with synthetic amounts. The markup lives in `config('pixelforge.markup_percent')`.

**Profit is never stored.** It is always `charged_amount − provider_cost`, from the two stored values.

**Formatting only at the edge.** `Nanodollars::format()` (4 places for users, 9 for the admin report) is used by Resources; formatted strings never go back into math. Arch tests forbid `float`, `round`, `floor`, `ceil` and `number_format` in `app/Money` and `app/Actions`.

**The text-token variance arrives as a JSON float.** `/v1/pricing` sends `text_token_variance: 0.15` as a number. `Pricing::fromArray` converts it once to the string `"0.15"` and all math after that is `BigDecimal` (backend) or `BigInt` fractions (frontend).

## Estimate

**A range, computed in two places from one set of vectors.** Text: `e = ceil(chars / 4)` with `mb_strlen` (code points), range `floor(e × 0.85)` to `ceil(e × 1.15)`; images `765 × n`; output from the table; reasoning min to max for the quality. The shown range is the user price (with markup). `App\Actions\EstimateGenerationCost` is the source of truth and its result is stored on the generation; `resources/js/lib/estimate.ts` mirrors it for live feedback with `BigInt`, and both test suites run the same `tests/Fixtures/estimate-vectors.json`. Rejected: a debounced estimate endpoint (a round trip per keystroke, and the UI flickers).

## Sending generations

**The local row comes first, the network call second.** `SubmitGeneration` stores the generation (status `queued`, a fresh `uuid` that is also the `Idempotency-Key`, and the estimate) in a transaction, then dispatches `SendGenerationToPixelforge` with `afterCommit()`. The request body is rebuilt from the row on every attempt, so a retry is byte-for-byte the same request and Pixelforge replays it instead of creating a second generation. Rejected: calling Pixelforge in the HTTP request (slow page, and a timeout would leave us not knowing whether it was created).

**Retries: Retry-After, growing backoff, jitter, a 10-minute deadline.** Retryable: `overloaded`, `rate_limit_exceeded`, `concurrency_limit_exceeded`, timeouts and connection errors, and any undocumented 5xx. Delay = `max(Retry-After, 2^attempt capped at 60 s) + 0-3 s jitter`. The job retries until 10 minutes after the user pressed Generate (`retryUntil`) rather than a fixed count, because a concurrency wait is normal under load (only 3 generations run at the provider at once) and a count of ~6 would fail the fourth of a burst. Everything else (`content_policy_violation`, `daily_limit_exceeded`, `invalid_api_key`, `idempotency_key_reused`, `validation_failed`) fails at once, for free. When the deadline passes, `failed()` marks the generation `retries_exhausted`, free.

**"Waiting for a free slot".** While a retryable error keeps the generation local, its last error code is kept in `last_submit_error`, so the UI can say "Waiting for a free slot at the provider" (concurrency) or "Provider busy, retrying" instead of a bare "queued".

**An idempotent replay is a success.** `Idempotent-Replayed: true` returns the generation as it is now, possibly already finished; its state goes through `ApplyGenerationState` like any webhook.

**A generation we gave up on, but Pixelforge finished.** If every attempt timed out, we fail it locally. If one attempt had actually reached Pixelforge, its webhook (found by `metadata.generation_uuid`) later says `succeeded` and Pixelforge bills it. We then store the provider cost and tokens on the failed row (so `/v1/usage` still reconciles) but never charge the user and never change the status: `StateChange::BilledAfterLocalFailure`, logged as a warning. Rejected: flipping it to succeeded (a final status would move) or ignoring it (our provider cost would silently drift from Pixelforge's).

## Webhooks

**Signature first, on the raw bytes.** `VerifyPixelforgeSignature` parses `t=<unix>,v1=<hex>` (several `v1` values are accepted, for secret rotation), recomputes HMAC-SHA256 of `"{t}.{raw body}"` with `hash_equals`, and rejects with 400 if the header is missing, malformed, wrong, or `|now − t| > 300 s`. It uses `$request->getContent()`, and the controller parses those same bytes. The docs give 300 s only in their example code; we use it. With no secret configured, every webhook is rejected and a critical log is written.

**Route outside the web group.** `routes/webhooks.php` is loaded through `withRouting(then:)` with no middleware group: no session, no CSRF, no auth. The signature is the authentication.

**Synchronous processing, one transaction.** The event row (`insertOrIgnore` on a unique `event_id`) and its effect commit together. A redelivery inserts nothing and returns 200 `duplicate`. If applying throws, the event row rolls back too, we answer 500, and Pixelforge's own retry tries again. The work is a few indexed queries, far below the 10 s limit, so a queued job would only add a second place where things can fail. Rejected: acknowledging first and processing in a job (needs its own dedupe and retry story).

**One state machine for webhooks, the send job and reconciliation.** `ApplyGenerationState` locks the row (`lockForUpdate`) and only moves the status forward (queued 0 < processing 1 < succeeded/failed 2); same or lower rank is `stale` and changes nothing. The payload is the full generation object, so `succeeded` applies even if `processing` was never seen. Results are logged and stored on the event: `applied`, `stale`, `duplicate`, `unknown`, `ignored`, `billed_after_local_failure`.

**Charge exactly once, two layers.** (1) Only the transition from a non-final status into `succeeded` writes a `generation_charge` ledger entry, under the row lock. (2) `ledger_entries` has a unique `(generation_id, type)` index, so a second charge cannot be stored even if (1) had a bug. Cost is computed from the payload's `usage` and `rates`. A failure stores zero cost and no ledger entry.

**Matching.** By `pixelforge_id`, else by `metadata.generation_uuid` (the webhook can beat the POST response; we then store the `pixelforge_id` right there). An event whose `metadata.app_env` is not ours, or that matches no row, is stored and answered 200 with no effect: each environment has its own key and URL, but a misconfigured URL must not touch another environment's data.

## Reconciliation

**Poll what webhooks missed, every minute.** `ReconcilePendingGenerations` (scheduled `everyMinute`, `ShouldBeUnique`) picks generations Pixelforge accepted that are still `queued`/`processing` more than 2 minutes after creation (normal runs take 5-30 s, `[slow]` up to 90 s), oldest first, at most 20 per run, and passes `GET /v1/generations/{id}` to `ApplyGenerationState`. A rate limit or a bad key stops the run; other errors skip that generation. Generations without a `pixelforge_id` are not polled: the send job owns them until it succeeds or fails them.

**The billing day is the UTC day of `completed_at`.** That is how `/v1/usage` buckets cost. `pixelforge:reconcile-usage --from= --to=` sums `provider_cost`, count and tokens with `SUM` over bigints grouped by `DATE(completed_at)` for every generation with a provider cost (succeeded ones, plus the rare billed-after-local-failure), and compares them with `/v1/usage` day by day. It exits non-zero on any difference. The MySQL connection uses `timezone +00:00` so `DATE()` is a UTC day.

## Product and UI

**Studio layout copied from promptedit.com/ai/image.** Generate is a composer column (prompt, reference image URLs, size and quality as segmented controls) with a sticky footer holding the live price range and the lime GENERATE button; the right side shows the estimate breakdown by token type (collapsible). History lists generations as cards (stacked on mobile) with status badge, estimate range, final price or "Not charged", and the plain-language status line. A detail page (`/generations/{uuid}`) shows estimated vs. actual tokens. Prototype: `docs/design/prototype.html`.

**Users see their price, never our provider cost.** `GenerationResource` exposes `estimate` and `charged` (each as a 4-place display string plus the exact 9-place value for a tooltip) and no provider cost or profit.

**Live status by Inertia polling, only while something is pending.** `usePendingPoll` reloads only the `generations` (or `generation`) prop and `hasPending` every 3 s and stops when every generation on the page is final. Rejected: websockets (Reverb is extra infrastructure for a 3-second refresh).

**UUIDs in URLs.** Generations are routed by `uuid`, so ids are not guessable; the policy still checks ownership (tested by requesting another user's uuid).

**Retry creates a new generation.** A failed generation stays failed; "Retry" submits the same input as a new row with a new `Idempotency-Key` (reusing the old key would replay the old failure).

**Estimated tokens are stored.** `estimated_tokens` keeps the min/max token counts used for the stored estimate, so the detail page compares estimate and actual even if Pixelforge's tables change later.

## Deploy

**UbServer + Tailscale Funnel, same pattern as 247squad.** Compose services `app`, `web`, `queue`, `scheduler`, `mysql` and a `tailscale` sidecar; no published ports. The difference from 247squad: the app must be public (PromptEdit opens it, Pixelforge posts webhooks and refuses private addresses), so `serve.json` turns on Funnel for 443 only, proxying only to `web:80`. Rejected: ngrok/cloudflared (another moving part and account) and opening router ports.

**The assets stage has PHP.** Wayfinder's Vite plugin runs `php artisan wayfinder:generate` during the build, so the `assets` stage is `php:8.4-cli-alpine` plus Node, instead of a Node image. Production still has no Node.

**Larger FastCGI buffers.** Laravel's `Link` preload header lists every Vite chunk; nginx's 4k default buffer returned 502 on Inertia pages. Found by running the production stack locally before deploying.

**Database driver for queue, cache and sessions.** One less service than Redis, and the load is a handful of jobs per minute.

**Auth routes throttled.** The app is public: Fortify routes are limited to 30 requests per minute per IP (plus Fortify's own login limiter), generation submits to 10 per minute per user.

## Stretch: admin report

**Same day rule as Pixelforge.** `/admin/report` (gate `view-admin-report`, `is_admin` only, set by seeder) groups by `DATE(completed_at)` in UTC and by user: succeeded and failed counts, credit spent (`SUM(charged_amount)`), provider cost (`SUM(provider_cost)`) and profit (always charged − cost from the two stored sums). Totals per day, per user and overall; default range is the last 30 days. Each day shows "Pixelforge reported" from `/v1/usage` (cached 60 s) with ✓ or the exact difference; if Pixelforge cannot answer (error, or more than 92 days), our numbers still show and the column is empty. The aggregate query runs through `toBase()` so the aliases are not cast to `Nanodollars` by the model.

## Stretch: balance

**The ledger is the truth, `users.balance` is a cache.** Every purchase (positive) and charge (negative) is a `ledger_entries` row; `RecordLedgerEntry` updates `users.balance` in the same transaction with the user row locked. A test checks `SUM(amount) = balance`.

**Available balance reserves the maximum.** Available = balance − Σ `estimated_max` of the user's unfinished generations. `SubmitGeneration` locks the user row and refuses (`InsufficientCredits`) when available < the new generation's `estimated_max`, so two simultaneous submits are serialized and cannot both spend the same credit (the lock is real on MySQL; SQLite serializes writes). On success the real `charged_amount` (always ≤ the maximum) is charged; on failure nothing is charged and the reservation disappears because the generation is final. The Generate button is disabled with "Not enough credits: this generation can cost up to $X. Buy credits", and the server checks again. Rejected: a separate "hold" ledger entry (two more writes per generation and a release step that can be forgotten).

**Fake purchases, idempotent.** Fixed packages ($5, $10, $25). The page sends a one-time `purchase_token` (a UUID made in the browser); the action checks it and `purchase_token` is unique in the database, so a double click buys once. New users (registration and seeded accounts) get a $1 welcome credit as a `purchase` entry with token `welcome-{id}`.

**A missing API key is a typed error.** Found while testing the report locally: with `PIXELFORGE_API_KEY` unset, the client now throws `PixelforgeException(invalid_api_key)` (logged as critical) instead of a config exception, so every caller's error handling applies.

## Code review fixes

A review of the whole diff found ten issues; all were fixed with tests:

- **Billed after local failure, any event order.** The rule now keys on "failed locally (not with Pixelforge's own `generation_failed`/`internal_error`), no provider cost yet", so a `processing` event that links the row first no longer hides the later `succeeded`.
- **Stored rates win.** Cost uses the rates saved at creation; the payload's rates are used only if none were stored.
- **Usage above the estimate.** Pixelforge's documented ranges keep usage inside the estimate. If it ever reports more, we still charge the real price (cost + 25%) but log `generation.charged_above_estimate`; capping would break "price = cost + 25%". The Credits page no longer promises "never more than the maximum".
- **Admin report cache.** The cache store does not unserialize objects (`serializable_classes => false`), so the report caches plain integers per day; a second load used to 500.
- **Purchase token from the server.** `crypto.randomUUID()` does not exist on plain-HTTP origins (`http://pixelforge.test`); the server now issues the token per page view.
- **Backoff for unexpected errors** in the send job (`[5, 15, 30, 60]` s), so a bug cannot burn the shared 60 requests/minute.
- **Reconciliation rotates** (least recently checked first, each poll touches the row) and survives any exception per row, so stuck rows cannot starve newer ones.
- **Missing webhook secret** returns the documented 400 instead of a 500.
- **Usage comparison covers both sides' days**, so a cost we recorded on a day Pixelforge did not report is flagged.
- **Accounts with generations cannot be deleted**: the cascade would erase billing records that Pixelforge still counts.

## Verified against the real API

The full QA script ran locally against Pixelforge with a local key and webhooks through an ngrok tunnel (`docs/qa.md`, run 1): every keyword scenario behaved as designed, `[duplicate]` deliveries were absorbed, the concurrency wait showed and resolved, a dashboard resend was a no-op, and `pixelforge:reconcile-usage` matched `/v1/usage` to `0.000000000`.

## Left out and why

- **Storing images.** Pixelforge keeps images for 24 hours; after `image_url_expires_at` the UI shows "Image expired" instead of a broken image. Storing them (S3 or disk) was not asked and adds storage, cleanup and cost.
- **Real payments.** "Buy credits" is a test purchase that writes a ledger entry. A payment provider (Stripe Checkout + webhook) would reuse the same idempotent `BuyCredits` path.
- **Websockets for live status.** 3-second Inertia polling, only while something is pending, is enough here and needs no extra service.
- **Two-factor auth, passkeys, email verification.** Removed from the starter kit: not part of the assessment, and fewer moving parts on a public URL.
- **Refunds and admin balance adjustments.** The ledger supports new entry types, but no screen creates them.
- **Per-user rate limiting beyond 10 submits/minute and the credit check.** Pixelforge's limits are per account, shared by all users; a fair-share queue would matter with many users, not for this evaluation.
- **Browser tests.** By request; the proof is Pest (244 tests, MySQL and SQLite), Vitest, and the manual QA run in `docs/qa.md`.
