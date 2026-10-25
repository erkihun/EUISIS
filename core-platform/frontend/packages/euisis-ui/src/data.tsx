import { Fragment, useEffect, useMemo, useState, type FormEvent, type MouseEvent, type ReactElement, type ReactNode } from 'react';
import {
    Combobox,
    ComboboxButton,
    ComboboxInput,
    ComboboxOption,
    ComboboxOptions,
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
} from '@headlessui/react';
import {
    createColumnHelper as createFeatureColumnHelper,
    flexRender,
    tableFeatures,
    useTable,
    type CellData,
    type ColumnDef as FeatureColumnDef,
    type RowData,
} from '@tanstack/react-table';
import { useUiMessages } from './context';
import { EmptyState } from './feedback';
import { Button, Skeleton, cx } from './primitives';
import { tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from './table';
import { controlClassName, selectClassName } from './forms';

/**
 * TanStack Table 9 types every column by the table's feature set. DataTable uses
 * none, so one empty set is bound here and callers keep ColumnDef<Row> and
 * createColumnHelper<Row>().
 */
const features = tableFeatures({});
type Features = typeof features;

export type ColumnDef<TData extends RowData, TValue extends CellData = CellData> = FeatureColumnDef<Features, TData, TValue>;
export const createColumnHelper = <TData extends RowData>() => createFeatureColumnHelper<Features, TData>();

export interface PaginationMeta {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    /** First and last row numbers on this page, when the server sends them. */
    from?: number | null;
    to?: number | null;
}

/*
 * At most seven page numbers whatever the page count: 3M employees at 25 a
 * page is 120,000 pages, which must never become 120,000 buttons.
 */
function pageRange(current: number, last: number): Array<number | 'ellipsis'> {
    if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1);
    let left = Math.max(2, current - 1);
    let right = Math.min(last - 1, current + 1);
    if (current <= 4) { left = 2; right = 5; }
    if (current >= last - 3) { left = last - 4; right = last - 1; }
    const result: Array<number | 'ellipsis'> = [1];
    if (left > 2) result.push('ellipsis');
    for (let page = left; page <= right; page += 1) result.push(page);
    if (right < last - 1) result.push('ellipsis');
    result.push(last);
    return result;
}

/** One page button or link; Laravel paginator links use it too. */
export function paginationItemClassName(active = false): string {
    return cx(
        'inline-flex h-8 min-w-8 items-center justify-center rounded-control px-2 text-[13px] tabular-nums transition-colors',
        'disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
        active
            ? 'bg-primary font-semibold text-(--color-on-primary,#fff)'
            : 'border border-(--app-border) text-(--app-foreground) hover:bg-(--app-surface-muted)',
    );
}

export function ChevronIcon({ direction }: { direction: 'left' | 'right' }) {
    return <svg aria-hidden="true" viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d={direction === 'left' ? 'm15 18-6-6 6-6' : 'm9 18 6-6-6-6'} /></svg>;
}

/** "26–50 of 340" in the viewer's language. */
export function paginationRange(template: string, meta: PaginationMeta): string {
    const from = meta.from ?? (meta.total === 0 ? 0 : (meta.currentPage - 1) * meta.perPage + 1);
    const to = meta.to ?? Math.min(meta.currentPage * meta.perPage, meta.total);
    const values: Record<string, number> = { from, to, total: meta.total };
    // One pass, whole names: ':to' must not eat the start of ':total'.
    return template.replace(/:(from|total|to)\b/g, (_, name: string) => values[name].toLocaleString());
}

/**
 * The one pagination bar: the visible range on the left, page buttons on the
 * right. Draws nothing for an empty list and no buttons for a single page.
 */
