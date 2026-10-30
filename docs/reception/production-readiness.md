# Reception production readiness

## Deployment

Drain workers, back up the database, deploy code, run `php artisan migrate`, `composer dump-autoload`, `php artisan optimize:clear`, `php artisan ziggy:generate`, and build frontend assets. Assign explicit Reception roles and organization scopes before opening desks.

## Rollback

Disable navigation/permissions, drain active reception work, export required records, then roll back the two Reception migrations. Rollback removes Reception operational data, so use it only with a verified backup and approved retention decision.

## Required operational checks

Verify a realistic staging migration, concurrent queue issuance against the production database engine, localized/Amharic rendering, scoped isolation, backups and restore, printer layout, appointment notifications, retention policy, monitoring and peak-hour load. Production readiness must remain conditional until these environment checks pass.
