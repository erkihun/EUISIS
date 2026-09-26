# Cafeteria networks and organization access

Part of the [cafeteria policy architecture](cafeteria-policy-architecture.md).

## Provider → network → locations

```
Provider A                      (providers — the payee)
└── Network A                   (cafeteria_service_networks)
    ├── Cafeteria 1 — Main      (cafeteria_providers, location_type = main)
    ├── Cafeteria 2 — Branch    (location_type = branch, parent = Cafeteria 1)
    └── Kiosk — Service Point   (location_type = service_point)
```

Rules (`CafeteriaNetworkService::assertPlacement`):

- every location of a network belongs to the network's provider;
- a network has at most one main cafeteria; a main cafeteria has no parent;
- a branch or service point must belong to a network; its parent (default: the network's main) must be in the same network;
- no chain of parents may loop;
- an inactive, archived, closed or temporarily closed location serves no one.

The parent/child hierarchy is **for display and grouping only**. It never grants access.

Creating a cafeteria (Cafeteria Management → Cafeterias → Add) does the whole placement in one step: reuse a provider or register a new one (with its `cafeteria` service, which the provider portal needs), start a new network with a main cafeteria or join an existing one, and link the legacy service-provider registry used by terminals. The operating provider is fixed afterwards: a new operator is a new location, so transactions keep their payee. A location may move to another network of the same provider (audited); organization access then follows the network it is in.

## Organization access — who may eat where

`organization_cafeteria_access`: organization + network + dates + status (pending approval → active → ended).

| Setting | Effect |
|---|---|
| `primary_cafeteria_id` | the default location — always allowed |
| `allow_cross_location_usage` | every other active location in the network is allowed too |
| location exception `is_allowed = false` | this location is excluded, even with cross-location usage |
| location exception `is_allowed = true` | this location is allowed, even without cross-location usage |

Access is **explicit**. Two organizations with identical policies, or a cafeteria "primarily serving" an organization, grant nothing. A primary cafeteria is a convenience, not a restriction, when cross-location usage is on. If the main cafeteria is temporarily closed, employees use another allowed location — their policy does not change.

## Service assignment — who may serve whom

`cafeteria_service_assignments`: organization + provider, optionally narrowed to a network or a cafeteria, dated and approved. It is the contractual link policies hang off. A policy scope must equal or narrow its assignment's scope.

## Reference case

```
Provider A
├── Cafeteria 1 — Main      (primarily serves Organization 1)
└── Cafeteria 2 — Branch

Organization 1: policy subsidy 120 ETB
Organization 2: policy subsidy 150 ETB, primary Cafeteria 2, cross-location usage ON
Employee X: Organization 2
```

Employee X eats at **Cafeteria 1**:

| | |
|---|---|
| Employee organization (entitlement owner, billed) | Organization 2 |
| Service location | Cafeteria 1 |
| Provider (payee) | Provider A |
| Applied subsidy | **150 ETB** (Organization 2's policy — not 120) |
| Billing liability | Organization 2 |
| Provider payable | Provider A |

The same day's entitlement cannot later be consumed at Cafeteria 2 — or at any other location (see [entitlement rules](cafeteria-entitlement-rules.md)). With cross-location usage off, Employee X could eat only at Cafeteria 2.

Covered by `tests/Feature/Cafeteria/CrossOrganizationUsageTest.php` and `OrganizationAccessTest.php`.
