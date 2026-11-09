# Transport — Reconciliation

Reconciliation is the screen (admin `Transport › Settlements › statement`, portal `Statements › statement`) and the service method (`TransportSettlementService::reconciliation`) that put the five figures side by side without merging them:

| Figure | Source | Who can change it |
| --- | --- | --- |
| Claimed | the provider's invoice | the provider, until submitted |
| Verified | counts of verified boardings / employee-days / completed trips in the period | nobody; only new evidence or a void changes it |
| Calculated | verified × the contract snapshot's rates | nobody; recompute re-reads evidence while the statement is a draft |
| Adjusted | sum of **approved** adjustments and disputes | a second reviewer, per adjustment |
| Approved | the amount fixed at approval | the approver, once |

Units reconciled: boardings, employee-days, completed trips (claimed vs verified with the difference), unique riders and cancelled trips (verified only), plus by-route boardings and riders.

Scenario F (claim 50, verified 37) shows `boardings_difference = 13` and the amount difference; neither figure is overwritten (`TransportBusinessScenariosTest`, `TransportReconciliationFixtureTest` with 100 employees over a week).

Exports: statement PDF (EN/AM, Ethiopian dates, dompdf Ethiopic fonts) from both tiers; the settlement table CSV/XLSX from the admin reports.
