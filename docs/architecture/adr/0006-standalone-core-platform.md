# ADR 0006: The Core as a standalone platform

- Status: Accepted
- Date: 2026-10-25
- Decided by: the platform owner (delegated decision, recorded for review)

## Context
ADR 0002 made the Core (Organization → Unit → Position → Employee → Assignment →
ID Card) the write authority inside EUISIS, and the Core stages 1–8 put
contracts, ratchets and snapshots around it. The Core still shared one
application, one database and one deployment with every service module. The
owner asked for the Core to stand on its own: a separate backend, frontend and
database in its own folder.

## Decision
The Core platform lives in `core-platform/`:

- `core-platform/backend`: a Laravel 13 API (`/api/v1`), token sign-in
  (Sanctum, 8-hour tokens), its own database (`euisis_core`). It owns the 14
  Golden Path tables with the same columns and identifiers as EUISIS, the
  organization scope rule (scope = WHERE over the published hierarchy; fails
  closed), seat capacity, one current placement per employee, card transitions
  and its own audit log carrying the request id.
- `core-platform/frontend`: the EUISIS screens (English and Amharic), copied
  unchanged and served by the Core backend (see "The screens are the EUISIS
  screens" below). The API stays for EUISIS and partner systems.
- Data enters by copy: `core:import-from-euisis` reads EUISIS (read only),
  keeps every UUID, number and public card reference, and `core:snapshot`
  (same algorithm and table list as EUISIS `core:snapshot`) proves the copy
  matches before anyone relies on it.

EUISIS keeps writing master data until the cutover below. Service modules are
not moved; they keep reading the Core tables inside EUISIS until each one is
switched to the Core API.

### Golden Path rules carried into the Core (2026-10-25)

The Core platform runs the whole Golden Path with the EUISIS rules
(organization structure, positions and establishments, employment, the card
request and card lifecycle, the integrity checks). Four points needed a
decision, taken under the owner's delegation:

1. **The tree is the reports_to edges.** The city's published hierarchy is
   recorded as `reports_to` edges; EUISIS builds its tree and closure paths
   from the published version's edges. The Core treats `reports_to` and
   `structural_parent` as the tree (scope, hierarchy page, parents) and
   creates new parent edges as `reports_to`. Oversight, coordination and the
   other relationship kinds do not place an organization in the tree.
2. **Ending employment suspends the cards.** Besides closing the placement
   (ADR 0002), separation suspends the employee's active and issued cards in
   the same transaction. EUISIS only refuses such cards at verification and
   reports them as a data-quality finding; suspension (reversible) stops them
   at the source without the finality of revocation.
3. **Numbering rules are formats.** EUISIS code rules become one format per
   kind in Core settings › Numbering (`{seq:N}`, `{year}`), with one counter
   per rendered pattern; a typed code is kept as given.
4. **Production steps per card.** Print batches stay in EUISIS; the Core marks
   one card printed, then issued, then active.

### The screens are the EUISIS screens (2026-10-25)

The owner asked for every Core screen to be exactly the EUISIS one. The Core
now serves the EUISIS pages themselves (copied unchanged by
`core-platform/frontend/scripts/sync-from-euisis.mjs`, checked in CI) through
Inertia, under the EUISIS route names. Decisions taken under the delegation:

5. **One copy, one patch.** Pages, components, styles, the UI kit and the
   language files are copied byte for byte. The only change is in `app.tsx`:
   a link to a page that stays in EUISIS resolves to `#` instead of failing.
6. **Browser sessions.** The screens sign in with a session (database
   driver) that ends after Core settings › Security › session length without
   activity, as in EUISIS. The API keeps its 8-hour tokens.
7. **Roles as EUISIS permissions.** Each Core role grants a fixed list of
   EUISIS permission names (`CorePermissions`), so the pages offer what EUISIS
   offers for those rights. An account still has one role and one scope
   organization: when several roles are ticked the highest one is kept.
8. **Contact details on the screens.** Employee and card pages show phone,
   e-mail, address and emergency contact to Core readers, as EUISIS shows them
   to `employees.view`. The national id stays hidden (EUISIS needs
   `employees.viewPii`, which no Core role has). The API still returns none of
   these fields.
9. **Public card check.** The holder's code goes by e-mail only; the Core has
   no SMS gateway.
10. **Hierarchy versions are read only on the screens.** The draft, review,
    approval and publication workflow (ADR 0007) runs through the Core API;
    the EUISIS version pages show the versions and their trees.
11. **Core-only settings.** Numbering formats and the workforce seat default
    appear on the General tab of the EUISIS settings page.
12. **Rules from the Golden Path audit (ADR 0007) hold on the screens too.**
    A one-time password is replaced on the EUISIS forced-password page before
    any other screen; settings and deleting organizations are for a city-wide
    administrator. A possible duplicate employee is not saved at first: the
    EUISIS form warns under its reason field and names the matches; saving the
    same person again with a reason (5+ characters) gives RegisterEmployee its
    confirmation, and the decision is recorded as a duplicate review.
13. **Exit Core** opens `EUISIS_APP_URL` when it is set, otherwise it signs
    the person out.
14. **Settings are the EUISIS settings (2026-10-26).** The settings page is
    the EUISIS System Settings page with the EUISIS tabs and fields; the
    definitions are the EUISIS registry, copied by the sync script. The Core
    keeps its stricter defaults where it had the same key (for example the
    12-character password minimum and the 8-hour session), shows its own rules
    in the matching tab's "Other settings" card, and maps the EUISIS MFA card
    onto its per-class MFA switches. It leaves out what it does not do: SMS
    and Telegram (no gateway), EUISIS-only notification events, upload type
    lists, password breach and common-password checks, forced HTTPS (done by
    the web server), audit retention and the landing-page choice. E-mail
    settings saved on the page replace the `.env` mail values.
15. **The card template designer is the EUISIS one (2026-10-26).** The
    templates page is EUISIS `SystemSettings/IdCardTemplates`, run by the
    EUISIS model, rules, action, request and controller copied by the sync
    script (backgrounds, logos, seal, signature, layout boxes, text styles,
    header, back photo, default per orientation). The card pages get the
    designed template through the EUISIS `idCardTemplate(s)` props. The
    Core's print record (ADR 0010 template versions, batches, credentials)
    stays; `CardTemplateMirror` makes the default active designed template
    the active print version, so nothing is activated in a second place.
    The server-side print SVG keeps the Core's own drawing for now.

## Consequences
- The Core can be deployed, scaled, backed up and secured on its own.
- Two copies of the master data exist during the transition. Only one may be
  the writer at any time (ADR 0002 write modes): EUISIS in `core`/`legacy`
  mode before the cutover; the Core platform after it, with EUISIS put in
  `cutover` (read only) and then fed from the Core.
- Accounts are separate: Core accounts have one of three roles
  (`core_viewer`, `core_officer`, `core_admin`) and an optional scope
  organization. EUISIS roles are not copied.

## Migration impact
1. Expand: deploy the Core platform with an empty database; `migrate`.
2. Copy: `core:import-from-euisis`, then compare `core:snapshot` files from
   both sides; any difference stops the cutover.
3. Switch: set EUISIS `CORE_MASTER_DATA_WRITES=cutover`, re-run the copy with
   `--fresh-tables`, compare again, open the Core platform for writes.
4. Contract (later, per module): service modules read from the Core API
   instead of the shared tables. Not started.
Rollback before step 3 is dropping the Core database. After step 3 it is
setting EUISIS back to `core` mode; changes made in the Core platform in
between must be replayed from its audit log.

## Security impact
Every read and write applies the organization scope. Card verification
returns validity, status, number and name only. Personal fields (national id,
phone, address, date of birth) are hidden from API responses. Writes need an
officer or admin role and a reason for card changes; each one is audited with
the actor, request id, IP and user agent.

## Operational impact
New runbook steps live in `core-platform/README.md`. CI runs the Core backend
tests and frontend build (`.github/workflows/core-platform.yml`). Backups of
`euisis_core` are needed from the moment step 3 is reached.
