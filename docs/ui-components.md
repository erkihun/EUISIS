# Admin UI components

Every admin page builds its buttons, form controls, tables and filters from
one kit, so the same element looks and behaves the same on every page and in
both themes. Import from `@/Components/ui` unless the table names another
module; the kit lives in `packages/euisis-ui`. `tests/Unit/AdminUiConsistencyTest.php` fails when a
page adds hand-rolled versions.

## What to use

| Need | Component | Class name (when markup must stay plain) |
| --- | --- | --- |
| Button | `<Button variant size>` | `buttonClassName({ variant, size, className })` |
| Link that looks like a button | `<Button as={Link} href=…>` | `buttonClassName(…)` on the `<Link>` |
| Text input | `<Input>` (full width) | `inputClassName` + your width (also correct on a `<select>`: it keeps room for the chevron) |
| Select | `<Select>` (full width) | `selectClassName` + your width |
| Textarea | `<Textarea>` | `textareaClassName` + your width |
| Label, help, error | `<FormField>`, `<FormLabel>`, `<FieldError>` | — |
| Search box | `<SearchInput>` | — |
| List filters | `<FilterPanel>` + `<FilterField label>`; `<AppFilterBar routeName>` when the controls are uncontrolled (`name` + `defaultValue`) | `filterPanelClassName`, `filterFieldClassName(wide)`, `filterCaptionClassName` |
| Table | `<TableCard>` + `<Table>`, `<THead>`, `<TBody>`, `<Tr>`, `<Th align>`, `<Td align numeric muted>` | `tableClassName`, `tableHeadClassName`, `tableBodyClassName`, `tableRowClassName`, `tableHeaderCellClassName`, `tableCellClassName` |
| Column-defined table | `<AppDataTable columns data>` | — |
| Pagination | `<ListPagination meta>` (any Laravel paginator; keeps the query string), `<Pagination meta onPageChange>` (kit) | `paginationItemClassName(active)` |
| Status | `<StatusBadge status>` (`@/Components/StatusBadge`) | `badgeClassName(tone, className)` — tone `neutral`, `info`, `success`, `warning`, `danger` |
| Row actions | `<AppActionMenu items>` | `actionLinkClassName({ tone, size, className })` for inline Edit / View / Delete / Restore text links — tone `primary`, `neutral`, `danger`, `success` |
| Card / panel | `<Card>`, `<CardHeader>`, `<CardTitle>` | — |
| Empty list | `<EmptyState>` (`@/Components/EmptyState`) | — |
| Dialog / confirm | `useConfirm()` (`@/Components/ConfirmProvider`), `<Modal>` | — |

### Button variants

| Variant | For |
| --- | --- |
| `primary` | The one main action of a form or page (Save, Submit, Filter) |
| `outline` | Secondary actions next to it (Cancel, Clear, Export, Previous/Next) |
| `ghost` | Low-emphasis actions inside toolbars and rows |
| `destructive` | Delete, revoke, reject — the page's irreversible main action |
| `destructiveOutline` | A harmful action beside the main one (Remove next to Save) |
| `link` | An inline text action |

Sizes: `sm` in tables and dense toolbars, default elsewhere, `lg` only for a
page's single call to action.

## A list page

Every admin list is built the same way, top to bottom:

1. `PageHeader` with the page's main action (Create).
2. `FilterPanel` — one labelled `FilterField` per filter (the search box is
   `wide`), then **Filter** (primary) and **Reset** (only while something is
   filtered). Filters apply when the user presses Filter or Enter, never per
   keystroke: one query per intent against a registry of millions.
3. The table in a frame with `tableContainerClassName` (or `TableCard`),
   `EmptyState` inside it — with `common.noMatchingRecords` as the
   description when filters are active — and `ListPagination` as its last
   child. The pagination bar states the range ("26–50 of 340") and draws at
   most seven page numbers.

```tsx
<FilterPanel onSubmit={submit} onReset={reset} hasActiveFilters={Object.values(filters).some(Boolean)}>
    <FilterField label={t('common.search')} wide><Input type="search" value={draft.search} onChange={…} /></FilterField>
    <FilterField label={t('common.status')}><Select value={draft.status} onChange={…}>…</Select></FilterField>
    <FilterField label={t('common.effectiveFrom')} htmlFor="from"><LocalizedDatePicker id="from" … /></FilterField>
</FilterPanel>
<div className={tableContainerClassName}>
    {rows.length === 0 ? <EmptyState … /> : <div className="overflow-x-auto"><table …>…</table></div>}
    <ListPagination meta={paginator} />
</div>
```

