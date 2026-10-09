# Employee availability

`EmployeeAvailabilityService` exposes only statuses supported by installed data: `official_field_work`, `approved_not_checked_in`, and `unknown`. `unknown` is intentional: it does not mean absent or in-office, because EUISIS has no source that can prove either state.

Availability is workflow status, not live tracking. The scoped query exposes employee identity, historical assignment context, Field Work reference, destination summary, and expected return. It never returns location-event coordinates. It is paginated (maximum 100 records) and constrains organization-wide views through `OrganizationScopeService`.

There is no persisted reporting-line relationship. The existing immediate-supervisor resolver uses explicit Daily Activity reviewer assignments only for approval authority; it cannot safely produce a city-wide manager availability list. A supervisor-specific availability view requires an approved, queryable reporting-line or reviewer-assignment scope design.
