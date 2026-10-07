# Assessment scoring

`App\Services\Assessment\AssessmentScoringService` is the only place an assessment is scored.

- **Server-side, decimal and predefined:** it runs on the server with bcmath decimals, never floats. Administrators choose a method from a fixed list and never write a formula.
- **No frontend totals:** totals shown in the browser are previews only and are never stored.

## Definitions

| Term | Value |
|---|---|
| Criterion score | The score of the selected rating option, snapshotted with the response. |
| Criterion maximum | The configured maximum, else the best option. A score above it is refused. |
| Section raw / maximum | The sum of its criterion scores / maxima. |
| Raw total / form maximum | The sum of section raws / maxima. |
| Percentage | Raw total / form maximum × 100. |
| Section percentage | Section raw / section maximum × 100. When every criterion in the section has a weight: Σ (score / criterion maximum × weight). |
| Weighted score | Σ section percentage × section weight / 100. Weights sum to 100. |
| Contribution | Percentage (or the weighted score, under the weighted method) × overall contribution weight / 100. |

## Scoring methods

| Method | Final result |
|---|---|
| `raw_score` | The raw total. |
| `percent_of_max` | The percentage. |
| `weighted_score` | The weighted score. |
| `contribution_weight` | The contribution, in percentage points. |

The contribution is computed whenever a version has an overall contribution weight, whatever the method.

**Example:** raw 24 of 30 = 80%. With a contribution weight of 5, the contribution is 80 × 5 / 100 = **4.0** points. Neither 30 nor 5 is built in; each form configures its own maximum and weight.

## Several evaluators

Each evaluator type is scored separately.
- **Several evaluators of one type** (for example two peers) are combined by the configured aggregation; `average` is the only one so far.
- **Different types** are combined by their contribution weights: Σ component percentage × weight / 100.
- **Validation:** weights must sum to 100, checked before publishing. They are never rescaled, and components are never averaged blindly.
- **Missing components:** if a weighted component is missing, the combined result is not computed.

Example: manager 80% at weight 95, peers 60% at weight 5 → 76 + 3 = **79**.

## Precision

Intermediate values keep 10 decimals. Stored results are rounded half-up to 4 decimals, and the UI shows 2. An aggregate is always computed from unrounded parts, so rounding error does not accumulate.

## Not done here

No score is inferred from activity logs, messages, attendance or Daily Activity counts. Behavioural and competency results come only from evaluators answering a published form. The Daily Work Register measures work against task standards, which is a separate domain.
