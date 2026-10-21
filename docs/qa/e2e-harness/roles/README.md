# Role-by-role walkthrough harness (2026-10-21)

Scripts behind `docs/qa/e2e-role-walkthrough-2026-10-21.md`. They drive the installed Chrome over the DevTools protocol with Node's built-in `WebSocket`, so nothing needs installing. Evidence, not a maintained suite.

## Run

1. `npx vite build`, so `public/build` matches the checkout.
2. `php artisan route:list --json > ../routes.json` (the scripts read `routes.json` and `qa-users.json` from the parent directory).
3. `QA_OUT=../qa-users.json php artisan tinker setup-qa-users.php` creates `qa-role-<slug>@example.test` for every role, scoped citywide for global roles and to the Public Service Bureau subtree otherwise. It also prints drift between `DefaultRoleMatrix` and the database.
4. `MSYS_NO_PATHCONV=1 node proxy.js 8123 8131 6` starts six `php -S` nodes behind a round-robin proxy. The proxy serves `public/` files itself, because a single built-in server stalls on asset chunks. It also sets `MFA_ENFORCE=true`, the shipped default.
5. `node warm.js`, then `node roles-walk.js 4` (four roles at a time, about an hour). Pass a regex as the second argument to run some roles only.
6. `php artisan tinker qa-employee.php`, then `node journeys.js` for the ID card and employee portal journeys. `node extras.js` checks the Communication module, which is reached from the header, not the sidebar.
7. `node aggregate.js` writes `results/aggregate.json`. `php artisan tinker disable-qa-users.php` disables the QA accounts afterwards; they cannot be deleted because audit rows reference them.

## What `roles-walk.js` does per role

Signs in, enrolling or answering TOTP when MFA is required. Expands the sidebar and opens every link in a real page load, recording HTTP status, Inertia component, console errors, JavaScript exceptions, failed requests and time. It then reads the sidebar's own permission gates from `AppSidebar.tsx` and requests every gated page the role holds no permission for, expecting a refusal. Finally it opens the profile and signs out through the account menu.