When the server echoes default filter values (a default date window or
period), decide "is something filtered?" with `hasFilterQuery()`, which reads
the URL, so Reset does not appear for values the user never chose. Dates use
`LocalizedDatePicker`, never a native `<input type="date">`.

Deliberate exceptions, which keep their own controls: view switchers that
change what the whole page shows (the performance dashboard cycle, the
daily-activity report type, the oversight cycle picker), the service-feedback
filter bar (instant filters, chips and date presets shared by its dashboard,
reports and inbox) and the service-providers toolbar (status tabs with a
type sidebar). `tests/Unit/AdminUiConsistencyTest.php` counts hand-rolled
pagination, one-button-per-page lists, GET search forms and selects that
reload the page, so new ones fail the build.

## Rules

- **Colours come from tokens:** `text-[color:var(--app-foreground)]`,
  `text-[color:var(--app-muted-foreground)]`, `bg-[color:var(--app-surface)]`,
  `bg-[color:var(--app-surface-muted)]`, `border-[color:var(--app-border)]`,
  `border-[color:var(--app-border-strong)]`. They switch with dark mode; a raw
  `text-gray-500 dark:text-slate-400` pair does not need to be written.
  Semantic colours (green credit, red error) stay as they are.
- **Text on a primary fill** uses `text-[color:var(--color-on-primary)]`, not
  `text-white`: a bright brand colour's dark-mode variant needs dark text.
- **Add width, not style.** The control classes carry no width, and the table
  cell classes carry no alignment or colour, so a page adds `w-full`, `w-40`,
  `text-right` or a colour without fighting a built-in value. (When two
  classes set the same property, the CSS order decides, not the class order.)
- **Keep layout classes** (`w-*`, margins, `flex-1`, positioning) on the
  element; never padding, radius, border or background on a control.

## Example

```tsx
import { Button, TableCard, Table, THead, TBody, Tr, Th, Td, selectClassName, cx } from '@/Components/ui';

<TableCard title={t('employees.title')} actions={<Button variant="primary" size="sm">{t('common.add')}</Button>}
    footer={<ListPagination meta={employees.meta} label={t('common.pagination')} />}>
    <Table>
        <THead><tr><Th>{t('common.name')}</Th><Th align="right">{t('common.amount')}</Th></tr></THead>
        <TBody>
            {rows.map((row) => <Tr key={row.id}><Td>{row.name}</Td><Td numeric>{row.amount}</Td></Tr>)}
        </TBody>
    </Table>
</TableCard>
```

## History

On 2026-10-11 (third pass) every admin list moved onto one filter panel and
one pagination bar. The audit log drew one button per page; twelve lists —
the five cafeteria setup lists, card requests, print batches, the recycle
bin, vacancy announcements, tribunal cases, transfer applications and
hierarchy versions — received page data but drew no working page controls,
so nothing past the first page was reachable. Range text is now filled in one pass (`:to` no longer corrupts
`:total`), and the kit's one-row `FilterBar` was removed.


On 2026-10-11 a codemod moved 356 admin files onto the kit: static class
names on buttons, button-styled links, inputs, selects, textareas and every
table element, plus shared `inputCls`-style constants, and replaced neutral
gray/slate pairs with the tokens. Files with uncommitted work at the time
(the sidebar, ID card preview/show, grievance committee show, the print
export modal, service-feedback submission pages) and the discipline module
were not touched. Dynamic `className={…}` expressions were left as written,
with their colours tokenised.

A second pass the same day turned unpaired neutral colours (a light
`text-gray-500` with no dark variant) into tokens — skipping colour tints
without a dark variant and files with dark surfaces, where a token could lower
contrast — moved bordered cards from `rounded-xl`/`rounded-2xl` to
`rounded-panel`, turned static status pills into `badgeClassName` (squared,
as `StatusBadge`), and rewrote the shared style modules
(`dailyActivity/helpers.ts`, `performance/ui.tsx`, `EmployeeFormLayout.tsx`)
onto the kit, so every page importing them follows. The ID card renderers
(`Components/IdCards/IdCard*.tsx`) are never changed: the printed card does
not follow the admin theme.
