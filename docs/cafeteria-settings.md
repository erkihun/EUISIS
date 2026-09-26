# Cafeteria Settings Integration

Cafeteria Settings are **system defaults**. They never set an organization's subsidy: financial values come only from approved service policies (see [cafeteria policy architecture](cafeteria-policy-architecture.md) → *System defaults vs. policy vs. operations*).

The scan calendar reflects:

- weekly day rules from `cafeteria_day_rules` (physical opening defaults)
- public holidays from `public_holidays`
- special open/closed/no-subsidy days from `cafeteria_special_days` (global or per cafeteria)
- employee leave/exclusion periods from `employee_cafeteria_exclusions`
- consumed entitlements from `cafeteria_transaction_consumed_days` (across every cafeteria)

Whether a day is an entitlement day for an employee is decided by the employee organization's policy (working days, holidays, leave), together with the cafeteria's physical availability — see [entitlement rules](cafeteria-entitlement-rules.md).

The default usage mode may only be `single_day` or `use_remaining_week`; advance use also needs the policy to allow it. Client-supplied amounts and scan times are refused; the server prices every scan from the resolved policy.

Provider users see only settings-driven scan and calendar data for their own provider's cafeterias unless they hold explicit all-provider permissions.
