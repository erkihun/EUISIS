# UI/UX refresh plan (November 2026)

A design pass over the admin interface with the `ui-ux-pro-max` skill
(nextlevelbuilder, v2.15.0, installed under `.claude/skills/`), run on
2026-11-09 against the local build on `:8000` in the Amharic locale. The pass
stays inside the pilot scope freeze: no new modules, no new component APIs,
no change to the Ethiopian-calendar widgets, and the existing tokens in
`resources/css/app.css` and `packages/euisis-ui/src/tokens.css` remain the
single source of colour, spacing and radius.

## 1. What the skill recommended

Queries run (`.claude/skills/ui-ux-pro-max/scripts/search.py`):

| Query | Result used |
| --- | --- |
| `government civil service HR administration portal --design-system` | Style **Accessible & Ethical** (government, legal compliance): contrast 4.5:1 text, 3:1 non-text, visible focus rings, reduced motion, 44 px touch targets, no ornament, no low-contrast grey-on-grey. Palette "high-contrast navy" (`#0F172A` / `#F8FAFC` / border `#E2E8F0`), which is what the app already ships. |
| `form validation error field label --domain ux` | Inline error per field with `aria-describedby` (done), **error summary or focus move after a failed submit** (missing). |
| `empty state no results guidance --domain ux` | Empty state = message **and** an action, never "0 results" alone. |
| `dark mode contrast tokens --domain ux` | 4.5:1 for text in both themes; 3:1 for control boundaries. |
| `keyboard focus visible navigation --domain ux` | Visible focus on every control including inside dialogs (done). |
| `typography hierarchy base 16 line height --domain ux` | Line-height 1.5–1.75 for body, a fixed modular scale (done via `--text-*` tokens). |
| `loading skeleton feedback --domain ux` | Stable skeletons with `aria-busy` (kit `Skeleton` exists). |
| `government admin portal enterprise --domain style` | Fluent 2 / Polaris-class "calm hierarchy, semantic tokens, standardized corners" — matches the kit. |
| `inertia blade forms validation --stack laravel` | `useForm` for every form (done). The React stack file returned no match for admin tables and forms. |

Recommendations **not** adopted, with the reason:

- **Font change to Atkinson Hyperlegible / Lexend.** Rejected. The app self-hosts Inter + Abyssinica SIL for Amharic (CSP `font-src 'self'`); neither suggestion has Ethiopic glyphs, and `docs/ui-typography.md` fixes the pairing.
- **Palette swap.** Rejected. The shipped navy + signal red already is the "high-contrast navy" palette. The orange seen in the local screenshots is the *Appearance* setting in the local `psemsdb` database (primary and accent both set to orange), not the code defaults.
- **Landing-page pattern ("Minimal Single Column").** Not applicable to a signed-in admin.

## 2. What the pass found

The interface already has one layout, one kit, token-only neutrals, visible
focus rings, labelled icon buttons, a status map, reduced-motion support and
guard tests (`tests/Unit/AdminUiConsistencyTest.php`,
`tests/Unit/AdminShellDesignTest.php`). The findings below are the gaps that
remain, measured on the sign-in, dashboard, employee list, employee form and
system-settings screens at 1440 px and 390 px, light and dark.

| # | Finding | Evidence | Skill rule |
| --- | --- | --- | --- |
| F1 | Input and outline-button borders fail the 3:1 non-text contrast in both themes. | `--app-border-strong` `#cbd2dc` on white = **1.5:1**; dark `#334155` on `#111827` = **1.7:1**. On the tinted form card the light ratio is lower still. | Accessibility › non-text contrast (3:1) |
| F2 | After a failed submit nothing moves focus; the person scrolls to find the red field. | Employee form: errors render inline with `role="alert"`, but focus stays on the Save button. | Forms › focusable error summary (High) |
| F3 | The assistant's floating button covers the sticky Save/Cancel bar on phones. | Employee form at 390 px: the button sits over the Cancel action. | Touch › no overlap of fixed controls |
| F4 | Dashboard masthead on phones: the date-range segmented control scrolls sideways with arrows, and the briefing strip breaks its three sentences into two-word columns. | Dashboard at 390 px. | Layout › no horizontal scroll, readable measure |
| F5 | Breadcrumbs scroll sideways on phones (arrows appear in the heading band). | Employee form at 390 px. | Navigation › predictable back |
| F6 | Long organization / unit names wrap to six lines per row in the employee list, so a row is 160 px tall and the table shows two employees per screen. | Employee list at 1440 px. | Tables › dense admin lists |
| F7 | Empty state is text only unless a page passes an icon; the title has the same weight as body text. | `packages/euisis-ui/src/feedback.tsx` `EmptyState`. | Feedback › empty states |
| F8 | When the configured logo URL does not resolve, the sign-in page shows the browser's broken-image glyph. | Local `storage/system-assets/...png` returns 403, visible top-left of the sign-in page. | Visual › official brand assets |
| F9 | Colour emphasis is spent on the local primary/accent setting: in this database both are orange, so selected tabs, range buttons, KPI tiles, bullets and primary buttons all read as one colour. | Dashboard screenshots. | Style › one accent, red is a signal |

