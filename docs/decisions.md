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
