# Admin UI consistency

The standards every admin page follows, the audit that measured the interface
against them on 2026-10-12, and what changed. The token values themselves are
in `docs/ui-design-system.md`; the kit's API table is in
`docs/ui-components.md`; the shell is described in `docs/ui-navigation.md`.

**Development rule.** A new admin page is built from the shared layout and the
kit: `AuthenticatedLayout` with a `header={<PageHeader …/>}`, kit cards,
tables, filters, forms, buttons, badges and dialogs, and the `--app-*` colour
tokens. Nobody copies a block of markup from another page; if a pattern is
missing from the kit, it is added to the kit once. `tests/Unit/AdminUiConsistencyTest.php`
and `tests/Unit/AdminShellDesignTest.php` fail when a page drifts.

## 1. Standards

| Area | Standard |
| --- | --- |
| Admin shell | One layout, `resources/js/Layouts/AuthenticatedLayout.tsx`: fixed sidebar (280px, 64px rail when collapsed), sticky 64px header, breadcrumb-and-title band, content column `max-w-[1600px]` with `px-4 sm:px-6 lg:px-8 py-6`. No footer. Below `lg` the sidebar is a Headless UI drawer. |
| Sidebar | `AppSidebar`: sections → groups → pages, permission- and scope-aware (a group appears only with a visible child), 36px rows on the control radius, 18px Solar icons, accent wash and 2px bar on the current page, flyouts in the rail, `/` focuses the filter, state remembered per browser. Transport and court cases are hidden by `config/features.php`. |
| Topbar | `AppHeader`: menu button (below `lg`), sidebar toggle (`lg` and up), command palette (`Ctrl K` / `⌘ K`), language, theme, notifications, account menu. Every control is 36px `rounded-md`. |
| User menu | Avatar, name, first role; identity band with e-mail and role badges; Profile settings; Sign out (the only sign-out control in the admin). Theme and language sit beside it in the header, not inside it. |
| Breadcrumbs | `Breadcrumbs` renders from the navigation tree and the URL; pages never build their own. |
| Page width | The layout owns width. Forms and detail pages may narrow their own content with `mx-auto max-w-2xl` … `max-w-5xl`; nothing widens beyond the column. |
| Page header | `PageHeader` passed through the layout's `header` prop: title (`text-page-title`, the only `h1`), description, optional back link, actions on the right (primary action last). Rendering it in the page body is blocked by a test. |
| Primary action | In the page header. A card header carries the primary action only on pages without a page-level action. |
| Vertical rhythm | Page blocks `space-y-6`; inside a card `space-y-4`; form fields `--space-field`; sections `--space-section`. |
| Cards | `Card` family on `--app-surface` with `--app-border`, `rounded-card`, `--card-padding`; tonal tints only through `card-tone-*`. KPI cards: `KpiCard` (dashboard) with tonal tints, `StatCard` and `AppMetricCard` elsewhere; all three share the card tokens. |
| Tables | `TableCard` + table kit or `AppDataTable`; header on `--app-surface-muted`, `text-table`, sort indicator from the kit; rows hover with `--app-surface-muted`; density from the Table Density setting; server-side pagination through `ListPagination`. Row actions: inline `actionLinkClassName` links for one or two actions, `AppActionMenu` beyond that. |
| Filters | `FilterPanel` + `FilterField` (controlled) or `AppFilterBar` (uncontrolled, submits on Filter): search first, primary filters, Filter then Reset. Advanced filters stay in the panel; the panel collapses on mobile. Filters live in the query string. |
| Search | `SearchInput` (36px, leading icon, clear control, label). Never a client-side load of large data. |
| Forms | `FormSection` (title, description, two-column grid on desktop, one column on mobile) → `FormField` (label, required mark, description, error below the control) → `Input` / `Select` / `Textarea` / `SearchableSelect` / `FileUpload`. Required fields show the `required` mark of `FormLabel` only. Actions at the bottom: Cancel (`outline`), then the primary action; destructive actions `destructive`. |
| Buttons | `Button` / `buttonClassName` variants `primary`, `secondary`, `outline`, `ghost`, `destructive`, `destructiveOutline`, `link`; sizes `xs`, `sm`, default, `lg`, `icon`. Icon-only buttons carry `aria-label` and `title`. Red is destructive only. |
| Badges | `StatusBadge status` (translated label, tone from the status map) and `badgeClassName(tone)`. The same status has the same colour on every page. Raw enum codes never reach the screen. |
| Dialogs | `AppDialog` (sizes `sm` … `2xl`, title, description, footer) for editing and detail; `useConfirm()` for every destructive or workflow confirmation (variants `default`, `warning`, `danger`, `success`, optional reason or typed confirmation). `window.confirm` remains only for the three synchronous "leave this page?" guards. |
| Toasts and alerts | `AppToaster` through `useToast()` for outcomes of actions; `Alert tone` for inline notices. No `alert()`. |
| Loading, empty, error | `AppSkeleton` family while a page or table loads, `Button loading` for in-flight actions, `EmptyState` with a domain message and an action, `ErrorState` with retry for failed loads. |
| Responsive | No horizontal page overflow at 390, 768, 1024 and 1440 px. Tables scroll inside their card; header actions wrap. |
| Accessibility | Visible focus rings (`focus-visible:ring-2`), labelled icon buttons, `aria-current` on the active page, `aria-expanded` on toggles, Headless UI dialogs and menus (focus trap, Escape, restore), `role="alert"` on field errors. |
| Dark mode | Only tokens for neutrals; semantic hues only in the kit's pairs. Tested with the invisible-text check of the crawl. |
| Icons | Shell: Solar linear through `NavIcons`. Content: `Icons` (Lucide-style). The same concept uses the same icon (`Plus` add, `PencilIcon` edit, `TrashIcon` delete, `EyeIcon` view, `SearchIcon`, `FilterIcon`, `DownloadIcon`, `Upload`, `PrinterIcon`, `SettingsIcon`, `Users`, `Building2`, `ShieldCheck`). |
| Motion | Sidebar width 200ms, colours 150ms, dialogs and menus 100–200ms through Headless UI; off under the animations setting and the reduced-motion preference. |
| Settings | `SystemSettings/Index`: grouped section navigation on the left, one section panel on the right, per-section Save with dirty state and a leave guard. |
| Detail pages | Page header with back link and actions, identity card first, status badge, description lists for key fields, sections or tabs for related records, history last. |
| Organization context | Pages scoped to an organization show its name in the page header description or identity card; the management scope is enforced server-side and the sidebar lists only permitted pages. |
| Exports | `Export` as an outline button in the page header or filter bar; queued exports show their status in place. |
| Dates and numbers | `LocalizedDateDisplay` and the calendar helpers (Ethiopian in Amharic, Gregorian in English); `Intl.NumberFormat` through the shared formatters. |

