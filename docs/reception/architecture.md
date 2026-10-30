# Reception architecture

Reception follows the repository module layout. `Domain/Models` owns persistence, `Application/Services` owns transactional workflows and scope enforcement, `Http/Requests` validates input, controllers adapt Inertia/HTTP, `Contracts/Data` publishes permission names, and the module provider owns routes, morph names and code-rule registration.

The main invariant is two-dimensional authorization: a user must hold the operation permission and the target organization must be reachable through `OrganizationScopeService`. A referral checks both source and destination. Reads are filtered at query construction, not after serialization.

Visitor phone values use Laravel encryption at rest. A keyed SHA-256 digest supports exact repeat lookup without decrypting every row. Lists expose only a masked phone. National ID is not collected.