Everything else checked passes: text contrast (muted text 6.0:1 light, 6.9:1 dark), heading order, one `h1` per page, `aria-current` on the sidebar, `aria-expanded` on toggles, 36 px shell controls that grow to 44 px on touch, dialogs and menus from Headless UI, reduced motion through the setting and the media query, no horizontal page overflow at 390 px on the five screens.

## 3. Changes proposed

All changes are token or kit level so every page benefits; no page API changes.
Each line names the file, so the diff can be reviewed against it.

### Apply now

| # | Change | Where | Fixes |
| --- | --- | --- | --- |
| C1 | **Control borders at 3:1.** `--app-border-strong` light `#cbd2dc` → `#7a8597` (3.7:1 on white, 3.3:1 on the tinted card); dark `#334155` → `#5b6b85` (3.3:1 on the surface, 3.0:1 on the muted surface). Dividers (`--app-border`) stay as they are: they separate, they are not boundaries of a control. | `packages/euisis-ui/src/tokens.css`, `resources/css/app.css` (both declare it) | F1 |
| C2 | **Focus the first invalid field after a failed submit.** One listener on Inertia's `error` event in the shared `LocalizedUiProvider`: after the page re-renders, focus the first `[aria-invalid="true"]` control, or the first `[role="alert"]` if no field is marked. No per-form code. | `resources/js/Components/ui/LocalizedUiProvider.tsx` | F2 |
| C3 | **Assistant button clears sticky bars.** The sticky form action bar sets `data-sticky-actions` on itself; the floating trigger reads it and sits above the bar's height (`bottom: calc(bar + 1rem)`) while the bar is on screen. | `resources/js/Components/ai/GlobalAIAssistant.tsx`, `resources/js/Components/employees/EmployeeFormLayout.tsx` (and the kit `FormActions` footer if present) | F3 |
| C4 | **Dashboard masthead on phones.** Below `sm`, the date-range presets become a `Select` (same options, same form field) and the briefing strip lists its sentences one per line. Desktop unchanged. | `resources/js/Components/dashboard/DateRangeFilter.tsx`, `DashboardBriefing.tsx` | F4 |
| C5 | **Breadcrumbs collapse on phones.** Below `sm`, show only the parent crumb as "‹ Parent"; the full trail from `sm` up. The `nav` keeps `aria-label` and the full list stays in the DOM for screen readers (`sr-only`). | `resources/js/Components/Breadcrumbs.tsx` | F5 |
| C6 | **Two-line clamp for long cell text.** Kit `Td` gains `clamp` (lines, default off); when set, the cell text is limited to that many lines with the full text in `title`. The employee list uses `clamp={2}` on institution, unit and position cells. The guard test's ceilings are unaffected. | `packages/euisis-ui/src/table.tsx`, `resources/js/Pages/Employees/Index.tsx` | F6 |
| C7 | **Empty state with a default icon and a clearer title.** A muted search glyph when no icon is passed, title `text-base font-semibold`, description kept at `text-sm`. | `packages/euisis-ui/src/feedback.tsx` | F7 |
| C8 | **Logo fallback.** `ApplicationLogo` and the sign-in logo images hide themselves on `error` and fall back to the built-in mark, so a missing asset never shows a broken image. | `resources/js/Components/ApplicationLogo.tsx`, `resources/js/Pages/Auth/Login.tsx` | F8 |

