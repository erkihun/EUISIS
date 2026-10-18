# UI design system

The tokens and component variants the admin interface is built from, as they
exist in the code today. Values come from `packages/euisis-ui/src/tokens.css`,
`resources/css/app.css` (including its Tailwind `@theme` blocks) and the kit in
`packages/euisis-ui/src`. Nothing here is aspirational: if a value is not in
one of those files, it is not in this document. Usage rules and the audit are in
`docs/admin-ui-consistency.md`; the kit's API table is in `docs/ui-components.md`.

Stack: Laravel 12 + Inertia, React 18, TypeScript, Tailwind CSS 3 with
`@tailwindcss/forms`, Headless UI for menus, popovers, dialogs and tabs, `cmdk`
for the command palette, and the project's own kit (`@euisis/ui`, re-exported
from `@/Components/ui`). There is no shadcn/ui, Radix or lucide-react package;
icons are hand-drawn Lucide-style paths in `@/Components/Icons` and, for the
shell only, the Solar set through `@/Components/NavIcons`.

## 1. Colour

Every colour an admin page uses is a CSS variable, read through Tailwind's
arbitrary-value syntax (`bg-[color:var(--app-surface)]`) or a named utility
(`bg-primary`, `text-primary-700`). The variables switch with the `.dark` class
on `<html>`, so a page never writes a `dark:` pair for a neutral colour.

### Surfaces and text (`--app-*`)

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `--app-background` | `#f6f7f9` | `#0b1020` | Page background |
| `--app-surface` | `#ffffff` | `#111827` | Cards, panels, inputs, dialogs |
| `--app-surface-muted` | `#f1f3f6` | `#171f31` | Table heads, hover rows, segmented controls, disabled fills |
| `--app-border` | `#e2e5ea` | `#1f2937` | Dividers and card borders |
| `--app-border-strong` | `#cbd2dc` | `#334155` | Input and outline-button borders |
| `--app-foreground` | `#0f172a` | `#f8fafc` | Body text, titles |
| `--app-muted-foreground` | `#5b6472` | `#94a3b8` | Helper text, labels, icons at rest |

### Brand (`--color-*`)

| Token | Value | Notes |
| --- | --- | --- |
| `--color-primary` | `#122170` (dark theme `#5d72bf`) | Navy. Also the Tailwind `primary` colour with a 50–950 scale (`#eef1fa` … `#070d2c`) |
| `--color-primary-hover` | `#1d3084` (dark `#8f9fd8`) | |
| `--color-primary-light` | `#dde3f5` (dark `#1d3084`) | Soft fills |
| `--color-on-primary` | derived | Text on a primary fill; buttons use it instead of `text-white` |
| `--color-accent` | `#d12908` (dark `#f76b3c`) | Orange, used sparingly: primary calls to action, the selected sidebar page |
| `--color-success` / `--color-warning` / `--color-danger` | `#15803d` / `#b45309` / `#b91c1c` | Semantic text and icons |

Appearance settings (System Settings → Appearance) rewrite `--color-primary-strong`,
`--color-primary-soft`, `--color-secondary`, `--color-accent-strong`,
`--color-accent-soft` and the sidebar variables on `<html>` at runtime
(`resources/js/Layouts/AuthenticatedLayout.tsx`), so a configured brand colour
reaches every component that reads the tokens.

### Semantic tones

Tones carry one meaning everywhere: **neutral** (resting, historical),
**info** (in progress, awaiting someone), **success** (done, active, allowed),
**warning** (needs attention, paused, late), **danger** (refused, failed,
revoked). They exist in three forms that agree with each other:

| Form | Where | Values |
| --- | --- | --- |
| Badge tones | `badgeClassName(tone)`, `StatusBadge` | `neutral`, `info` (primary scale), `success` (emerald), `warning` (amber), `danger` (red) |
| Card and alert tints | `card-tone-*`, `Alert tone` | `--tone-info-bg #e6ecfb` / `-border #c9d4f2`, `--tone-success-bg #dff3ea` / `#b7e2cd`, `--tone-warning-bg #fdf0d9` / `#f2d59b`, `--tone-danger-bg #fbe4e1` / `#f1c0ba` (light theme only; dark surfaces stay flat) |
| Status map | `resources/js/lib/statusStyles.ts` | each status code → label + tone, below |