export function Pagination({ meta, onPageChange, onPerPageChange, pageSizes = [10, 25, 50, 100], disabled = false, label, className }: {
    meta: PaginationMeta;
    onPageChange: (page: number) => void;
    onPerPageChange?: (size: number) => void;
    pageSizes?: number[];
    /** While a page loads. */
    disabled?: boolean;
    label?: string;
    className?: string;
}) {
    const messages = useUiMessages();
    if (meta.total <= 0) return null;
    return (
        <nav aria-label={label ?? messages.pagination} className={cx('flex flex-wrap items-center justify-between gap-3', className)}>
            <div className="flex items-center gap-3 text-xs text-(--app-muted-foreground)">
                <p role="status" className="whitespace-nowrap tabular-nums">{paginationRange(messages.range, meta)}</p>
                {onPerPageChange && <select aria-label={messages.results} value={meta.perPage} disabled={disabled} onChange={(event) => onPerPageChange(Number(event.target.value))} className={cx(selectClassName, 'h-8 w-auto')}>{pageSizes.map((size) => <option key={size} value={size}>{size}</option>)}</select>}
            </div>
            {meta.lastPage > 1 && <div className="flex flex-wrap items-center gap-1">
                <button type="button" className={paginationItemClassName()} disabled={disabled || meta.currentPage <= 1} onClick={() => onPageChange(meta.currentPage - 1)} aria-label={messages.previous}><ChevronIcon direction="left" /></button>
                {pageRange(meta.currentPage, meta.lastPage).map((page, index) => page === 'ellipsis'
                    ? <span key={`ellipsis-${index}`} className="px-1 text-(--app-muted-foreground)" aria-hidden="true">…</span>
                    : <button key={page} type="button" data-page={page} className={paginationItemClassName(page === meta.currentPage)} disabled={disabled} onClick={() => page !== meta.currentPage && onPageChange(page)} aria-label={`${messages.page} ${page}`} aria-current={page === meta.currentPage ? 'page' : undefined}>{page.toLocaleString()}</button>)}
                <button type="button" className={paginationItemClassName()} disabled={disabled || meta.currentPage >= meta.lastPage} onClick={() => onPageChange(meta.currentPage + 1)} aria-label={messages.next}><ChevronIcon direction="right" /></button>
            </div>}
        </nav>
    );
}

export function DataTable<T extends RowData>({ data, columns, loading = false, emptyTitle, emptyDescription, emptyAction, pagination, onPageChange, onPerPageChange, className }: {
    data: T[];
    columns: ColumnDef<T, any>[];
    loading?: boolean;
    emptyTitle?: ReactNode;
    emptyDescription?: ReactNode;
    emptyAction?: ReactNode;
    pagination?: PaginationMeta;
    onPageChange?: (page: number) => void;
    onPerPageChange?: (size: number) => void;
    className?: string;
}) {
    const table = useTable({ features, data, columns });
    return <div className={cx(tableContainerClassName, className)}>
            {!loading && data.length === 0 ? <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} /> : <div className="overflow-x-auto">
                <table className={tableClassName}>
                    <thead className={tableHeadClassName}>
                        {table.getHeaderGroups().map((group) => <tr key={group.id}>{group.headers.map((header) => <th key={header.id} scope="col" className={cx(tableHeaderCellClassName, 'text-left')}>{header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}</th>)}</tr>)}
                    </thead>
                    <tbody className={tableBodyClassName}>
                        {loading ? Array.from({ length: pagination?.perPage ?? 6 }, (_, row) => <tr key={row}>{columns.map((_, col) => <td key={col} className={tableCellClassName}><Skeleton className="h-4 w-full" /></td>)}</tr>) : table.getRowModel().rows.map((row) => <tr key={row.id} className={tableRowClassName}>{row.getAllCells().map((cell) => <td key={cell.id} className={tableCellClassName}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</td>)}</tr>)}
                    </tbody>
                </table>
            </div>}
        {pagination && onPageChange && <Pagination meta={pagination} onPageChange={onPageChange} onPerPageChange={onPerPageChange} className="border-t border-(--app-border) px-4 py-3" />}
    </div>;
}

/** The filter panel's frame, for a page whose filter markup must stay its own. */
export const filterPanelClassName = 'rounded-card border border-(--app-border) bg-(--app-surface) p-4';

/**
 * The one filter panel. Fields sit in a wrapping row, each labelled above its
 * control; Filter applies them (so a server is queried once per intent, not
 * per keystroke) and Reset appears only while something is filtered.
 */
export function FilterPanel({ children, onSubmit, onReset, hasActiveFilters = false, processing = false, label, submitLabel, resetLabel, actions, footer, className }: {
    children: ReactNode;
    /** Called after the browser submit is prevented. */
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    onReset?: () => void;
    hasActiveFilters?: boolean;
    /** Disables the fields while results load. */
    processing?: boolean;
    /** Accessible name of the form, e.g. "Employee filters". */
    label?: string;
    submitLabel?: ReactNode;
    resetLabel?: ReactNode;
    /** Page actions that belong with the filters (Export), pushed to the right. */
    actions?: ReactNode;
    /** A hint under the fields. */
    footer?: ReactNode;
    className?: string;
}) {
    const messages = useUiMessages();
    return (
        <form role="search" aria-label={label ?? messages.filters} className={cx(filterPanelClassName, className)} onSubmit={(event) => { event.preventDefault(); onSubmit(event); }}>
            <fieldset disabled={processing} className="flex min-w-0 flex-wrap items-end gap-3">
                {children}
                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary">{submitLabel ?? messages.filter}</Button>
                    {hasActiveFilters && onReset && <Button type="button" variant="ghost" onClick={onReset}>{resetLabel ?? messages.reset}</Button>}
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2 sm:ml-auto">{actions}</div>}
            </fieldset>
            {footer && <div className="mt-3 text-xs text-(--app-muted-foreground)">{footer}</div>}
        </form>
    );
}

