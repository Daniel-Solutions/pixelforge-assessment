# Pixelforge Studio

Users generate images through the Pixelforge API (fictional provider) and pay the provider cost plus 25%. Take-home for PromptEdit. Everything (code, UI, docs, commits) in English. Database in UTC.

## Stack

Laravel 13, Inertia 3, Vue 3 (`<script setup>`) + TypeScript strict, Vite+, Tailwind 4, Pest 4, Vitest (via `vp test`). SQLite in dev/tests, MySQL 8.4 in production. Queue and cache on the `database` driver.

## Commands

- `composer dev`: server, queue, logs and Vite. `composer ci`: everything CI runs (Pint, Larastan, Pest with coverage, `vp check`, `vue-tsc`, Vitest, build).
- `php artisan test --filter=...`, `vendor/bin/pint`, `vendor/bin/phpstan analyse`.
- `php artisan pixelforge:reconcile-usage --from= --to=`: compare our provider cost per UTC day with Pixelforge `/v1/usage`.

## Code rules

- `declare(strict_types=1)`, parameter and return types everywhere, array shapes in PHPDoc.
- Thin controllers: Form Request validates, Policy authorizes, Action (`app/Actions`, one `handle` method) does the work.
- No `$fillable`/`$guarded`: `Model::unguard()` is global. Save only `validated()`. `user_id`, `is_admin`, status, costs and balance never come from the request.
- Enums in `app/Enums`. No `dd`, `dump` or `env()` outside `config/`. Arch tests in `tests/Unit/ArchTest.php` enforce this.
- Only `App\Pixelforge\PixelforgeClient` builds Pixelforge URLs or reads its raw JSON.

## Money rules

- Every amount is an `int` of nanodollars (1 USD = 1,000,000,000) in `bigInteger` columns, wrapped in `App\Money\Nanodollars`. Never `float`.
- Provider cost = sum of `tokens × rate ÷ 1M` using the `rates` stored on the generation (fixed at creation), computed with `BigDecimal`; a non-exact result throws.
- User price = cost × 1.25, rounded half-up to the nanodollar. Markup lives in `config('pixelforge.markup_percent')`.
- Format only at the edge (Resources/frontend). Formatted strings never go back into math.

## Webhook rules

- Verify `Pixelforge-Signature` (HMAC-SHA256 of `"{t}.{raw body}"`, 300 s tolerance) before anything else; 400 on failure.
- Store the event in `webhook_events` (unique `event_id`) and apply its effect in the same transaction; a duplicate does nothing and returns 200.
- `ApplyGenerationState` is the only place that changes a generation's provider state (webhook and reconciliation both use it). Lock the row; statuses never move backwards.
- Charge once: only the non-final to `succeeded` transition charges, and `ledger_entries` has a unique `(generation_id, type)` index.

## Agent notes

- No browser MCP. Proof is Pest tests plus the manual QA script in `docs/qa.md`.
- Record every non-obvious decision in `docs/decisions.md` (2 to 4 lines: problem, choice, rejected alternative).
