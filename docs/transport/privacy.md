# Transport — Privacy

* **Data minimization by tier.** Providers (portal, device API, manifests PDF) see employee name and number only. Organization, manual reasons and eligibility snapshots appear on the staff tier within scope. National ID, contact and HR fields never leave the employee module.
* **Cards carry no PII.** The QR is a stable card URL; NFC tags hold a reference. The scan value is hashed before storage.
* **Vehicle GPS is not employee tracking.** Positions (behind `transport_gps`) belong to a trip and a device, never to an employee, and are purged after `gps_retention_days`.
* **Boarding is not attendance, payroll or discipline.** The My Portal card says so to the employee; reports distinguish boardings, riders, trips and employee-days without judging anyone.
* **Employees see their own usage** (My Portal › Services): route, stops, today's trips, boardings and refusals with reasons.
* **Retention.** Boarding evidence is kept as settlement evidence; retention beyond that is `NEEDS_POLICY_DECISION_TRANSPORT_RETENTION`.
* **Exports** are scoped, rate-limited and logged; CSV cells are formula-safe (`csv_safe_row`).
* **Audit** records who voided, approved, revoked and changed what, with reasons.
