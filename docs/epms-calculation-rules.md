# EPMS — Calculation Rules

All arithmetic uses `brick/math` `BigDecimal` through `App\Services\Performance\Calculation\Dec`:

- **Rounding:** HALF_UP.
- **Precision:** 10 decimal places while computing; 4 when stored.
- **Division by zero:** returns `null` ("not calculable"), never 0.

Floats are never used for scores.

## 1. KPI achievement (`KpiAchievementCalculator`)

| Direction | Formula | Special cases |
|---|---|---|
| `HIGHER_IS_BETTER` | actual ÷ target × 100 | target ≤ 0 → `INVALID_TARGET` |
| `LOWER_IS_BETTER` | target ÷ actual × 100 | actual 0 → cap (or 100 when the target is also 0); target 0 with actual > 0 → 0 |
| `TARGET_IS_BEST` | 100 within ± tolerance, then linear down to 0 at the zero-score deviation | zero-score deviation defaults to \|target\|; target 0 without a deviation → `INVALID_TARGET` |
| `BINARY` | 100 if achieved, else 0 | |
| `MILESTONE` | percent of the highest milestone reached | milestones flagged `requires_verification` count only when the actual is verified |

Then, in order:

1. **Floor:** a negative achievement is set to 0.
2. **Cap:** capped at the KPI or target `achievement_cap` (≥ 100; the default comes from the `default_achievement_cap` setting, 120). When `allow_overachievement` is off, the cap is 100.
3. **Status:** `OK`, `NO_ACTUAL`, `INVALID_TARGET` or `INVALID_ACTUAL`. A missing actual scores **0** in the employee score but shows as *not reported*, never as a fabricated value.

The trace records the formula, the raw achievement, the cap and whether it was capped.

## 2. Aggregating observations (`KpiAggregationService`)

Several actuals for one subject and period are combined by the KPI's `aggregation_method`:

| Method | Rule |
|---|---|
| `SUM` | Σ values |
| `AVERAGE` | Σ values ÷ n |
| `WEIGHTED_AVERAGE` | Σ(value × w) ÷ Σ w, where w = weight, else denominator, else 1 |
| `RATIO_FROM_TOTALS` | Σ numerators ÷ Σ denominators (× 100 for percentage KPIs). **Never an average of percentages.** |
| `MIN` / `MAX` | smallest / largest |
| `LATEST_VALUE`, `MILESTONE`, `NO_AGGREGATION`, `CUSTOM_FORMULA` | latest observation |

Combining **children** into a parent target (roll-up):

- `LATEST_VALUE` children are summed (each child's latest level adds up).
- `MILESTONE`, `NO_AGGREGATION` and `CUSTOM_FORMULA` cannot be combined. The parent needs its own measurement.

## 3. Which actuals count

- An agreement item counts only actuals of **its configured data source**, inside the agreement's effective period. For example, a manual entry on a `DAILY_ACTIVITY` KPI is ignored.
- **Daily activity:** Σ `quantity` of *approved* daily-activity items linked to the item, in the period. This is stored as one actual with `source_key = daily_activity`. The count of activities is never used.
- **System sources:** computed by `SystemKpiSourceRegistry`; stored as verified.
- **Manual / document / survey:** entered by the covering manager with `kpi_actuals.enter`. A verified actual can be changed only by a verifier. The person who enters an actual cannot verify it (separation of duties).
- **Amended targets:** the item's amendment chain is followed, and each version applies to its own effective period.

## 4. Employee score (`EmployeeScoreCalculator::trace`)

```
item weight          = objective weight × target weight ÷ 100        (when built from the position plan)
item weighted        = achievement % × item weight ÷ 100
results score        = Σ item weighted                                (items total 100)
competency score     = Σ(rating ÷ scale max × 100 × weight) ÷ Σ weight
final score          = results × results_weight ÷ 100 + competency × competency_weight ÷ 100
rating               = RESULT scale band where min ≤ final ≤ max
```

`results_weight` and `competency_weight` are settings (default 80 and 20; they must total 100).

Worked example: target 1000, actual 900 → 90%; weight 100 → results 90. Competencies all 4 of 5 → 80. The final score is 90 × 0.8 + 80 × 0.2 = **88.0000**, rated *Very Good*.

The trace is also `complete = false` while any item has no actual or a competency is unrated. Finalizing requires rated competencies.

### Result values

| Field | Meaning |
|---|---|
| `calculated_score` | the formula output; never overwritten |
| `adjusted_score` | an approved MANAGER adjustment (setting `allow_score_adjustment`, reason, a different approver) |
| `calibrated_score` | the calibration panel's decision |
| `final_score` | the value in force: calibrated › adjusted › calculated |

Each change is written to `performance_score_adjustments` with the original score, the new score and the reason.

### Transfers within a cycle

Each agreement has its own result. The cycle total is Σ(final score × days in the agreement) ÷ Σ days (`combinedForCycle`). It is shown only when `prorate_transfer_results` is on.

## 5. Unit and organization performance (`PerformanceAggregationService`)

For a plan target, the actual is:

1. **Its own measurement**, if the target has one. The own measurement wins, and contributors are then ignored.
2. Otherwise, the **direct contributors** combined by the KPI's aggregation method:
   - child-plan targets whose `parent_target_id` is this target (each measured recursively by the same rule), and
   - employee items whose `position_target_id` is this target.

Only direct children are read. A grandchild reaches the parent only through its child's value, so nothing is counted twice. Each contributing actual is recorded in `kpi_contributions`, unique per (parent target, source actual), and the combined value is persisted as an `aggregate` actual. Circular lineage is detected with a visited set and ignored.

```
objective score = Σ(target achievement × target weight) ÷ 100
plan score      = Σ(objective score × objective weight) ÷ 100
```

A plan with no measured target has score `null` ("not calculated"), not 0.

### Health

The health of a target (dashboard "KPIs needing attention") is based on its achievement:

- **SUM KPIs:** the achievement is first prorated by elapsed time: achievement × period days ÷ elapsed days.
- **Thresholds:** ≥ `at_risk_threshold` (80) is ON_TRACK; ≥ `off_track_threshold` (60) is AT_RISK; below that is OFF_TRACK. No actual is NOT_REPORTED.

## 6. Rating scales

Bands are inclusive at both edges and stored to 4 decimal places, so 89.9999 is *Very Good* and 90 is *Exceptional*. Band edits apply to new calculations only; finalized results keep the label in their snapshot. Band edits are audited as `setting_updated`.

The seeded bands have no forced distribution or quota.

## 7. Reproducibility

`calculate()` stores the whole trace as `snapshot_json`: inputs, per-item formulas, weights, settings and the timestamp. Tests assert that the stored result equals its own trace. After finalization:

- the result row cannot be updated, except for its release fields, and cannot be deleted;
- the agreement becomes FINALIZED;
- an appeal decision writes a new revision (`revision_no + 1`, `is_current`) and leaves the original row intact.
