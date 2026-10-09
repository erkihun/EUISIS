# Field Work GPS production readiness

## Runtime and test database

Production uses PostgreSQL. The committed default PHPUnit profile still targets in-memory SQLite, which cannot run on this host because PDO SQLite is not installed. Use `phpunit.pgsql.xml.dist` only with environment-provided PostgreSQL credentials and a dedicated `DB_TEST_DATABASE` ending in `_test`; the test base rejects any other PostgreSQL database name. Never point tests at a production or shared operational database.

## Required operational configuration

After migrations and permission seeding, a restricted administrator must set the **Field Work GPS** System Settings policy. Every policy starts in a non-enforcing state: check-in/check-out completion requirements are off, accuracy and geofence actions are `not_configured`, and retention/offline/team values are declarations only. This is intentional: the application does not invent an employment or privacy policy.

Each submitted observation stores its effective policy snapshot, geofence result, distance, validation flags, and review state. `block` persists the observation and returns a failed action response; `require_review` persists it with `pending_supervisor_review`. Precise coordinates remain protected by the Field Work location permissions and are not written into audit log payloads.

## Constraints before go-live

- Browser GPS is explicit, point-in-time capture only; it is not continuous tracking and does not queue offline observations.
- Location-retention configuration is recorded but no automatic deletion job exists in this release. Legal, records, and privacy owners must approve and implement that lifecycle before enabling a retention promise.
- The completion gate currently evaluates the primary requester. Team members capture their own events; proxy/team-completion enforcement requires a separately approved workflow.
- Run the full feature suite on the isolated PostgreSQL test database, migration dry runs, role/permission smoke tests, and an HTTPS browser/device GPS test before declaring the module ready.