/** A filter field's frame, for a widget that draws its own label (an employee picker). */
export function filterFieldClassName(wide = false): string {
    // A plain input or select fills its field, whatever width class it carries.
    return cx('flex min-w-0 flex-col gap-1.5 [&>input]:w-full [&>select]:w-full', wide ? 'w-full sm:min-w-64 sm:flex-[2_1_16rem]' : 'w-full sm:w-48');
}

/** The caption above a filter control. */
export const filterCaptionClassName = 'text-xs font-medium text-(--app-muted-foreground)';

/**
 * One labelled field of a filter panel. It wraps its control in the label;
 * for a widget with buttons of its own (a date picker), pass the control's
 * id as `htmlFor` instead.
 */
export function FilterField({ label, htmlFor, wide = false, className, children }: {
    label: ReactNode;
    htmlFor?: string;
    /** The search box: grows to fill the row. */
    wide?: boolean;
    className?: string;
    children: ReactNode;
}) {
    const frame = cx(filterFieldClassName(wide), className);
    if (htmlFor) return <div className={frame}><label htmlFor={htmlFor} className={filterCaptionClassName}>{label}</label>{children}</div>;
    return <label className={frame}><span className={filterCaptionClassName}>{label}</span>{children}</label>;
}

export interface SearchableOption { value: string; label: string; description?: string; disabled?: boolean }
export function SearchableSelect({ id, label, immediate = false, value, options: providedOptions, onChange, loadOptions, disabled, clearable = true, placeholder, searchPlaceholder, emptyText, loadingText, className }: {
    id?: string;
    /** Accessible name of the input. Headless UI replaces aria-labelledby, so the name is passed as text. */
    label?: string;
    /** Open the option list on focus rather than on typing. */
    immediate?: boolean;
    value: string;
    options?: SearchableOption[];
    onChange: (value: string, option: SearchableOption | null) => void;
    loadOptions?: (query: string, signal: AbortSignal) => Promise<SearchableOption[]>;
    disabled?: boolean;
    clearable?: boolean;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    loadingText?: string;
    className?: string;
}) {
    const messages = useUiMessages();
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState(providedOptions ?? []);
    const [loading, setLoading] = useState(false);
    useEffect(() => { if (providedOptions) setOptions(providedOptions); }, [providedOptions]);
    useEffect(() => {
        if (!loadOptions || disabled) return;
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            try { setOptions(await loadOptions(query, controller.signal)); }
            catch (error) { if ((error as Error).name !== 'AbortError') setOptions([]); }
            finally { if (!controller.signal.aborted) setLoading(false); }
        }, query ? 250 : 0);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [disabled, loadOptions, query]);
    const selected = useMemo(() => options.find((option) => option.value === value) ?? null, [options, value]);
    const filtered = loadOptions ? options : options.filter((option) => option.label.toLocaleLowerCase().includes(query.toLocaleLowerCase()));
    return <Combobox value={selected} onChange={(option: SearchableOption | null) => onChange(option?.value ?? '', option)} disabled={disabled} immediate={immediate} nullable>
        <div className={cx('relative', className)}>
            <ComboboxInput id={id} aria-label={label} className={cx(controlClassName, (clearable && value) || !clearable ? 'pr-9' : '')} displayValue={(option: SearchableOption | null) => option?.label ?? ''} onChange={(event) => setQuery(event.target.value)} placeholder={placeholder ?? searchPlaceholder ?? messages.search} />
            {clearable && value && <button type="button" className="absolute right-2 top-1/2 -translate-y-1/2 rounded-sm p-1" aria-label={messages.clear} onClick={() => onChange('', null)}>×</button>}
            {!clearable && (
                <ComboboxButton className="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-(--app-muted-foreground)">
                    <svg aria-hidden="true" viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="m6 9 6 6 6-6" /></svg>
                </ComboboxButton>
            )}
            <ComboboxOptions anchor="bottom start" className="z-50 mt-1 max-h-64 w-(--input-width) overflow-auto rounded-card border border-(--app-border) bg-(--app-surface) p-1 shadow-lg empty:hidden">
                {loading ? <div className="px-3 py-2 text-sm text-(--app-muted-foreground)">{loadingText ?? messages.loading}</div> : filtered.length === 0 ? <div className="px-3 py-2 text-sm text-(--app-muted-foreground)">{emptyText ?? messages.noResults}</div> : filtered.map((option) => <ComboboxOption key={option.value} value={option} disabled={option.disabled} className="cursor-pointer rounded-control px-3 py-2 text-sm data-focus:bg-(--app-surface-muted) data-disabled:opacity-50"><span className="block">{option.label}</span>{option.description && <span className="block text-xs text-(--app-muted-foreground)">{option.description}</span>}</ComboboxOption>)}
            </ComboboxOptions>
        </div>
    </Combobox>;
}

