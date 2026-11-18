# Manual QA

Run after each deploy, logged in as the demo user. Record the date, the result and anything odd. Keywords in the prompt are case-insensitive and count as text tokens.

## Run 1: local, real Pixelforge API (2026-11-18 UTC)

Setup: local app (`php artisan serve`, `queue:work`, `schedule:work`, SQLite) with the **local** Pixelforge key, webhooks delivered through an ngrok tunnel to `/webhooks/pixelforge`. Every generation was submitted through the real UI (Playwright) as the demo user, after buying $25 of test credits.

| #   | Scenario                                                            | Expected                                                         | Result                                                                                                                              |
| --- | ------------------------------------------------------------------- | ---------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| 0   | "Send test event" from the dashboard                                | 200, signature verified                                          | ✓ `ping`, verified, `ignored`                                                                                                       |
| 1   | Normal generations: low (square), medium (square), high (landscape) | queued → processing → succeeded live; charge inside the estimate | ✓ charged $0.018284375, $0.060306250, $0.356343750, each inside its range                                                           |
| 2   | Medium portrait with 2 reference image URLs                         | 1,530 image tokens                                               | ✓ usage `11 / 1530 / 1650 / 919`, charged $0.116053125 (est. $0.1048–$0.1330)                                                       |
| 3   | `[fail]`                                                            | Accepted, then failed, free                                      | ✓ `generation_failed`, cost 0, no ledger entry, plain message shown                                                                 |
| 4   | `[blocked]`                                                         | Fails at once, free, clear message                               | ✓ `content_policy_violation` on the first attempt, cost 0, "Prompt rejected by the provider's content policy…"                      |
| 5   | `[overloaded]`                                                      | Retries, then a clear failure                                    | ✓ 14 attempts over 10 minutes (UI: "The provider is busy. Retrying automatically."), then `retries_exhausted`, free                 |
| 6   | `[duplicate]`                                                       | One charge; second delivery is a no-op                           | ✓ every event arrived twice, second logged `duplicate`; one ledger charge. A random duplicate on `[fail]` was absorbed the same way |
| 7   | `[slow]`                                                            | Shows processing for 60-90 s, then succeeded                     | ✓ `processing` then `succeeded` events, live in History                                                                             |
| 8   | 4 submits in a row (`[slow]`, medium, high, 2 refs)                 | 4th waits for a slot, then completes                             | ✓ 4th showed "Waiting for a free slot at the provider." (`concurrency_limit_exceeded`), sent on attempt 4, succeeded                |
| 9   | Resend a delivered event from the dashboard                         | Nothing changes                                                  | _pending_                                                                                                                           |
| 10  | `php artisan pixelforge:reconcile-usage`                            | Difference 0                                                     | ✓ 2026-11-18: 6 generations, ours `0.468235000` = Pixelforge `0.468235000`, difference `0.000000000`                                |

Money checks: every `charged_amount` is exactly `provider_cost × 1.25`; the demo balance ($1 welcome + $25 − 6 charges = $25.414706250) equals the sum of its ledger entries; failures and rejected submits have zero cost and no ledger entry.

## Run 2: production

_To be filled in after the deploy, with the production key and the Funnel URL. Leave these generations in place (including the failures) for PromptEdit to compare with Pixelforge's records._
