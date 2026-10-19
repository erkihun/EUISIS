# Partner API security tests

Run:

```bash
php vendor/bin/pest tests/Feature/Api tests/Feature/MasterBackboneDomainRemediationTest.php \
  tests/Feature/Security/ExternalApplicationOrganizationScopeTest.php tests/Feature/Settings/ApiManagementTest.php
# reference consumer (own vendor; needs an APP_KEY)
cd external-apps/cafeteria-system && APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));') php vendor/bin/pest
```

| Area | Test |
| --- | --- |
| F1 provider token cannot read directory/verification | `MasterBackboneRemediationApiTest` "refuses a provider-portal token…" (5 routes), `IdCardIntegrationApiTest` |
| F1 scope removal immediate; person needs permission | "stops an application at once…", "holds a person…" |
| F2 unreviewed / scoped self / scoped subtree / outside → 404 | F2 cases; `ExternalApplicationOrganizationScopeTest` (SEC-010) |
| F3 UUID end-to-end; consumer fails closed | F3/F4 case; cafeteria `ServeEmployeeTest` |
| F4 machine principal eligibility, 422 | F3/F4 case, 422 case |
| F5 seven card states | F5 dataset |
| F6 replay, conflict, in-progress, DB uniqueness, per-principal | F6 cases |
| F16 unbound app, wrong provider, mixed offline batch, batch cap | F16 cases |
| F17 signed link serve / tamper / revoke / expire / withheld | F17 cases |
| F18 period bound, invalid period, other provider | F18 case |
| F19 entitlement selection | F19 case |
| Archive guard; transfer JD | `MasterBackboneDomainRemediationTest` |
| Access review findings and UI fields | `ApiManagementTest` (last three) |

Every test uses synthetic records; none reads production data.

The audit's observed-behaviour evidence (`docs/audits/BackboneBoundaryEvidenceTest.php`)
describes the pre-remediation state and is kept for the record; it is not part of
the suite and now fails by design.
