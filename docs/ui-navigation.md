# UI Navigation

## Cafeteria Provider Portal

The provider portal navigation is isolated in `resources/js/Layouts/CafeteriaProviderPortalLayout.tsx`. It does not reuse the admin sidebar or top navigation.

Visible provider items:

- Provider Dashboard
- Scan ID
- Transactions
- Ledger
- Daily Menus
- Food Orders
- Reports
- Provider Profile

Admin-only modules such as organizations, employees, ID cards, roles, permissions, system settings, audit logs, and code rules are not shown in the provider portal.

## Admin shell

The signed-in layout (`resources/js/Layouts/AuthenticatedLayout.tsx`) is a fixed
sidebar, a 64px sticky header and a page heading band above the content.

- **Icons.** The shell draws every icon from `resources/js/Components/NavIcons.tsx`,
  which adapts the Solar icon set (linear weight, `@solar-icons/react`) under the
  names the sidebar and header already used. Add a shell icon there, never from
  the package directly, so the shell stays on one weight. Page content keeps
  `@/Components/Icons`.
- **Sidebar.** 280px wide (`w-[17.5rem]`), or a 64px icon rail when collapsed from
  the header toggle or its own footer button. Rows are 36px on the control radius;
  the current page carries the accent wash and a 2px bar. The brand block and the
  page heading band share the `app-dot-grid` texture (`resources/css/app.css`).
- **Header.** Menu button (below `lg`), sidebar toggle (`lg` and up), command
  palette trigger (Ctrl K / Cmd K), then language, theme, notifications and the
  account menu. Every control is 36px (`h-9 w-9 rounded-md`).
- **Motion.** Width and colour transitions run 150 to 200ms and are switched off by
  the appearance setting and by the reduced-motion system preference alike.

`tests/Unit/AdminShellDesignTest.php` pins these conventions.
