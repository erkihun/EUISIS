# ADR 0022: Organization registration integrity before go-live

- Status: accepted (2026-11-01)
- Context: the owner is about to register the city's institutions in production. Once employees,
  units, positions and hierarchy links point at an organization, correcting a wrong registration is
  costly. An end-to-end review of the register form, the edit form and the structure import found
  that the same institution could be registered twice under one name, that a new or edited record
  could take statuses no workflow stands behind (merged with no body merged into, archived without
  the archive action), that a type change ignored the organization's place in the hierarchy, that a
  rename overlapped the previous name's period, and that the import skipped the name history and
  showed a parent it never linked.

## Decision

1. **One live organization per name.** A draft, active or inactive organization holds its English
   and its Amharic name to itself (`OrganizationDuplicateNameFinder`). Names compare without case
   and with runs of spaces collapsed, and are stored collapsed. Archived, merged and dissolved
   organizations release the name, so a re-established body can be registered again. The register
   form, the edit form and the structure import all apply the rule.
2. **Statuses a form may set.** Registration accepts draft, active or inactive. The edit form adds
   dissolved; merged and archived are kept only when already set (archiving has its own action, and
   no merge flow exists to name the body merged into).
3. **A type change must fit the hierarchy.** The new type must accept every parent, and be accepted
   by every child, that the organization has in the published version and in drafts — the same
   `allowsParentType` rule a new link is held to.
4. **Name history reads as a sequence.** A rename closes the open name record on the day of the
   rename and starts the new one that day. The first record still covers the organization's own
   period. The import keeps the same history.
5. **The import registers like the form.** A new organization from a file needs both names. A
   parent code in the file is reported as a warning: the import does not create hierarchy links,
   which go through a draft version and its publication. An Organizational Admin, who may only
   register under their own organization, cannot create a new organization from a file.
6. **Child registration says when it will show.** The link goes into a draft version while the
   parent's page reads the published one, so the confirmation names the version to publish.

### The rest of the registration chain

7. **Types in use stay.** An organization type that any organization carries, or that another
   type lists as an allowed parent, cannot be deleted (deactivate it instead). Renaming a type's
   code rewrites that code in other types' `parent_allowed_types`, which store codes.
8. **Unit types in use stay.** A unit type that any unit uses cannot be archived. An inactive unit
   type is not taken up by a unit that does not already have it.
9. **Units stay where they are made.** A unit's organization cannot change through the edit form
   (it is shown read-only; its sub-units, positions and assignments would stay behind). A unit
   with sub-units or positions cannot be archived, as before with current assignments. Two units
   under the same parent may not share an English or Amharic name.
10. **Reporting lines.** A unit cannot report to itself.
11. **Hierarchy versions.** A link made in the tree editor follows the same organization-type rule
    as the register form. A draft copied before the current version was published cannot be
    published (it would undo that version's changes), and a draft must take effect after the
    version it replaces.
12. Refusals from the list buttons (delete type, archive unit type, archive unit) come back as an
    error toast, since those buttons have no form to show field errors on.

## Consequences

- Duplicate institutions are stopped at entry rather than found after records attach to them.
- Spelling variants the comparison cannot see (for example ሥ/ስ) still need the official list; the
  test-case document asks testers to compare against it.
- Tests that imported the same workbook twice now use a second institution name.
