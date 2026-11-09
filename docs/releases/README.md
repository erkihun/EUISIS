# Release notes

One note per production release ([../governance/release-management.md](../governance/release-management.md)).
File name: `YYYY-MM-DD-production-<production commit>.md`.

| Release | Built from `main` | Note |
| --- | --- | --- |
| 2026-10-24, production `083bfdec` | `3baaa4ef` | [2026-10-24-production-083bfdec.md](2026-10-24-production-083bfdec.md) |

Earlier production builds (`116792f7` from `main` `d10faef`, and before) have no
release note; their contents are in the Git history.

## Template

```markdown
# Release <date>, production <commit>

Built from main <commit>; previous production <commit>. Release type: PATCH | MINOR | MAJOR | EMERGENCY.

## Features
## Bug fixes
## Database migrations
(file, additive or not, expected duration on the largest tables)
## API changes
(endpoints, fields, authorization behaviour; breaking or not; consumers told)
## Event changes
## Security changes
## Dependency changes
(composer audit / npm audit result)
## Known issues
## Deployment steps
## Rollback notes
## Gates confirmed
(CI run, migration review, API and event compatibility, rollback prepared — who and when)
```
