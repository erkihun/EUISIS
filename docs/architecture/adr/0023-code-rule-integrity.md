# ADR 0023: Code rule integrity before go-live

- Status: accepted (2026-11-01)
- Context: an end-to-end review of code rules (rule administration, resolution, generation,
  sequences, manual codes) before institutions are registered in production found that editing a
  rule changed more than the person changed, that the "next number" on the form did not move the
  counter codes are taken from, that a rule could be pointed at another record type after issuing
  codes, that typed codes and several entity types were not checked for clashes before use, and
  that running out of free numbers ended in a server error.

## Decision

1. **Only a changed next number is a change.** The edit form sends `next_number` on every save; it
   is applied only when it differs from the stored value. A change moves the global sequence row
   (where codes are actually taken from) and becomes `initial_sequence_number`, the start for new
   per-scope sequences and periodic resets. It may not go below the number already reached
   (codes would repeat); the sequence reset action remains the deliberate way back.
2. **A rule that has issued codes keeps its record type.** Changing `entity_type` is refused once
   the rule has generation logs or sequences; a new rule is created instead.
3. **Every entity type with a known code column is checked before a code is handed out.** Where no
   explicit collision check is registered, the manual-override target (model and code column) is
   used.
4. **A typed code is held to uniqueness** where the entity's codes are unique across all records
   (organizations, types, employees, positions, ID cards and module references). Units and the
   design catalog repeat codes under different parents, so their own request validation scopes the
   check.
5. **No free number is a validation message, not a server error** (`SequenceCodesExhaustedException`).
6. Codes are cut to 100 characters, not bytes, so an Ethiopic prefix is never split mid-letter.

## Not decided here (owner)

- `{YEAR}` and yearly resets use the Gregorian year; `{FISCAL_YEAR}` is also the Gregorian year
  (its comment wrongly places the Ethiopian fiscal year at Meskerem 1; the federal fiscal year
  starts Hamle 1). Whether codes should carry the Ethiopian year is a numbering-policy decision.
- Archiving the only active rule of an entity stops new records of that entity until a new rule is
  active; this is how rules are replaced, so it is not blocked.
