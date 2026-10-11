import { Fragment, useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
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
    Transition,
} from '@headlessui/react';
import { flexRender, getCoreRowModel, useReactTable, type ColumnDef } from '@tanstack/react-table';
import { useUiMessages } from './context';
import { EmptyState } from './feedback';
import { Button, Skeleton, cx } from './primitives';
import { tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from './table';
import { SearchInput, controlClassName, selectClassName } from './forms';

export type { ColumnDef } from '@tanstack/react-table';
export { createColumnHelper } from '@tanstack/react-table';

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
        'inline-flex h-8 min-w-8 items-center justify-center rounded-[var(--radius-control)] px-2 text-[13px] tabular-nums transition-colors',
        'disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
        active
            ? 'bg-[color:var(--color-primary)] font-semibold text-[color:var(--color-on-primary,#fff)]'
            : 'border border-[color:var(--app-border)] text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]',
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
            <div className="flex items-center gap-3 text-xs text-[color:var(--app-muted-foreground)]">
                <p role="status" className="whitespace-nowrap tabular-nums">{paginationRange(messages.range, meta)}</p>
                {onPerPageChange && <select aria-label={messages.results} value={meta.perPage} disabled={disabled} onChange={(event) => onPerPageChange(Number(event.target.value))} className={cx(selectClassName, 'h-8 w-auto')}>{pageSizes.map((size) => <option key={size} value={size}>{size}</option>)}</select>}
            </div>
            {meta.lastPage > 1 && <div className="flex flex-wrap items-center gap-1">
                <button type="button" className={paginationItemClassName()} disabled={disabled || meta.currentPage <= 1} onClick={() => onPageChange(meta.currentPage - 1)} aria-label={messages.previous}><ChevronIcon direction="left" /></button>
                {pageRange(meta.currentPage, meta.lastPage).map((page, index) => page === 'ellipsis'
                    ? <span key={`ellipsis-${index}`} className="px-1 text-[color:var(--app-muted-foreground)]" aria-hidden="true">…</span>
                    : <button key={page} type="button" data-page={page} className={paginationItemClassName(page === meta.currentPage)} disabled={disabled} onClick={() => page !== meta.currentPage && onPageChange(page)} aria-label={`${messages.page} ${page}`} aria-current={page === meta.currentPage ? 'page' : undefined}>{page.toLocaleString()}</button>)}
                <button type="button" className={paginationItemClassName()} disabled={disabled || meta.currentPage >= meta.lastPage} onClick={() => onPageChange(meta.currentPage + 1)} aria-label={messages.next}><ChevronIcon direction="right" /></button>
            </div>}
        </nav>
    );
}

export function DataTable<T>({ data, columns, loading = false, emptyTitle, emptyDescription, emptyAction, pagination, onPageChange, onPerPageChange, className }: {
    data: T[];
    columns: ColumnDef<T, unknown>[];
    loading?: boolean;
    emptyTitle?: ReactNode;
    emptyDescription?: ReactNode;
    emptyAction?: ReactNode;
    pagination?: PaginationMeta;
    onPageChange?: (page: number) => void;
    onPerPageChange?: (size: number) => void;
    className?: string;
}) {
    const table = useReactTable({ data, columns, getCoreRowModel: getCoreRowModel() });
    return <div className={cx('space-y-3', className)}>
        <div className={tableContainerClassName}>
            {!loading && data.length === 0 ? <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} /> : <div className="overflow-x-auto">
                <table className={tableClassName}>
                    <thead className={tableHeadClassName}>
                        {table.getHeaderGroups().map((group) => <tr key={group.id}>{group.headers.map((header) => <th key={header.id} scope="col" className={cx(tableHeaderCellClassName, 'text-left')}>{header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}</th>)}</tr>)}
                    </thead>
                    <tbody className={tableBodyClassName}>
                        {loading ? Array.from({ length: pagination?.perPage ?? 6 }, (_, row) => <tr key={row}>{columns.map((_, col) => <td key={col} className={tableCellClassName}><Skeleton className="h-4 w-full" /></td>)}</tr>) : table.getRowModel().rows.map((row) => <tr key={row.id} className={tableRowClassName}>{row.getVisibleCells().map((cell) => <td key={cell.id} className={tableCellClassName}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</td>)}</tr>)}
                    </tbody>
                </table>
            </div>}
        </div>
        {pagination && onPageChange && <Pagination meta={pagination} onPageChange={onPageChange} onPerPageChange={onPerPageChange} />}
    </div>;
}

/** The filter panel's frame, for a page whose filter markup must stay its own. */
export const filterPanelClassName = 'rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4';

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
            {footer && <div className="mt-3 text-xs text-[color:var(--app-muted-foreground)]">{footer}</div>}
        </form>
    );
}

/** A filter field's frame, for a widget that draws its own label (an employee picker). */
export function filterFieldClassName(wide = false): string {
    // A plain input or select fills its field, whatever width class it carries.
    return cx('flex min-w-0 flex-col gap-1.5 [&>input]:w-full [&>select]:w-full', wide ? 'w-full sm:min-w-64 sm:flex-[2_1_16rem]' : 'w-full sm:w-48');
}

