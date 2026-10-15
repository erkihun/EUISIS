# Executive Situation Room — Handoff

Branch `claude/cool-cori-b4dsgk`, PR erkihun/EUISIS#7. Status as of 2026-10-14.

## Architecture and placement

1. One room at `/situation-room` with three parts: category launcher with 16 categories, Community Voice by Institution (from `main`), and the Action Register (from `main`) — `situation-room-architecture.md`.
2. Category registry `SituationCategory` drives the launcher, routes, grants, launcher sections, icons and wall eligibility.
3. One analytics class per category (`app/Services/SituationRoom/Analytics`), each panel a closure run in isolation by `SituationRoomService::run()`.
4. Panel contract `SituationPanel`: kpis, bar, hbar, stacked, line, donut, table, notice (with a reason).
5. Sidebar: one "Executive Situation Room" group with Situation Room, Community Voice, Wall Mode, Action Register and Executive Reports, each permission-gated.
6. Transport is out of scope: no category, no metric, no label (tested).

## Access and scope

7. Scope resolved once per request through `OrganizationScopeService` and applied before any aggregation (`SituationScope::apply`).
8. Roll-up along the published hierarchy: own office plus one row per direct child, each child including its subtree; name order.
9. A viewer with a single top organization is taken one level down automatically.
10. A forged or out-of-scope `organization_id` returns 403 before counting (tested on workforce, complaints and overview).
11. No sibling or parent leakage (tested: Subcity A never sees Subcity B or the Bureau).
12. One permission set: six core grants plus six per-domain grants and `system-settings.manageSituationRoom` — `situation-room-access-control.md`.
13. Community Voice now requires `public_voice.view`; the migration grants it to every role holding `situation_room.view`.
14. Users with no scope record remain unrestricted (existing `OrganizationScopeService` semantics) — see open decisions.

## Categories (figures defined in `situation-room-metrics-catalog.md`)

15. Executive Overview: headlines per permitted domain, each linked; open actions of the scope.
16. Workforce: headcount, capacity, vacancy (rule D-2), gender, age bands, employment type, status, occupations, grades.
17. Organization Structure: organizations, units, positions, staffing table per roll-up row, hierarchy status.
18. Service Delivery: services, work recorded per service, community voice per service.
19. Public Complaints.
20. Public Feedback (with ratings).
21. Tip-offs (Corruption / Improper practice / Unclassified older).
22. Daily Operations: days reported, reporting rate, late days, review queue.
23. Performance (EPMS): current-cycle plans, agreements, results, released ratings (small groups protected), appeals.
24. Employee Movement: inbound / outbound / internal transfers, employment-status changes.
25. Field Work: under way, past return, awaiting approval, destinations, types.
26. Cafeteria: meals, refusals, subsidy and contribution totals.
27. Grievance, Discipline & Appeal: counts only, kept apart from public complaints.
28. Critical Attention: five configured rules, CONFIGURATION_REQUIRED until set.
29. Trends & Comparison: period vs previous, twelve months, organizations side by side.
30. Data Quality: twelve checks with base, share and where each is fixed.

## Data rules

31. Nothing estimated: NEEDS_DATA for education, length of service and headcount history.
32. GIS_DATA_NOT_AVAILABLE / MAP_LAYER_NOT_CONFIGURED for the field-work map.
33. SERVICE_LINKAGE_REQUIRED where submissions do not name a service.
34. NEEDS_POLICY_DECISION_REPORTING_HIERARCHY when no hierarchy is published.
35. Public complaint ≠ grievance ≠ discipline ≠ appeal: never added together, stated on screen.
36. Counts are "received", never findings; organization rows never ranked.
37. The room never writes to a source record (tested); only the Action Register writes.
38. Thresholds only from configuration (System Settings › Situation Room; community-voice rules on Public Feedback › Settings).

## Privacy

39. No submitter identity, narrative or evidence in any payload or export (tested per category).
40. Employee names only with `view_employee_targeted` + `service_feedback.view_employee_target`, one focused organization, name order, viewer excluded, audited.
41. Small-group rule ("fewer than k", default 5) on rating distributions and caseload; withheld cells never read as "nothing recorded".
42. Wall: aggregates only, enforced on the server whatever the grants.
43. Exports: same scope and filters as the screen, never names, audited.
44. Cache keys include scope, filters, detail grants and the viewer's employee id; nothing in browser storage but the language.

## Performance (`situation-room-performance.md`)

45. Scope-first grouped SQL; nothing loads entity rows into PHP.
46. Request-scoped memo removed 45+ duplicate queries per category.
47. Measured on PostgreSQL 16 with 120k employees, 400k submissions and 600k logs: every category ≤ 0.52 s city-wide uncached; ≤ 0.2 s for a subcity.
48. New index `esf_org_type_submitted_idx`; EXPLAIN shows index scans for the scoped queries.

## Experience

49. Deferred figures, lazy chart library, per-card skeletons and error isolation.
50. Responsive at 390 / 768 / 1024 / 1440 / 1920 px with no horizontal scroll (browser-tested).
51. Keyboard reachable; each chart has a "Show the numbers" table and a text summary.
52. Freshness shown on every view, with Refresh.
53. EN/AM from one dictionary shared with exports; English exports Gregorian, Amharic exports Ethiopian.
54. Wall: rotation, pause (Space, ← →), full screen, timed refresh.

## Verification

55. 118 Situation Room tests (this branch's and main's) pass on SQLite; with Settings, 236 pass on PostgreSQL.
56. Regression subsets (Unit, Permissions, Settings, Public Feedback): 703 passed. The single failure (`AdminUiConsistencyTest` raw dark slate 103 > 102) is identical on `main`.
57. `tsc`, `vite build`, strict i18n audit and Pint on all touched files pass; Ziggy route snapshot regenerated.
58. Browser checks in English and Amharic: launcher, categories, drill-down, chart tables, wall, attention, action deep link, Community Voice, reports, exports.

## Merge with main

59. Two independent implementations unified as above. Retired from this branch: its own action register (never shipped), main's separate wall page and `WallLayout`.
60. Main's components moved under `Components/SituationRoom/communityVoice` (they would otherwise clash on case-insensitive checkouts).

## Open decisions

61. D8 kiosk session for walls; map layer for field work; headcount snapshots; unrestricted scope-less users; review of the Community Voice `public_voice.view` gate; UAT (`situation-room-uat.md`) with production data.

## Verdict

62. **CONDITIONAL_SITUATION_ROOM_READY.** No known P0 or P1 defect in the Situation Room. Conditions: CI green on the merged head, the UAT script run on production-like data, and a product decision on the open items in 61.