### Owner decision, no code

- **F9 Appearance settings.** The local database sets both primary and accent to orange. The code defaults (navy `#122170`, signal red `#d12908`) follow the skill's palette; resetting the two settings in *System Settings › Appearance* restores the intended hierarchy. Not changed by this pass.

### Later (outside this pass)

- Consolidate `KpiCard`, `StatCard` and `AppMetricCard` on one API (already listed in `docs/admin-ui-consistency.md` §5).
- Migrate the remaining `Modal` usages to `AppDialog`.
- A `tone-*` utility for the semantic hue pairs.

## 4. Applied (2026-11-09)

The owner approved the eight changes and they are in the working tree
(uncommitted). Notes from the implementation:

- C1: both token files changed; `docs/ui-design-system.md` updated.
- C2: the listener lives in `LocalizedUiProvider` (mounted by every layout). Forms that do not mark fields `aria-invalid` (the employee form passes errors to `FieldError` only) still get focus on the control that shares a wrapper with the first inline error; the error text is the last resort.
- C3: `EmployeeFormLayout` is the one sticky bar today; the other nine `sticky bottom-0` bars (organizations, grievance portal, court decisions, communication settings, assessment workspace, service-monitoring template editor) can opt in by adding `data-sticky-actions`.
- C6: the kit `Td` has `clamp`; the employee list is still a plain `<td>` table, so it uses `tableCellClampClassName()` directly. The clamp must not be combined with `block` (the first build did that and the clamp did not apply).
- Local-only noise seen while verifying, not caused by the refresh: the configured logo asset answers 403 (storage link), and an employee's `private-photo` route answers 500 (no photo files in the dev database).

## 6. Second pass, same day: table tools and the Swiss retheme

After the eight changes the owner asked for two more things.

**Pro tables on every admin list.** A kit layer, `TableTools`
(`packages/euisis-ui/src/tableTools.tsx`), wraps a table and adds: click-to-sort
on any header (ascending, descending, off; the page's rows, with a "sorted on
this page only" note; headers carrying `data-sort` under a `serverSort` prop ask
the server instead, and headers that already hold a control are left alone), a
Columns menu that hides columns through one scoped stylesheet, a compact or
comfortable density toggle that outranks the global setting for that table, a
kept header (the rows scroll inside the card), and per-table memory in
localStorage. `AppDataTable` and the kit `DataTable` wrap themselves; a codemod
wrapped the 277 tables in the other 215 list, detail and report pages
(`<TableTools id="<page-slug>">`). The toolbar appears only on tables with
three or more columns and at least one row. Strings: `common.table*` in both
languages.

**Minimalism & Swiss retheme** (the skill's `minimalism-and-swiss-style` row:
monochrome neutrals, one accent, no decoration, radius 0, shadow none, clear
type hierarchy). Applied through tokens only:

| Token / rule | Before | After |
| --- | --- | --- |
| Neutrals (light) | slate-tinted `#f4f6fb` … `#5b6472` | true greys `#f6f6f6`, `#ededed`, `#dcdcdc`, `#767676`, `#5c5c5c`, text `#141414` |
| Neutrals (dark) | navy-tinted `#0b1020` … `#94a3b8` | `#111112`, `#19191b`, `#222225`, `#2e2e32`, `#6e6e75`, `#a3a3aa`, text `#f4f4f5` |
| Radii | 6 / 8 / 10 px | 2 / 2 / 4 px; Card Radius setting steps 0–8 px, Button Style `soft` 6 px |
| Shadows | Tailwind defaults | hairline + short sharp shadow for `shadow-sm/lg/xl` |
| Cards | navy tint, tinted KPI tones | flat white, hairline border, 3px start-edge rule per tone (both themes) |
| Heading band | dot-grid texture | flat |
| Header | translucent + blur | solid surface |
| Titles | 1.375rem | 1.5rem, tracking −0.02em in English only |