/** The caption above a filter control. */
export const filterCaptionClassName = 'text-xs font-medium text-[color:var(--app-muted-foreground)]';

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

/**
 * A filter row that applies as the user changes it (client-side lists). Same
 * frame as FilterPanel; prefer FilterPanel for server queries.
 */
export function FilterBar({ search, onSearchChange, searchPlaceholder, children, secondary, actions, onReset, hasActiveFilters, onSubmit, className }: {
    search?: string;
    onSearchChange?: (value: string) => void;
    searchPlaceholder?: string;
    children?: ReactNode;
    secondary?: ReactNode;
    actions?: ReactNode;
    onReset?: () => void;
    hasActiveFilters?: boolean;
    onSubmit?: () => void;
    className?: string;
}) {
    const messages = useUiMessages();
    return <form role="search" aria-label={messages.filters} className={cx(filterPanelClassName, 'flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between', className)} onSubmit={(event) => { event.preventDefault(); onSubmit?.(); }}>
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-3">
            {search !== undefined && onSearchChange && <SearchInput value={search} onChange={onSearchChange} placeholder={searchPlaceholder ?? messages.search} className="w-full sm:w-72" />}
            {children}
            {secondary}
            {hasActiveFilters && onReset && <Button variant="ghost" onClick={onReset}>{messages.reset}</Button>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2 lg:justify-end">{actions}</div>}
    </form>;
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
            {clearable && value && <button type="button" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1" aria-label={messages.clear} onClick={() => onChange('', null)}>×</button>}
            {!clearable && (
                <ComboboxButton className="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-[color:var(--app-muted-foreground)]">
                    <svg aria-hidden="true" viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="m6 9 6 6 6-6" /></svg>
                </ComboboxButton>
            )}
            <ComboboxOptions anchor="bottom start" className="z-50 mt-1 max-h-64 w-[var(--input-width)] overflow-auto rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-1 shadow-lg empty:hidden">
                {loading ? <div className="px-3 py-2 text-sm text-[color:var(--app-muted-foreground)]">{loadingText ?? messages.loading}</div> : filtered.length === 0 ? <div className="px-3 py-2 text-sm text-[color:var(--app-muted-foreground)]">{emptyText ?? messages.noResults}</div> : filtered.map((option) => <ComboboxOption key={option.value} value={option} disabled={option.disabled} className="cursor-pointer rounded-[var(--radius-control)] px-3 py-2 text-sm data-[focus]:bg-[color:var(--app-surface-muted)] data-[disabled]:opacity-50"><span className="block">{option.label}</span>{option.description && <span className="block text-xs text-[color:var(--app-muted-foreground)]">{option.description}</span>}</ComboboxOption>)}
            </ComboboxOptions>
        </div>
    </Combobox>;
}

export interface ActionItem { label: ReactNode; icon?: ReactNode; onClick?: () => void; href?: string; danger?: boolean; separatorBefore?: boolean; show?: boolean; disabled?: boolean }
export function ActionMenu({ items, label, renderLink }: { items: ActionItem[]; label?: string; renderLink?: (item: ActionItem, className: string) => ReactNode }) {
    const messages = useUiMessages();
    const visible = items.filter((item) => item.show !== false);
    if (!visible.length) return null;
    return <Menu as="div" className="relative inline-block">
        <MenuButton className={cx('inline-flex h-[var(--control-h-md)] w-[var(--control-h-md)] items-center justify-center rounded-[var(--radius-control)] text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)]')} aria-label={label ?? messages.actions}>•••</MenuButton>
        <Transition as={Fragment} enter="transition duration-100" enterFrom="opacity-0 scale-95" enterTo="opacity-100 scale-100" leave="transition duration-75" leaveFrom="opacity-100 scale-100" leaveTo="opacity-0 scale-95">
            <MenuItems anchor="bottom end" className="z-50 mt-1 w-44 rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-1 shadow-lg focus:outline-none">
                {visible.map((item, index) => <Fragment key={index}>{item.separatorBefore && <div className="my-1 border-t border-[color:var(--app-border)]" /> }<MenuItem disabled={item.disabled}>{({ focus }) => {
                    const itemClass = cx('flex w-full items-center gap-2 rounded-[var(--radius-control)] px-3 py-2 text-left text-sm', focus && 'bg-[color:var(--app-surface-muted)]', item.danger && 'text-red-700 dark:text-red-400', item.disabled && 'opacity-50');
                    if (item.href && renderLink) return <>{renderLink(item, itemClass)}</>;
                    if (item.href) return <a href={item.href} className={itemClass}>{item.icon}{item.label}</a>;
                    return <button type="button" className={itemClass} onClick={item.onClick} disabled={item.disabled}>{item.icon}{item.label}</button>;
                }}</MenuItem></Fragment>)}
            </MenuItems>
        </Transition>
    </Menu>;
}
