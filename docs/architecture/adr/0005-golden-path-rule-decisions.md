# ADR 0005: Golden Path rule decisions from the Stage 1 re-audit

Status: accepted 2026-10-25. Decided by the engineering lead under the project
owner's delegation ("decide owner rule conflicts from the project's own
properties"), choosing the integrity-first, compatibility-safe option each time.

## Context

The code-only re-audit of Golden Path Stage 1 (25 October 2026) found five
places where two parts of the system held different rules for the same thing.
A single Core action cannot carry two rules, so each needed a decision.

## Decision

1. **Moving an occupied position.** A direct move (`MovePositionAction`,
   `positions.move`) still refuses an occupied position. An approved
   organizational change request may move one: restructuring is the governed
   path for it (draft, review, approval, implementation). The holders' current
   active placements follow the position to the new unit in the same
   transaction (same position, same organization; each change records
   `assignment.updated`). Before this, the unit on the placement stayed behind.
2. **Changing an employee number.** An issued employee number never changes; it
   is a stable identifier like the employee UUID (Stage 4 "stable identifiers").
   It is no longer a self-service correction field, and a correction request for
   it filed earlier can only be rejected. The HR edit form already kept it
   read-only.
3. **Employee number sequence.** The rule resolved for the employee's
   organization is canonical (registration and the CSV import use it; it falls
   back to the global rule when no organization rule exists, which is the seeded
   setup). The organization structure import keeps the global rule because it
   can create the organization in the same file, before the organization has an
   id to scope a rule to. No code change.
4. **Who may suspend and reinstate an ID card.** No new permission: suspension
   follows `id-cards.revoke` (or `cards.manage`), reinstatement follows
   `id-cards.activate` (or `cards.manage`), both within organization scope.
   Suspension is the reversible block; revocation stays final.
5. **Code format of copied positions.** Kept: a copied position gets a
   code-rule `code` and the job position code `{code}-JPC`, so a copy never
   collides with the source's codes and needs no extra code rule.

## Alternatives considered

1. Refuse occupied moves everywhere (forces a transfer of every holder before
   a restructuring; impractical for a reorganization) or allow them without
   moving the placements (leaves placements in a unit the seat left; rejected).
2. Allow corrections to the number (breaks references printed on cards and held
   by partners; rejected).
3. Force one global sequence everywhere (would ignore an organization rule an
   owner configured) or force organization rules in the structure import (not
   possible for organizations created in the same file).
4. Separate `id-cards.suspend` / `id-cards.reinstate` permissions: deferred
   until the ID card office asks for a split; adding them is additive.
5. Regenerate the job position code through the code rule: needs an active
   rule for every copy target; rejected for now.

## Consequences

- Restructuring keeps placements consistent with their positions.
- Employee numbers are immutable after issue across every entry path.
- No schema change, no new permission, no API or event change.

## Migration impact

None. Existing pending employee-number correction requests can be rejected by
HR; nothing is rewritten.

## Security impact

None new; suspension and reinstatement reuse existing, scoped permissions.

## Operational impact

None.
