# Manual QA (production)

Run after each deploy, on `https://pixelforge.<tailnet>.ts.net`, logged in as the demo user. Record the date, the result and anything odd. Keywords in the prompt are case-insensitive and count as text tokens.

Results: _not run yet; to be filled in after the first deploy._

| #   | Scenario                                                        | Expected                                                                                                          | Result |
| --- | --------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- | ------ |
| 1   | One normal generation per quality (low, medium, high)           | Each passes queued → processing → succeeded live; the charge is inside the estimate                               |        |
| 2   | A generation with 2 reference image URLs                        | Actual image tokens = 1,530 on the detail page                                                                    |        |
| 3   | Prompt with `[fail]`                                            | Accepted, then `failed`; "You were not charged"; no ledger entry                                                  |        |
| 4   | Prompt with `[blocked]`                                         | Fails right away with "Prompt rejected by the provider's content policy"; free                                    |        |
| 5   | Prompt with `[overloaded]`                                      | Retries (status line says the provider is busy), then fails clearly when the deadline passes; free                |        |
| 6   | Prompt with `[duplicate]`                                       | One charge only; the Pixelforge dashboard shows each event delivered twice; second delivery logged as `duplicate` |        |
| 7   | Prompt with `[slow]`                                            | Live status shows processing for 60-90 s, then succeeded                                                          |        |
| 8   | 4 generations in a row                                          | The 4th shows "Waiting for a free slot at the provider" and completes after one of the first three                |        |
| 9   | Resend an already delivered event from the Pixelforge dashboard | Nothing changes (`duplicate` in the logs)                                                                         |        |
| 10  | `dc exec app php artisan pixelforge:reconcile-usage`            | "All days match: difference 0.000000000."                                                                         |        |

Leave these generations in place (including the failures) for PromptEdit to compare with Pixelforge's records.
