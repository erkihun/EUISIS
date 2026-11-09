# Employee appeal and Administrative Tribunal integration

An employee appeal is a distinct record (`grievance_appeals`) linked to one issued grievance decision. It records the appellant, reason, filed date, deadline snapshot, source stage, and routed destination stage. Filing an appeal creates a new stage; it never overwrites the grievance or its source decision.

When an approved route targets an external authority configured as the Administrative Tribunal, the system creates or updates the separate `administrative_tribunal_cases` register linked to the grievance. Tribunal users are limited to cases they currently handle and to their organization scope. A `grievances.tribunal` permission by itself is not sufficient to list, open, or update arbitrary Tribunal records.

This is an internal register, not a substitute for an external Tribunal filing system. It does not claim electronic court filing, qualified signing, payment, or an authoritative court/tribunal API integration.

## NEEDS_DECISION

Confirm the actual Tribunal jurisdiction, admissibility/late-filing process, filing window, record-copy obligations, withdrawal/refiling rules, remand/execution lifecycle, external-system API authority, and legal signature requirements from the governing authority before enabling a production Tribunal integration.