## 2. What the audit found

Scope: 293 React pages render inside `AuthenticatedLayout` (the admin), plus the
shell components and the kit. Public (13), employee portal (28), provider (19)
and guest (3) pages keep their own layouts and were only checked where they use
a shared primitive. Transport pages exist but are feature-flagged off.

Measured before the changes (admin pages):

| Inconsistency | Before | After |
| --- | --- | --- |
| Page title rendered in the body instead of the heading band | 17 pages | 0 (test) |
| Breeze-era form wrappers (`TextInput`, `InputLabel`, `InputError`, `PrimaryButton`, `SecondaryButton`, `DangerButton`) | 220 usages in 26 files | 0; the six files deleted |
| Destructive confirmations through `window.confirm` | 12 sites in 9 pages | 0; 3 synchronous leave-page guards remain |
| Pagination components | `ListPagination`, `Pagination`, `PaginatorLinks` | `PaginatorLinks` removed; `ListPagination` for every Laravel paginator |
| Neutral grey classes (`gray`/`slate`) instead of tokens | 335 occurrences in 76 files | 102 `dark:` leftovers, 47 light leftovers, 67 of them in four files that hold uncommitted work (see §5) |
| Large radii on admin surfaces (`rounded-xl`+) | 7 pages | 1 (uncommitted file) |
| Admin header, sidebar and toolbar controls | 40px / `rounded-lg`, two icon sets | 36px / `rounded-md`, one shell icon set |
| Unused Breeze components (`Checkbox`, `NavLink`, `ResponsiveNavLink`) | present | deleted |

Found consistent already and kept: one layout for all admin pages; one
`PageHeader`; the kit's table, filter, form, badge, dialog, toast, empty and
error components (used by 67–101 admin pages each); server-side pagination
everywhere; status labels through the status map and translations; the
appearance settings wired to the tokens.

## 3. What changed

1. **Shell** (`feat(ui): professional admin shell`, earlier today): 64px header
   with sidebar toggle, 36px controls, Solar shell icons, dot-matrix heading band,
   reduced-motion support, mobile drawer corners.
2. **Page headers** moved into the heading band on 17 pages (NFC management,
   service feedback QR pages, public site management, position services,
   imports, backup and recovery, court cases).
