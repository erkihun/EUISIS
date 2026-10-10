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
| Text input | `<Input>` (full width) | `inputClassName` + your width |
| Select | `<Select>` (full width) | `selectClassName` + your width |
| Textarea | `<Textarea>` | `textareaClassName` + your width |
| Label, help, error | `<FormField>`, `<FormLabel>`, `<FieldError>` | — |
| Search box | `<SearchInput>` | — |
| Filter row | `<FilterBar>` or `<AppFilterBar>` (Inertia GET) | `filterInputCls` for its controls |
| Table | `<TableCard>` + `<Table>`, `<THead>`, `<TBody>`, `<Tr>`, `<Th align>`, `<Td align numeric muted>` | `tableClassName`, `tableHeadClassName`, `tableBodyClassName`, `tableRowClassName`, `tableHeaderCellClassName`, `tableCellClassName` |
| Column-defined table | `<AppDataTable columns data>` | — |
| Pagination | `<PaginatorLinks links>` (Laravel paginator), `<Pagination meta>` (client) | — |
| Status | `<StatusBadge status>` (`@/Components/StatusBadge`) | — |
| Row actions | `<AppActionMenu items>` | — |
| Card / panel | `<Card>`, `<CardHeader>`, `<CardTitle>` | — |
| Empty list | `<EmptyState>` (`@/Components/EmptyState`) | — |
| Dialog / confirm | `useConfirm()` (`@/Components/ConfirmProvider`), `<Modal>` | — |

### Button variants

| Variant | For |
| --- | --- |
| `primary` | The one main action of a form or page (Save, Submit, Filter) |
| `outline` | Secondary actions next to it (Cancel, Clear, Export, Previous/Next) |
| `ghost` | Low-emphasis actions inside toolbars and rows |
| `destructive` | Delete, revoke, reject — irreversible or harmful actions |
| `link` | An inline text action |

Sizes: `sm` in tables and dense toolbars, default elsewhere, `lg` only for a
page's single call to action.

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
    footer={<PaginatorLinks links={employees.links} label={t('common.pagination')} />}>
    <Table>
        <THead><tr><Th>{t('common.name')}</Th><Th align="right">{t('common.amount')}</Th></tr></THead>
        <TBody>
            {rows.map((row) => <Tr key={row.id}><Td>{row.name}</Td><Td numeric>{row.amount}</Td></Tr>)}
        </TBody>
    </Table>
</TableCard>
```

## History

On 2026-10-11 a codemod moved 356 admin files onto the kit: static class
names on buttons, button-styled links, inputs, selects, textareas and every
table element, plus shared `inputCls`-style constants, and replaced neutral
gray/slate pairs with the tokens. Files with uncommitted work at the time
(the sidebar, ID card preview/show, grievance committee show, the print
export modal, service-feedback submission pages) and the discipline module
were not touched. Dynamic `className={…}` expressions were left as written,
with their colours tokenised.
