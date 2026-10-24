# Master identity and organization backbone

The backbone is the authoritative chain every other module depends on. It owns
the master records; service modules own only their own transactions and refer
to backbone records by stable UUID. Decision record:
[ADR 0001](adr/0001-master-identity-and-organization-backbone.md).

```
Organization        (organization types, versioned hierarchy, structural and functional edges)
      |
Organizational unit (configurable unit types)
      |
Position            (the authorized work slot: approved, occupied, vacant, archived)
      |
Employee            (permanent identity)
      |
Assignment          (employment context: one current assignment per employee)
      |
Employee ID card    (credential; replaced cards stay in history)
      |
      +--> contracts (in process) . REST /api/v1 . outbox events, change feed, webhooks
      |
      +--> cafeteria . transport . recruitment . transfer . EPMS . daily work . field work
           public feedback . grievance . discipline . appeal . situation room . intelligence
           external systems . future services
```

## What the backbone owns

Organizations, organization types, hierarchy versions and their edges and
closure paths, organizational units and unit types, positions and their
profiles, job descriptions, grades, occupations and professions, employees,
employee status, assignments, ID cards and their lifecycle, card verification,
the organization scope foundation (`OrganizationScopeService`), the code rules
for these records, and their audit trail. The full table is in
[data-ownership.md](data-ownership.md).

## Identifiers are not interchangeable

| Identifier | Column | Meaning | Changes when |
| --- | --- | --- | --- |
| Employee UUID | `employees.id` | permanent machine identity | never: not on transfer, restructuring, position change or card replacement |
| Employee number | `employees.employee_number` | business identifier people read | only by a governed correction |
| Assignment UUID | `employee_assignments.id` | one employment context | a transfer closes it and opens a new one |
| Card record UUID | `id_cards.id` | one issued credential row | each issue or replacement is a new row |
| Card UUID | `id_cards.public_card_uuid` | verification reference in the QR code | a replacement card gets its own |
| Card number | `id_cards.card_number` | printed business number | a replacement card gets its own |
| User id | `users.id` | sign-in account | independent of the employee; an account may link to one employee |

A replacement card links back through `id_cards.previous_card_id` and
`card_replacements (old_card_id, new_card_id)`. The old card keeps its row,
status and print snapshots. No card action creates or changes an employee.

## Rules the code enforces

- One current assignment per employee and one live current card per employee:
  unique indexes in the database.
- A position cannot be archived while a current assignment occupies it.
- Employees have no delete path; their lifecycle is a status.
- A card is a valid credential only when it is active, not revoked, not
  expired, its QR is active and it is the employee's current card. The rule is
  `App\Backbone\Contracts\CardCredentialStatus`; the partner verification API
  uses it.
- Service delivery is gated by `ServiceEligibility` (employee active, current
  card active). HR administration of the employee is not gated by the card.
- Transfers move the assignment through `App\Backbone\Commands\TransferAssignment`;
  the employee record, UUID, number and card are not written.
- `tests/Unit/BackboneBoundaryTest.php` fails when backbone code imports a
  service module or a service module creates or bulk-updates a backbone
  record, beyond a shrinking list of known exceptions.

## How modules use the backbone

Inside the application, depend on the contracts in `app/Backbone/Contracts`
(bound in `BackboneServiceProvider`):

| Contract | Answers |
| --- | --- |
| `EmployeeDirectory` | who is this employee (by UUID or employee number) |
| `AssignmentContextReader` | where do they work now (organization, unit, position, version) |
| `OrganizationHierarchy` | is organization A within B, which organizations are under B |
| `CardCredentialStatus` | is this card a valid credential, and its state |
| `ServiceEligibility` | may this employee receive service X now (`check`, `checkCard`) |
| `CredentialLookup` | which card a scanned QR value or token names |

Two contracts point the other way: the backbone declares them and a service
module implements them in `ServiceModuleServiceProvider`. Without the module the
backbone binds a no-op.

| Contract | Implemented by | Used for |
| --- | --- | --- |
| `EmployeeFeedbackLinks` | service feedback | provisioning an employee's public feedback link at registration and printing it on the ID card |
| `ProviderCodeLookup` | service delivery | the `{PROVIDER_CODE}` and `{PROVIDER_PREFIX}` code-rule tokens |

Service modules also register into two backbone registries from the same
provider: code-rule targets and collision checks (`CodeRuleTargetRegistry`) and
the tables that block deleting an organization (`OrganizationDeletionGuard`).

They return immutable DTOs (`app/Backbone/Data`), never Eloquent models, so a
module cannot write back through them. Outside the application, use the
versioned API and events in [integration-api.md](integration-api.md).

Existing modules still read backbone models directly; they move to the
contracts one at a time ([migration plan](backbone-refactor-migration.md)).

## Deployment modes

- **Mode A, now:** one Laravel deployment and database. The boundary is
  ownership, contracts, dependency direction and data authority, enforced by
  tests and code review. Foreign keys between modules stay.
- **Mode B, later if justified:** the backbone deployed on its own. Contracts
  get HTTP adapters over `/api/v1`, modules keep their own data and stable UUID
  references, and consistency comes from the outbox, change feed and webhooks.
  Nothing in Mode A should make Mode B harder.
