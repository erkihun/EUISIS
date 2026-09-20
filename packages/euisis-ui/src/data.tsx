import { Fragment, useEffect, useMemo, useState, type ReactNode } from 'react';
import {
    Combobox,
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
import { SearchInput, controlClassName } from './forms';

export type { ColumnDef } from '@tanstack/react-table';
export { createColumnHelper } from '@tanstack/react-table';

export interface PaginationMeta {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
}

function pageRange(current: number, last: number): Array<number | 'ellipsis'> {
    if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1);
    const result: Array<number | 'ellipsis'> = [1];
    const left = Math.max(2, current - 2);
    const right = Math.min(last - 1, current + 2);
    if (left > 2) result.push('ellipsis');
    for (let page = left; page <= right; page += 1) result.push(page);
    if (right < last - 1) result.push('ellipsis');
    result.push(last);
    return result;
}

export function Pagination({ meta, onPageChange, onPerPageChange, pageSizes = [10, 25, 50, 100] }: {
    meta: PaginationMeta;
    onPageChange: (page: number) => void;
    onPerPageChange?: (size: number) => void;
    pageSizes?: number[];
}) {
    const messages = useUiMessages();
    const start = meta.total === 0 ? 0 : (meta.currentPage - 1) * meta.perPage + 1;
    const end = Math.min(meta.currentPage * meta.perPage, meta.total);
    return (
        <nav aria-label={`${messages.results} pagination`} className="flex flex-wrap items-center justify-between gap-3 text-sm text-[color:var(--app-muted-foreground)]">
            <div className="flex items-center gap-3">
                <span>{start}–{end} / {meta.total} {messages.results}</span>
                {onPerPageChange && <select aria-label={messages.results} value={meta.perPage} onChange={(event) => onPerPageChange(Number(event.target.value))} className={cx(controlClassName, 'h-[var(--control-h-sm)] w-auto py-0')}>{pageSizes.map((size) => <option key={size} value={size}>{size}</option>)}</select>}
            </div>
            {meta.lastPage > 1 && <div className="flex items-center gap-1">
                <Button variant="ghost" size="sm" disabled={meta.currentPage <= 1} onClick={() => onPageChange(meta.currentPage - 1)} aria-label={messages.previous}>‹</Button>
                {pageRange(meta.currentPage, meta.lastPage).map((page, index) => page === 'ellipsis'
                    ? <span key={`ellipsis-${index}`} className="px-1" aria-hidden="true">…</span>
                    : <Button key={page} variant={page === meta.currentPage ? 'default' : 'ghost'} size="sm" onClick={() => onPageChange(page)} aria-current={page === meta.currentPage ? 'page' : undefined}>{page}</Button>)}
                <Button variant="ghost" size="sm" disabled={meta.currentPage >= meta.lastPage} onClick={() => onPageChange(meta.currentPage + 1)} aria-label={messages.next}>›</Button>
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
        <div className="overflow-hidden rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
            {!loading && data.length === 0 ? <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} /> : <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)]">
                        {table.getHeaderGroups().map((group) => <tr key={group.id}>{group.headers.map((header) => <th key={header.id} className="px-4 py-3 text-xs font-semibold text-[color:var(--app-muted-foreground)]">{header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}</th>)}</tr>)}
                    </thead>
                    <tbody className="divide-y divide-[color:var(--app-border)]">
                        {loading ? Array.from({ length: pagination?.perPage ?? 6 }, (_, row) => <tr key={row}>{columns.map((_, col) => <td key={col} className="px-4 py-3"><Skeleton className="h-4 w-full" /></td>)}</tr>) : table.getRowModel().rows.map((row) => <tr key={row.id} className="hover:bg-[color:var(--app-surface-muted)]">{row.getVisibleCells().map((cell) => <td key={cell.id} className="px-4 py-3">{flexRender(cell.column.columnDef.cell, cell.getContext())}</td>)}</tr>)}
                    </tbody>
                </table>
            </div>}
        </div>
        {pagination && onPageChange && <Pagination meta={pagination} onPageChange={onPageChange} onPerPageChange={onPerPageChange} />}
    </div>;
}

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
    return <form className={cx('flex flex-col gap-3 rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-3 lg:flex-row lg:items-center lg:justify-between', className)} onSubmit={(event) => { event.preventDefault(); onSubmit?.(); }}>
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
            {search !== undefined && onSearchChange && <SearchInput value={search} onChange={onSearchChange} placeholder={searchPlaceholder ?? messages.search} className="w-full sm:w-72" />}
            {children}
            {secondary}
            {hasActiveFilters && onReset && <Button variant="ghost" size="sm" onClick={onReset}>{messages.clear}</Button>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2 lg:justify-end">{actions}</div>}
    </form>;
}

export interface SearchableOption { value: string; label: string; description?: string; disabled?: boolean }
export function SearchableSelect({ value, options: providedOptions, onChange, loadOptions, disabled, clearable = true, placeholder, searchPlaceholder, emptyText, loadingText, className }: {
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
    return <Combobox value={selected} onChange={(option: SearchableOption | null) => onChange(option?.value ?? '', option)} disabled={disabled} nullable>
        <div className={cx('relative', className)}>
            <ComboboxInput className={cx(controlClassName, clearable && value ? 'pr-9' : '')} displayValue={(option: SearchableOption | null) => option?.label ?? ''} onChange={(event) => setQuery(event.target.value)} placeholder={placeholder ?? searchPlaceholder ?? messages.search} />
            {clearable && value && <button type="button" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1" aria-label={messages.clear} onClick={() => onChange('', null)}>×</button>}
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