Status codes and their tone (the same badge colour on every page):

- **neutral**: inactive, retired, replaced, expired, archived, closed, vacant, draft, cancelled, unknown, weekend, public_holiday, leave, not_tracked, not_employed, not_assigned, future, carried_forward, cannot_validate
- **success**: active, issued, published, allowed, approved, verified, completed, resolved, occupied, provisioned, success, within_expected_area
- **info**: pending, submitted, under_review, in_progress, processing, transferred, printed, queued, open, scheduled, resubmitted, required, pending_supervisor_approval, in_field
- **warning**: suspended, paused, on_hold, exhausted, overdue, escalated, damaged, warning, expiring_soon, returned_for_correction, returned, check_in_missing, supervisor_not_resolved, outside_expected_area, low_accuracy
- **danger**: revoked, lost, denied, rejected, dissolved, failed, blocked, missing, error

Labels come from the `status.*` translation keys; a code that has no label
falls back to the English label of the map, never to the raw code.

Semantic hue classes (`text-red-700 dark:text-red-400`, `bg-amber-50
dark:bg-amber-950/40`, `text-emerald-700 dark:text-emerald-300`) are allowed
only to express these tones, in the pairs the kit itself uses. Neutral greys
(`gray`, `slate`, `zinc`, `neutral`) are never written in admin code; the
`--app-*` tokens replace them.

## 2. Spacing

The Tailwind 4px scale, with three named tokens for the places that repeat:

| Token | Value | Use |
| --- | --- | --- |
| `--space-field` | `1rem` | Between form fields |
| `--space-section` | `1.5rem` | Between page sections, cards and form sections |
| `--card-padding` | `1.25rem` | Card body padding |

Page gutters are `px-4 sm:px-6 lg:px-8`; the content column is `max-w-[1600px]
mx-auto` and the main area has `py-6`. Page blocks stack with `space-y-6`;
inside a card, `space-y-4`. Arbitrary pixel values (`mt-[13px]`) are not used
for layout; the few remaining ones size icons and printed card faces.

## 3. Radius and elevation

| Token | Value | Utility | Use |
| --- | --- | --- | --- |
| `--radius-control` | `6px` | `rounded-control`, `rounded-md` | Buttons, inputs, menu items, toolbar controls |
| `--radius-card` | `8px` | `rounded-card` | Cards, table cards |
| `--radius-panel` | `10px` | `rounded-panel` | Dialogs, popovers, large panels |

`rounded-full` is for badges, avatars and dots only. `rounded-xl` and larger are
not used on admin surfaces.

Elevation is borders first. Cards are `border border-[color:var(--app-border)]`
on `--app-surface` and `min-w-0`, so a card inside a grid or flex row shrinks to
its column instead of widening the page; the light theme tints them
(`resources/css/app.css`, "Tonal cards"). Shadows: `shadow-sm` on raised toolbars, `shadow-lg` on menus and
popovers, `shadow-xl` on dialogs; nothing larger in the admin.

## 4. Sizes

| Token | Value | Use |
| --- | --- | --- |
| `--control-h-sm` / `-md` / `-lg` | `30px` / `36px` / `40px` | Button and input heights (`size="sm"`, default, `size="lg"`) |
| `--icon-sm` / `-md` / `-lg` | `16px` / `18px` / `20px` | Icons in text, in controls, in the header |

Toolbar and table-row icon buttons are 36px (`h-9 w-9 rounded-md`). The admin
header is 64px (`h-16`); the sidebar is 280px, or a 64px rail when collapsed.

## 5. Typography

One family per language through `--font-ui` (Inter for English, Noto Sans
Ethiopic for Amharic, switched by `<html lang>`), weights 400–700 only
(`docs/ui-typography.md`). The steps, as Tailwind utilities:

| Utility | Size / weight | Use |
| --- | --- | --- |
| `text-page-title` | `1.375rem` / 700 | The one `<h1>` of a page, in the heading band |
| `text-section-title` | `1.125rem` / 600 | Section and card titles |
| `text-base` | `1rem` | Long-form text |
| `text-body`, `text-sm` | `0.875rem` | Body, table cells, buttons |
| `text-label` | `0.875rem` / 500 | Form labels |
| `text-table` | `0.8125rem` | Dense tables |
| `text-helper`, `text-xs` | `--fs-helper` / `--fs-xs` | Helper text, badges, breadcrumbs |

Section labels in the sidebar are `11px`, semibold, uppercase, tracked.

## 6. Components and variants

| Area | Component | Variants |
| --- | --- | --- |
| Buttons | `Button`, `buttonClassName` | `primary` (= `default`), `secondary`, `outline`, `ghost`, `destructive`, `destructiveOutline`, `link`; sizes `xs`, `sm`, `default` (= `md`), `lg`, `icon` |
| Inline actions | `actionLinkClassName` | tones `primary`, `neutral`, `danger`, `success`; sizes `sm`, default |
| Badges | `badgeClassName`, `StatusBadge` | tones above |
| Cards | `Card`, `CardHeader`, `CardTitle`, `CardDescription`, `CardFooter` | `card-tone-info/success/warning/danger` |
| KPI cards | `KpiCard` (dashboard), `StatCard`, `AppMetricCard` | tonal; see consolidation note in the consistency document |
| Tables | `TableCard`, `Table`, `THead`, `TBody`, `Tr`, `Th`, `Td`; `AppDataTable` | density follows `html[data-table-density]` |
| Row actions | `AppActionMenu` (app: Inertia links, translated label) over the kit `ActionMenu` | 32px ⋮ trigger; anchored list on `--app-surface`; item tones `default`, `warning` (amber), `danger` (red); `disabled` + `title`; `show` hides an item |
| Filters | `FilterPanel`, `FilterField`, `AppFilterBar`, `SearchInput` | |
| Pagination | `ListPagination` (Laravel paginators), `Pagination` (client callback) | |
| Forms | `FormField`, `FormLabel`, `FormDescription`, `FieldError`, `Input`, `Select`, `Textarea`, `FileUpload`, `SearchableSelect`, `FormSection` | |
| Dialogs | `AppDialog`; `useConfirm()` → `ConfirmationDialog` | confirm variants `default`, `warning`, `danger`, `success`; `requireReason`, `requireTyped` |
| Feedback | `Alert`, `AppToaster` (`useToast`), `EmptyState`, `ErrorState`, `Skeleton`, `AppSkeleton` family | |
| Navigation | `AuthenticatedLayout`, `AppSidebar`, `AppHeader`, `Breadcrumbs`, `PageHeader`, `AppCommandPalette` | |
| Icons | `@/Components/Icons` (content), `@/Components/NavIcons` (shell) | one weight each |

## 7. Appearance settings that reach the tokens

| Setting | Effect |
| --- | --- |
| Primary, secondary, accent colour | rewrite the brand variables above |
| Sidebar colour | `--sidebar-bg/fg/border/muted/hover/accent` are mixed from it |
| Default theme, user theme switching | `.dark` on `<html>`, the header toggle |
| Table density | `html[data-table-density="compact"]` tightens cell padding |
| Sticky table headers | `html[data-sticky-tables="true"]` |
| UI animations | `html[data-animations="false"]` zeroes transitions; the reduced-motion preference does the same |
| Sidebar compact default | initial collapsed state of the sidebar |
| Card radius | `html[data-card-radius="sm|md|lg|xl"]` rewrites `--radius-card` / `--radius-panel` (2/3, 4/6, 6/8, 8/10 px), so every `rounded-card` and `rounded-panel` surface follows it |
| Button style | `html[data-button-style="square"]` sets `--radius-control: 0`, `"soft"` sets `8px`; `rounded` keeps the 6px default |
