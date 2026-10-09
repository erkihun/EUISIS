# Transfer Screening and Selection

Screening starts from submitted applications and is recorded as review history. The deterministic resolver performs only configured active-employment, assignment, organization, position, grade, and service-duration checks; no browser-supplied eligibility result or executable rule is trusted.

Selection is locked and capacity is rechecked during final implementation. It never changes an assignment. When selection reaches the configured final approval (or no approval gate is configured), `CanonicalTransferCreationService` creates exactly one linked `employee_transfers` record. The unique application link makes retries idempotent. Assignment changes remain exclusive to canonical transfer implementation.

**NEEDS_DECISION:** screening reason taxonomy, shortlist/ranking methodology, committee composition, reserve-list handling, and whether selection approval satisfies any source/destination release stage.