3. **Forms** migrated from the Breeze wrappers to `FormLabel`, `FieldError`,
   `Input` and `Button` on 26 files; the wrappers and three unused Breeze
   components were deleted.
4. **Confirmations**: delete, archive, deactivate, finalize and transition
   actions on 9 pages now open the shared confirmation dialog with a title,
   the record name where available and the "cannot be undone" description.
5. **Colours**: 105 files had their neutral greys replaced by the `--app-*`
   tokens and the matching `dark:` classes removed; large radii replaced by
   `rounded-panel`.
6. **Pagination**: the submission inbox uses `ListPagination`; `PaginatorLinks`
   is gone.
7. **Guards**: `tests/Unit/AdminUiConsistencyTest.php` now also blocks the
   Breeze wrappers, native confirms, large radii and body-rendered page headers,
   and its grey-colour ceilings dropped from 185/32/69 to 102/20/27.
8. **Scripts**: `npm run typecheck`.

## 4. Deprecated patterns

Do not use these in admin code; the guard test rejects most of them.

| Pattern | Use instead |
| --- | --- |
| `TextInput`, `InputLabel`, `InputError`, `PrimaryButton`, `SecondaryButton`, `DangerButton` (deleted) | `Input`, `FormLabel`, `FieldError`, `Button variant` |
| `PaginatorLinks` (deleted), hand-rolled `links.map(...)` pagination | `ListPagination meta={paginator}` |
| `window.confirm` / `confirm()` for an action | `const { confirm } = useConfirm(); if (!(await confirm({ title, description, variant: 'danger' })).confirmed) return;` |
| `alert()`, ad-hoc toasts | `useToast()` |
| `Modal` (`@/Components/Modal`, Breeze) | `AppDialog`; existing usages (26) migrate as their pages are touched |
| `text-gray-*`, `text-slate-*`, `bg-white`, `bg-gray-50`, `border-gray-*`, `divide-gray-*` and their `dark:` pairs | `--app-*` tokens |
| `rounded-xl`, `rounded-2xl`, `rounded-3xl` on cards or panels | `rounded-card`, `rounded-panel` |
| `<table className="…">`, `<th className="…">` | the table kit |
| `<button className="bg-…">` | `Button` / `buttonClassName` |
| A `<PageHeader>` or `<h1>` inside the page body | the layout's `header` prop |
| `<select onChange={() => router.get(...)}>` as a filter | `FilterPanel` / `AppFilterBar` |
| A raw status code in text | `StatusBadge`, `status.*` translations |
| Inline `<svg>` icons | `Icons` (content) or `NavIcons` (shell) |

## 5. Remaining

- **Files with uncommitted work** (not touched by the audit): `Pages/IdCards/Show.tsx`,
  `Pages/IdCards/Preview.tsx`, `Components/IdCards/CardPrintExportModal.tsx`,
  `Pages/Grievances/Committees/Show.tsx` and the discipline module. They hold
  67 of the 102 remaining `dark:slate` classes, all 20 grey borders, all 27 grey
  texts and the one `rounded-xl`. Run the token codemod rules on them when that
  work lands, then lower the ceilings.
- **`dark:` leftovers in 20 other files** (35 classes) pair a semantic light colour
  (`bg-blue-50/40 … dark:bg-slate-900`) or sit in template literals the codemod
  does not parse (organogram, hierarchy tree, code-rule fields, CSV import,
  strategic goals). Each is a one-line change.
- **`Modal`** (26 usages, hierarchy and organization-design dialogs) still
  renders its own panel; `AppDialog` is the target.
- **Three KPI card components** (`KpiCard`, `StatCard`, `AppMetricCard`) share
  the tokens but not one API; consolidate on `KpiCard`.
- **Inline SVGs** remain in 9 admin pages (card requests, employee import and
  show, ID card show, print batches, profile, ID card templates, transfer
  announcements): mostly spinners and chevrons that `Icons` already provides.
- **Semantic hue classes** (`text-red-700`, `bg-amber-50`, …) are used directly
  in about 1,650 places in the kit's pairs; a `tone-*` utility would let them be
  written once.

## 6. Checks

- `npm run typecheck`, `npm run build`, `php artisan test tests/Unit` and the
  consistency guards pass; `vendor/bin/pint --test`, `composer audit --locked`
  and `npm audit` results are in the handoff of 2026-10-12.
- Browser crawl: see §7.

## 7. Browser crawl

<!-- CRAWL -->
