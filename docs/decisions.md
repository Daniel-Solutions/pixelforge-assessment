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