export type ActionMenuTone = 'default' | 'warning' | 'danger';
export interface ActionItem {
    label: ReactNode;
    icon?: ReactNode;
    onClick?: () => void;
    href?: string;
    /** Inertia method for an href; anything but GET renders the link as a button. */
    method?: 'get' | 'post' | 'put' | 'patch' | 'delete';
    tone?: ActionMenuTone;
    /** @deprecated Use `tone: 'danger'`. */
    danger?: boolean;
    /** Tooltip, e.g. why the action is disabled. */
    title?: string;
    separatorBefore?: boolean;
    show?: boolean;
    disabled?: boolean;
}
const actionItemTones: Record<ActionMenuTone, { text: string; focus: string }> = {
    default: { text: 'text-(--app-foreground)', focus: 'bg-(--app-surface-muted)' },
    warning: { text: 'text-amber-700 dark:text-amber-400', focus: 'bg-amber-50 dark:bg-amber-900/20' },
    danger: { text: 'text-red-700 dark:text-red-400', focus: 'bg-red-50 dark:bg-red-900/20' },
};
/**
 * The ⋮ menu that holds a record's actions: one 32px trigger at the end of a table row or card and a
 * floating list (anchored, so a scrolling table card never clips it) with one tone per intent. Links
 * render through `renderLink` (the app passes Inertia's Link); a menu with no visible item renders nothing.
 */
export function ActionMenu({ items, label, renderLink }: { items: ActionItem[]; label?: string; renderLink?: (item: ActionItem, className: string) => ReactElement }) {
    const messages = useUiMessages();
    const visible = items.filter((item) => item.show !== false);
    if (!visible.length) return null;
    const name = label ?? messages.actions;
    return <Menu as="div" className="relative inline-block">
        {/* Rows that navigate on click must not see the clicks that open the menu or pick an action (React bubbles through the portal). */}
        <MenuButton onClick={(event: MouseEvent) => event.stopPropagation()} className="inline-flex h-8 w-8 items-center justify-center rounded-control text-(--app-muted-foreground) transition-colors hover:bg-(--app-surface-muted) hover:text-(--app-foreground) focus:outline-hidden focus-visible:ring-2 focus-visible:ring-primary data-open:bg-(--app-surface-muted) data-open:text-(--app-foreground)" aria-label={name} title={name}>
            <svg aria-hidden="true" viewBox="0 0 24 24" className="h-4 w-4" fill="currentColor"><circle cx="12" cy="5" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="12" cy="19" r="1.6" /></svg>
        </MenuButton>
        <MenuItems transition anchor="bottom end" onClick={(event: MouseEvent) => event.stopPropagation()} className="z-50 w-48 origin-top-right rounded-card border border-(--app-border) bg-(--app-surface) p-1 shadow-lg transition duration-100 ease-out focus:outline-hidden data-closed:scale-95 data-closed:opacity-0 [--anchor-gap:4px]">
                {visible.map((item, index) => {
                    const tone = actionItemTones[item.tone ?? (item.danger ? 'danger' : 'default')];
                    return <Fragment key={index}>
                        {item.separatorBefore && <div className="my-1 border-t border-(--app-border)" />}
                        <MenuItem disabled={item.disabled}>{({ focus }) => {
                            const itemClass = cx('flex w-full items-center gap-2 whitespace-nowrap rounded-control px-3 py-2 text-left text-sm [&>svg]:h-4 [&>svg]:w-4 [&>svg]:shrink-0', tone.text, focus && tone.focus, item.disabled && 'cursor-not-allowed opacity-50');
                            if (item.href && renderLink) return renderLink(item, itemClass);
                            if (item.href) return <a href={item.href} className={itemClass} title={item.title} aria-disabled={item.disabled || undefined}>{item.icon}{item.label}</a>;
                            return <button type="button" className={itemClass} onClick={item.onClick} disabled={item.disabled} title={item.title}>{item.icon}{item.label}</button>;
                        }}</MenuItem>
                    </Fragment>;
                })}
            </MenuItems>
    </Menu>;
}
