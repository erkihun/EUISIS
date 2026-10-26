# Core rc1: full rehearsal plan for the new candidate

**D-12 decided 2026-10-26: the settings work is included in the release
candidate.** The settings work is a202bb31 on `feat/golden-path-stages-apply`,
rebased as 064c5e4b, now 69e0fa03. The candidate certified so far
(15c095aa) is superseded. Every rehearsal and gate is repeated for the new
candidate. Nothing earlier carries over except the procedures.

## The new candidate

| | Commit | Content |
|---|---|---|
| Base | 15c095aa | everything rehearsed on 2026-10-26 (evidence §1–§11) |
| + settings work | 69e0fa03 | the Core settings are the EUISIS settings: tabs, encrypted secrets, Settings › Email applied to the mailer, public-disk images, test e-mail, the registry copied by the sync script. 16 files, about 2,100 lines, no migrations |
| + review fix | 6bb346ee | favicon/logo refuse SVG (it would run script from `/storage`, outside the CSP); `deploy.sh`/`rollback.sh` link the public disk (`storage:link`), without which uploaded logos 404 |
| + review fix | 91c3d851 | `core:config-audit` judges the mailer the settings put in effect |
| **release_code_commit (proposed)** | **91c3d851** | 238 tests: SQLite 238/238, PostgreSQL 18.6 238/238, MariaDB 10.4 236 passed + 2 skipped; Pint clean; Composer audit and `npm audit` clean; typecheck and build pass; no sync drift |
| release_docs_commit | the docs commit(s) on top | no backend, frontend or deploy file |

## What the settings work changes, and so what the rehearsal must prove

| Change | Risk | Proved by |
|---|---|---|
| Settings pages replace the Core settings controller; new routes, including `POST /system-settings/test-email` (permission `system-settings.update`, 5 a minute) | authorization on every tab; the test e-mail used as a mail relay | settings smoke as a viewer (refused) and an administrator; pen-test scope |
| Secrets (SMTP password) stored encrypted with **APP_KEY**, never listed, `[set]` in the audit log | a restore without the same APP_KEY loses them; APP_KEY rotation must re-enter them | restore drill: the mail password decrypts on the restored copy; key runbook note |
| Settings › Email overrides the `.env` mailer at send time | mail goes to an unexpected mailer; the audit misreads it (fixed in 91c3d851) | config audit on staging with the mailer set on the page; test e-mail to the team mailbox |
| Images on the **public** disk under `/storage` | SVG script (fixed); a missing link (fixed); public disk inside backups | upload PNG, fetch `/storage/...` over HTTPS; SVG refused; the backup covers `storage/app/public` |
| No migrations; new rows in `core_settings` | rollback: the older release reads a settings table holding keys it does not know | rollback rehearsal to 15c095aa with settings saved by rc1, then the Golden Path on the older release |
| Registry copied from EUISIS by the sync script; CI drift check | the copy drifts from EUISIS | CI job "The screens are an unchanged copy of EUISIS" |

## Stage A: code gates

| # | Step | Status |
|---|---|---|
| A1 | Suites on SQLite, PostgreSQL, MariaDB; Pint; audits; typecheck; build; drift | **done locally** (above) |
| A2 | PR from the release branch to `main`; every CI job green (core-backend, core-backend-postgres, core-frontend) | WAITING: needs the push, which the release-management owner approves |
| A3 | Code review of 69e0fa03 by a second developer (the two fixes came from a first review) | WAITING FOR EXTERNAL EVIDENCE |

## Stage B: workstation pre-flight (finds defects fast; not certification)

This is the same rig as on 2026-10-26 (`docs/runbook/core-staging-rehearsal.md`).
It took about 2 hours end to end. Every defect found here last time (TRUSTED_PROXIES,
scheduler gate, import cap, notifications queue, CSP inline script) would
otherwise have surfaced on real staging.

1. Build the rc1 artifact from 91c3d851 (`build-artifact.sh`).
2. Deploy over 15c095aa on the workstation staging. The config audit must
   pass, and the smoke test must see `/health/monitor` report 91c3d851.
3. Run the Golden Path smoke over HTTPS.
4. **Settings smoke:**
   - a viewer is refused every tab;
   - an administrator saves each tab;
   - uploads a PNG logo and fetches it from `/storage`;
   - an SVG is refused;
   - the mail password is never shown back;
   - the test e-mail works with the `log` mailer.
5. CSP pass over the 15 screens plus every settings tab.
6. Back up, then run a restore drill. On the restored copy, the encrypted
   mail password decrypts and the logo file exists.
7. **Roll back to 15c095aa** with the rc1 settings rows present. Then run
   the Golden Path and open the settings page on the older release.
8. Fix forward to rc1.
9. Run 20 VUs of load for comparison with the 15c095aa baseline (42 req/s,
   p95 1.13 s). A clear regression is investigated.

**Stage B result (2026-10-26): passed on 91c3d851.** All nine steps passed; one
tooling defect was found and fixed (the backup missed the public disk). Details in
[core-go-live-evidence.md §15c](core-go-live-evidence.md).

**rc2 (2026-10-26): the card template designer was taken into the candidate**
(D-12 territory, decided by the project owner). Stages A and B were run again on
it and found five defects, fixed in db705422 and 2016bba4; the candidate is now
**2016bba4** ([core-go-live-evidence.md §15d](core-go-live-evidence.md)). The
designer adds a step to B4 (`deploy/staging/card-designer-smoke.php`) and to the
restore check (the template artwork).

**rc3 (2026-10-26): the printed card drawn from the designed template** was taken
in as well (83daed6b, cherry-picked as 8d742364). Stages A and B were run again
and found two gaps, fixed in 6bfacc4b; the candidate is now **6bfacc4b**
([core-go-live-evidence.md §15e](core-go-live-evidence.md)). B4 gains a check of
the printed-card routes (preview page, SVG faces, PNG export, print sheet); the
configuration review warns without Imagick.

## Stage C: real staging certification (owners TBD; the certifying run)

On Linux, Nginx, PHP-FPM 8.4, PostgreSQL 18, Redis available, with the real
certificate and the real secret store (D-09), from the **tagged** artifact:

1. `core:config-audit`, including MAIL_MAILER with the real mail decision (D-04).
2. pgBackRest with WAL archiving and the off-site repository. Then a restore
   drill from that repository onto an isolated host.
3. Deploy the previous build, then rc1. Run the Golden Path and the settings
   smoke. Roll back, then fix forward.
4. The CSP pass, then the enforcement decision (D-11).
5. Load test against the D-03 targets.
6. Monitoring connected and each critical alert fired (D-10).
7. The migration rehearsal on the sanitized production copy (`reconcile.php`).

## Stage D: people gates on the rc1 build

- **Business UAT:** 32 scenarios plus the 3 settings scenarios added to the
  package (SET-01..SET-03), then the signed record.
- **Penetration test:** scope now includes the settings pages, image upload,
  the test-e-mail endpoint and the encrypted settings secrets.
- **Amharic review:** the settings tabs come from EUISIS's own wording. Review
  them with the rest.

## Exit

A stage closes only with its evidence attached
([core-go-live-checklist.md](core-go-live-checklist.md)).

- **Tag:** after A2, `core-v1.0.0-rc1` on 91c3d851, or on the latest code
  commit after any fix found in B–D, which restarts the affected steps.
- **Go-live:** only by the GO rule in the go-live brief.
