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
| 9   | Resend a delivered event from the dashboard                         | Nothing changes                                                  | ✓ `evt_01M7P2EC1K99XNR3YMWB0J26CR` resent: 200 `duplicate`; events, ledger, balance and status unchanged                            |
| 10  | `php artisan pixelforge:reconcile-usage`                            | Difference 0                                                     | ✓ 2026-11-18: 6 generations, ours `0.468235000` = Pixelforge `0.468235000`, difference `0.000000000`                                |

Money checks: every `charged_amount` is exactly `provider_cost × 1.25`; the demo balance ($1 welcome + $25 − 6 charges = $25.414706250) equals the sum of its ledger entries; failures and rejected submits have zero cost and no ledger entry.

## Run 2: production (2026-11-18 UTC)

Setup: `https://pixelforge.taila7ffb4.ts.net` (UbServer, Tailscale Funnel, MySQL 8.4) with the **production** Pixelforge key and its webhook URL. Same scenarios as run 1, submitted through the public UI (Playwright) as the demo user after buying $25 of test credits. These generations are left in place, including the failures, for comparison with Pixelforge's records.

| #   | Scenario                                    | Result                                                                                                        |
| --- | ------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| 0   | "Send test event"                           | ✓ `ping`, signature verified, `ignored`; an unsigned `POST` gets 400                                          |
| 1   | Normal: low, medium, high (landscape)       | ✓ charged $0.020350000, $0.082703125, $0.396806250 (= cost × 1.25, each inside its estimate)                  |
| 2   | Medium portrait, 2 reference image URLs     | ✓ usage `11 / 1530 / 1650 / 300`, charged $0.106381250 (est. $0.1048–$0.1330)                                 |
| 3   | `[fail]`                                    | ✓ `generation_failed`, cost 0, no ledger entry                                                                |
| 4   | `[blocked]`                                 | ✓ `content_policy_violation` on the first attempt, cost 0                                                     |
| 5   | `[overloaded]`                              | ✓ 14 attempts over 10 minutes, then `retries_exhausted`, cost 0                                               |
| 6   | `[duplicate]`                               | ✓ redeliveries logged `duplicate` (3 in this run), one charge per generation                                  |
| 7   | `[slow]`                                    | ✓ `processing` then `succeeded`, charged $0.019115625                                                         |
| 8   | 4 submits in a row                          | ✓ the 4th waited with `concurrency_limit_exceeded` ("Waiting for a free slot…"), sent on attempt 4, succeeded |
| 9   | Resend a delivered event from the dashboard | _not repeated in production (verified in run 1)_                                                              |
| 10  | `php artisan pixelforge:reconcile-usage`    | ✓ 2026-11-18: 6 generations, ours `0.513705000` = Pixelforge `0.513705000`, difference `0.000000000`          |

Money checks: the demo balance ($1 welcome + $25 − 6 charges) is $25.357868750, equal to the sum of its ledger entries.
