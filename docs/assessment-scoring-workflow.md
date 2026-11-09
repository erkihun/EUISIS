# Assessment scoring workflow

1. The selected `rating_option_id` is validated against the criterion on the pinned form version.
2. `AssessmentScoringService` resolves the score from that option and calculates criterion, section, raw and normalized values under the configured scoring method.
3. The evaluator response stores option and score snapshots before it becomes submitted.
4. `AssessmentResultCalculator` aggregates complete response sets by evaluator scheme. Multiple peers remain independent until their scheme's configured aggregate is calculated.
5. The combined normalized result is multiplied by `overall_contribution_weight` only after aggregation. Raw scores and EPMS contribution values are not interchangeable.
6. At finalization the cycle's result-band policy classifies the normalized result and the code/labels are stored on the record. Later band-policy changes therefore do not reclassify history.

Only finalized records feed dashboard, oversight, institutional submission and `CompetencyAssessmentResultService`. Controllers do not directly write an employee's EPMS final score.