Hard-coded styles replaced in the shell and kit: the mobile drawer
(`rounded-e-2xl shadow-2xl`, blurred slate backdrop), the header blur, the
employee form section header and icon tile (`bg-gray-50/70`, `bg-blue-50`,
`rounded-lg`), the sticky Save bar blur, the Cancel link radius, the disabled
"Add employee" button (`bg-gray-300`), the KPI neutral tone and dark hover
(`slate-*`), the sidebar flyout and logo tile radii, the kit neutral badge and
tooltip colours. Not changed: the sign-in page (a brand piece with its own
motion), the Appearance settings in the local database (sidebar colour, orange
primary and accent), and the roughly 100 `gray`/`slate` classes that remain in
page files under the guard test's ceilings (listed in
`docs/admin-ui-consistency.md` §5).

## 7. Third pass, same day: neumorphism end to end

The owner then asked for a neumorphic ("soft UI") theme. The skill's
`neumorphism` row: one monochromatic surface, 12–16px radii, two soft shadows
(light above-left, dark below-right), embossed controls that deboss on press,
and an explicit accessibility risk of **high**. The retheme therefore keeps
the WCAG floors the first pass set: text stays above 4.5:1 (13.8:1 body,
5.7:1 muted in light; 12.2:1 and 6.9:1 in dark) and every input and outline
button keeps a 1px border at 3:1 or better on top of its inset shadow, because
a shadow alone is not a boundary.

Applied through the same tokens as the Swiss pass:

| Token / rule | Swiss | Neumorphic |
| --- | --- | --- |
| Page and surfaces | `#f6f6f6` page, `#ffffff` cards | one soft grey `#e8ebf0` (dark `#2b2e35`) for page, cards, inputs and dialogs |
| Depth | hairline borders | `--neu-raised`, `--neu-raised-sm`, `--neu-raised-lg`, `--neu-inset` per theme; `shadow-sm/md/lg/xl/2xl` resolve to them |
| Cards | flat, bordered | extruded: same colour, no border, raised shadow; a card inside a card is inset; tone = 3px start-edge rule |
| Radii | 2 / 2 / 4 px | 10 / 16 / 20 px; Card Radius setting 8–28 px; Button Style `square` 4 px, `soft` 14 px |
| Buttons (kit) | flat | filled buttons raised and flat on press; `secondary`, `outline`, `destructiveOutline` raised at rest and inset while pressed; `ghost` inset on press; 150 ms shadow transition |
| Inputs (kit) | bordered | bordered and inset |
| Titles | tracking −0.02em | −0.01em |

**Shell.** The top bar is a raised slab (shadow below, no border); its
command-palette field and the language switch are wells; icon controls lift
on hover and sink while pressed. The sidebar derives its own light and shade
from the configured sidebar colour with `color-mix`, so a navy or white
sidebar both read as soft; the current page is pressed into the rail, the
brand tile is raised, the filter field is a well. All of it is CSS on
`[data-admin-header]` and `[data-sidebar]` (app.css, "Neumorphic shell"),
no component markup changed.

Files: `resources/css/app.css`, `packages/euisis-ui/src/{tokens.css,primitives.tsx,forms.tsx}`, this document and `docs/ui-design-system.md`.

## 8. Interface style presets, managed in System Settings

The owner asked for several looks managed from the admin side, and for the
catalogue's recommendation to be applied. Of the 51 active styles in the
skill's `styles.csv`, the low-accessibility-risk rows whose "best for" names
government, enterprise or dashboards are Accessible & Ethical, Inclusive
Design, Minimalism & Swiss Style, Data-Dense Dashboard and Soft UI Evolution;
plain Neumorphism is rated **high** risk. So:

| Preset (`appearance.ui_style`) | What it is | Default |
| --- | --- | --- |
| `swiss` | The Swiss pass (§6): true greys, hairline depth, one accent | **yes** |
| `classic` | The original tonal navy admin: tinted cards, dot-grid band, 6–10 px radii | |
| `soft` | Soft UI (§7), the accessible form of neumorphism: raised surfaces with bordered, inset controls | |
| `contrast` | High contrast: black on white (white on black in dark), 2 px rules instead of shadows, 3 px focus outline | |

How it works:

- The base tokens in `app.css` are the Swiss preset; each other preset is an
  `html[data-ui-style="…"]` block plus a `.dark` block that restates every
  neutral token (`tests/Unit/UiStylePresetsTest.php` fails if one is missing,
  or if the settings options drift from the stylesheet).
- A preset sets neutrals, card treatment, radii (`--radius-*-base`),
  elevation (`--elev-*`, which the `shadow-*` utilities resolve to) and title
  tracking. Brand colours, the light/dark theme, spacing, typography scale and
  every component stay the same. The Card Radius setting now scales the
  preset's own radius (½, ¾, 1, 1½); Button Style `soft` adds 6 px.
- The setting is public: `app.blade.php` writes `data-ui-style` on `<html>`
  for the first paint (sign-in included), `AuthenticatedLayout` keeps it in
  sync after a save. The Appearance preview in System Settings draws the
  chosen preset's neutrals.
- Files: `SystemSettingsRegistry` (field, public list), `PublicSettingsService`,
  `UpdateAppearanceSettingsRequest`, `sections.ts`, `settings.ts` (en/am option
  labels), `SettingsPreviews.tsx`, `AuthenticatedLayout.tsx`, `app.blade.php`,
  `app.css`, `tokens.css`.

**Per-user choice.** While Appearance › "Allow user theme switching" is on,
the header's theme button is an Appearance menu (`ThemeToggle.tsx`): light,
dark or follow the device, and the interface style — the organisation default
or any of the four. The personal style lives in this browser (`localStorage`
key `ui-style`, like the light/dark key), is applied before first paint by the
inline script in `app.blade.php`, and is resolved by `resolveUiStyle` in
`resources/js/lib/theme.ts` wherever the shell writes `data-ui-style`.
Turning the setting off hides the menu and every browser returns to the
organisation's style.

Not done: thumbnail cards in the admin picker; it is a select for now.

## 9. Icons on every action

Action buttons and row-menu items carry a glyph without each page adding one.
`UiProvider` takes `actionIcons` rules (label → glyph); the kit `Button`
borrows a glyph for a text label when no `icon` prop is given (`icon={null}`
opts out; `link` and `icon` variants never infer), and `ActionMenu` items do
the same. The rules live in `resources/js/Components/ui/actionIcons.tsx`:
eighteen inline Lucide outlines mapped to `common.*` labels in the current
language (Save/Update/Submit/Confirm → check, Cancel/Close → ×, Create → plus,
Edit → pencil, Delete/Remove → bin, Archive, Restore/Reset/Clear → rotate,
View/Preview/Details → eye, Search, Filter, Export/Download, Import/Upload,
Print, Back/Previous, Next/Continue, Sign out, Copy, Send). A label that begins
or ends with the verb as a whole word also matches, so "Save employee" and
"ሠራተኛ ያስቀምጡ" get the check. The glyphs are inline rather than imported from
`@/Components/Icons`, so the sign-in page does not load the content icon set.

Not covered: hand-rolled `<button className={buttonClassName(…)}>` elements
and `buttonClassName` links in pages keep whatever icon they already have;
converting them to the kit `Button` (as the employee form's action bar now is)
brings them in.

## 5. Verification (done 2026-11-09)

- `npm run typecheck` clean; `npm run build` succeeded (assets rebuilt three times during the work).
- `php artisan test` on `AdminUiConsistencyTest`, `AdminShellDesignTest`, `AdminLayoutShellTest`, `EmployeeIndexTableUiTest`, `EmployeeFormLayoutUiTest` and `PublicSiteRedesignTest`: 34 passed, 214 assertions.
- Browser (Playwright, Amharic locale) on sign-in, dashboard, employee list, employee form and system settings at 1440 px and 390 px, light and dark:
  - input borders now compute to the new token in both themes; no horizontal overflow at 390 px on any of the five screens;
  - the dashboard masthead on a phone shows the period `Select` and the briefing list stacks one line each;
  - breadcrumbs collapse to "‹ Parent" on a phone, full trail from `sm` up;
  - the assistant button sits above the form's sticky bar on desktop and phone (measured: no overlap);
  - a failed employee submit focuses the first-name field (first inline error) and scrolls it into view;
  - the employee list clamps institution, unit and position cells to two lines (tooltip carries the full text);
  - the sign-in page shows the built-in mark instead of a broken image when the configured logo returns 403.
- Before and after screenshots are kept in the side-session notes folder (`shots/before-*.png`, `shots/after-*.png`).
