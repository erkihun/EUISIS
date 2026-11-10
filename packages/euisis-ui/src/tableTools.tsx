import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent, type MouseEvent, type ReactNode } from 'react';
import { useUiMessages } from './context';
import { Button, cx } from './primitives';

/*
 * TableTools: the "pro" layer over any table in the app — sorting by clicking a
 * header, a columns menu (show/hide), row density and a kept-visible header —
 * without rewriting the table. It wraps a table (hand-rolled `<table>`, the kit
 * `Table` or `DataTable`) and works on the DOM it finds inside:
 *
 * - headers are read from the first `<thead>` row; a header that already holds
 *   a control (a page's own sort button, a select-all box) is left alone;
 * - a click on any other header sorts the rows on this page (asc → desc → off),
 *   with `Intl.Collator` in the page language and numeric awareness; when the
 *   page passes `serverSort` and the header carries `data-sort="column"`, the
 *   server is asked instead and the whole result set is sorted;
 * - hidden columns are hidden by one scoped stylesheet (nth-child), so cells
 *   need no code; density and the sticky header are data attributes the app's
 *   CSS reads (resources/css/app.css, "Table tools");
 * - choices are remembered per table `id` in localStorage.
 *
 * Nothing here changes the markup pages render; React never sees the reorder,
 * and a new page of rows (new keys) comes back in server order and is re-sorted.
 */

export type TableDensity = 'comfortable' | 'compact';
export type TableSortDirection = 'asc' | 'desc';

export interface TableServerSort {
    /** The column currently sorted on the server, as the header's `data-sort` names it. */
    column: string | null;
    direction: TableSortDirection;
    onSort: (column: string, direction: TableSortDirection) => void;
}

interface StoredTools {
    hidden: number[];
    density: TableDensity | null;
    sticky: boolean | null;
}

interface ColumnInfo {
    index: number;
    label: string;
    sortable: boolean;
    serverKey: string | null;
}

interface ClientSort {
    index: number;
    direction: TableSortDirection;
}

const STORAGE_PREFIX = 'euisis.table.';
const EMPTY: StoredTools = { hidden: [], density: null, sticky: null };

function readStored(id: string): StoredTools {
    try {
        const raw = localStorage.getItem(STORAGE_PREFIX + id);
        if (!raw) return EMPTY;
        const parsed = JSON.parse(raw) as Partial<StoredTools>;
        return {
            hidden: Array.isArray(parsed.hidden) ? parsed.hidden.filter((n): n is number => Number.isInteger(n)) : [],
            density: parsed.density === 'compact' || parsed.density === 'comfortable' ? parsed.density : null,
            sticky: typeof parsed.sticky === 'boolean' ? parsed.sticky : null,
        };
    } catch {
        return EMPTY;
    }
}

function writeStored(id: string, value: StoredTools): void {
    try {
        const empty = value.hidden.length === 0 && value.density === null && value.sticky === null;
        if (empty) localStorage.removeItem(STORAGE_PREFIX + id);
        else localStorage.setItem(STORAGE_PREFIX + id, JSON.stringify(value));
    } catch {
        // Storage unavailable (private mode, blocked): the choice still holds for this visit.
    }
}

/** A header's visible words: screen-reader-only text (a page's "sorted ascending") is left out. */
function visibleLabel(cell: HTMLTableCellElement): string {
    const copy = cell.cloneNode(true) as HTMLElement;
    copy.querySelectorAll('.sr-only, [aria-hidden="true"]').forEach((node) => node.remove());
    return copy.textContent?.replace(/\s+/g, ' ').trim() ?? '';
}

function sameColumns(a: ColumnInfo[], b: ColumnInfo[]): boolean {
    return a.length === b.length && a.every((column, index) => column.label === b[index].label && column.sortable === b[index].sortable && column.serverKey === b[index].serverKey);
}

const NUMERIC = /^[-+]?\d[\d,.\s]*%?$/;

/** The value a cell sorts by: `data-sort-value` when a page sets one, else its text. */
function cellValue(cell: HTMLTableCellElement | undefined): string {
    if (!cell) return '';
    return cell.dataset.sortValue ?? cell.textContent?.trim() ?? '';
}

function compareValues(a: string, b: string, collator: Intl.Collator): number {
    if (a === b) return 0;
    if (a === '') return 1; // Empty cells sink to the end whichever way the column sorts.
    if (b === '') return -1;
    if (NUMERIC.test(a) && NUMERIC.test(b)) {
        const na = parseFloat(a.replace(/[,\s%]/g, ''));
        const nb = parseFloat(b.replace(/[,\s%]/g, ''));
        if (!Number.isNaN(na) && !Number.isNaN(nb) && na !== nb) return na - nb;
    }
    return collator.compare(a, b);
}

function Icon({ path, className }: { path: string; className?: string }) {
    return (
        <svg viewBox="0 0 24 24" className={cx('h-4 w-4', className)} fill="none" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d={path} />
        </svg>
    );
}

const ICONS = {
    columns: 'M4 5h16v14H4zM9 5v14M15 5v14',
    compact: 'M4 6h16M4 10h16M4 14h16M4 18h16',
    comfortable: 'M4 7h16M4 12h16M4 17h16',
    pin: 'M12 17v5M5 10l2-2V4h10v4l2 2v3H5z',
    reset: 'M3 12a9 9 0 1 0 3-6.7M3 4v5h5',
};

export interface TableToolsProps {
    /** Stable name of this table ("employees-index"); the viewer's choices are remembered under it. */
    id: string;
    children: ReactNode;
    /** Draw the toolbar above the table (default). `false` keeps header sorting without the toolbar. */
    toolbar?: boolean;
    /** Let headers be clicked to sort (default). */
    sortable?: boolean;
    /** The page sorts on the server: headers with `data-sort` call `onSort` instead of sorting this page. */
    serverSort?: TableServerSort;
    className?: string;
}

export function TableTools({ id, children, toolbar = true, sortable = true, serverSort, className }: TableToolsProps) {
    const messages = useUiMessages();
    const rootRef = useRef<HTMLDivElement>(null);
    const [columns, setColumns] = useState<ColumnInfo[]>([]);
    const [rowCount, setRowCount] = useState(0);
    const [stored, setStored] = useState<StoredTools>(() => readStored(id));
    const [clientSort, setClientSort] = useState<ClientSort | null>(null);
    const originalOrder = useRef<HTMLTableRowElement[] | null>(null);
    const collator = useMemo(
        () => new Intl.Collator(typeof document !== 'undefined' && document.documentElement.lang ? document.documentElement.lang : undefined, { numeric: true, sensitivity: 'base' }),
        [],
    );

    useEffect(() => { setStored(readStored(id)); }, [id]);

    const update = useCallback((patch: Partial<StoredTools>) => {
        setStored((previous) => {
            const next = { ...previous, ...patch };
            writeStored(id, next);
            return next;
        });
    }, [id]);

    const table = useCallback((): HTMLTableElement | null => rootRef.current?.querySelector('table') ?? null, []);

    /* Read the headers and mark the sortable ones. Runs after every render of the
       wrapped page, which is when a table's columns can change. */
    useLayoutEffect(() => {
        const element = table();
        if (!element) return;
        const headerRow = element.tHead?.rows[0];
        const cells = headerRow ? Array.from(headerRow.cells) : [];
        const next = cells.map((cell, index) => {
            const label = visibleLabel(cell);
            return {
                index,
                label,
                sortable: sortable && cell.colSpan === 1 && !cell.querySelector('button, a, input, select') && label !== '',
                serverKey: cell.dataset.sort ?? null,
            };
        });
        setColumns((previous) => (sameColumns(previous, next) ? previous : next));
        const body = element.tBodies[0];
        setRowCount(body ? body.rows.length : 0);

        cells.forEach((cell, index) => {
            const column = next[index];
            const isServer = Boolean(serverSort && column.serverKey);
            if (!column.sortable) {
                delete cell.dataset.sortable;
                cell.removeAttribute('aria-sort');
                return;
            }
            cell.dataset.sortable = '';
            cell.tabIndex = 0;
            cell.setAttribute('role', 'button');
            cell.title = messages.sortBy.replace(':column', column.label);
            const direction = isServer
                ? (serverSort && serverSort.column === column.serverKey ? serverSort.direction : null)
                : (clientSort && clientSort.index === index ? clientSort.direction : null);
            if (direction) cell.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');
            else cell.removeAttribute('aria-sort');
        });
    });

    /* Sort this page's rows in place. React keeps its own order; a new set of rows
       (a new page) arrives in server order and is sorted again by the observer. */
    const applyClientSort = useCallback(() => {
        const element = table();
        const body = element?.tBodies[0];
        if (!element || !body) return;
        const rows = Array.from(body.rows);
        if (rows.length < 2 || rows.some((row) => Array.from(row.cells).some((cell) => cell.colSpan > 1))) return;
        if (!originalOrder.current || originalOrder.current.some((row) => !row.isConnected) || originalOrder.current.length !== rows.length) {
            originalOrder.current = rows;
        }
        if (!clientSort) {
            const original = originalOrder.current;
            if (original.some((row, index) => row !== rows[index])) body.append(...original);
            return;
        }
        const { index, direction } = clientSort;
        const sorted = [...originalOrder.current].sort((a, b) => {
            const result = compareValues(cellValue(a.cells[index]), cellValue(b.cells[index]), collator);
            return direction === 'asc' ? result : -result;
        });
        if (sorted.some((row, position) => row !== rows[position])) body.append(...sorted);
    }, [table, clientSort, collator]);

    useLayoutEffect(() => { applyClientSort(); }, [applyClientSort, rowCount]);

    useEffect(() => {
        const element = table();
        const body = element?.tBodies[0];
        if (!body || typeof MutationObserver === 'undefined') return;
        let frame = 0;
        const observer = new MutationObserver(() => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                setRowCount(body.rows.length);
                applyClientSort();
            });
        });
        observer.observe(body, { childList: true });
        return () => { observer.disconnect(); cancelAnimationFrame(frame); };
    }, [table, applyClientSort, rowCount]);

    // A sort that was asked of this page is dropped when the columns change (another table rendered in the same spot).
    useEffect(() => {
        if (clientSort && !columns[clientSort.index]?.sortable) setClientSort(null);
    }, [columns, clientSort]);

    const toggleSort = useCallback((index: number) => {
        const column = columns[index];
        if (!column?.sortable) return;
        if (serverSort && column.serverKey) {
            const current = serverSort.column === column.serverKey ? serverSort.direction : null;
            serverSort.onSort(column.serverKey, current === 'asc' ? 'desc' : 'asc');
            return;
        }
        setClientSort((previous) => {
            if (!previous || previous.index !== index) return { index, direction: 'asc' };
            if (previous.direction === 'asc') return { index, direction: 'desc' };
            return null;
        });
    }, [columns, serverSort]);

    const headerFromEvent = (event: MouseEvent<HTMLDivElement> | KeyboardEvent<HTMLDivElement>): number | null => {
        const target = event.target as HTMLElement | null;
        const cell = target?.closest<HTMLTableCellElement>('th[data-sortable], td[data-sortable]');
        const element = table();
        if (!cell || !element || !element.tHead?.contains(cell)) return null;
        // A control inside the header (a filter, a checkbox) keeps its own click.
        if (target && target !== cell && target.closest('button, a, input, select, label')) return null;
        return cell.cellIndex;
    };

    const onClick = (event: MouseEvent<HTMLDivElement>) => {
        const index = headerFromEvent(event);
        if (index !== null) toggleSort(index);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const index = headerFromEvent(event);
        if (index === null) return;
        event.preventDefault();
        toggleSort(index);
    };

    const hidden = stored.hidden.filter((index) => index < columns.length);
    const hideableColumns = columns.filter((column) => column.label !== '');
    const showToolbar = toolbar && columns.length >= 3 && rowCount >= 1;
    const customised = hidden.length > 0 || stored.density !== null || stored.sticky !== null;

    const css = hidden.length > 0
        ? hidden.map((index) => `[data-table-tools="${id}"] table:not(table table) > :is(thead, tbody, tfoot) > tr > :nth-child(${index + 1}):not([colspan]){display:none}`).join('\n')
        : '';

    return (
        <div
            ref={rootRef}
            data-table-tools={id}
            data-density={stored.density ?? undefined}
            data-sticky={stored.sticky ? 'true' : undefined}
            className={cx('min-w-0', className)}
            onClick={onClick}
            onKeyDown={onKeyDown}
        >
            {css && <style>{css}</style>}
            {showToolbar && (
                <div role="toolbar" aria-label={messages.tableTools} className="flex flex-wrap items-center justify-end gap-1 border-b border-(--app-border) bg-(--app-surface) px-2 py-1.5 print:hidden">
                    {clientSort && !serverSort && (
                        <span role="status" className="me-auto px-1 text-xs text-(--app-muted-foreground)">{messages.sortedOnPage}</span>
                    )}
                    <Popover className="relative">
                        <PopoverButton as={Button} variant="ghost" size="sm" icon={<Icon path={ICONS.columns} />} className="h-8 text-xs">
                            {messages.columns}
                            {hidden.length > 0 && <span className="ms-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-(--color-on-primary,#fff) tabular-nums">{hidden.length}</span>}
                        </PopoverButton>
                        <PopoverPanel
                            anchor="bottom end"
                            transition
                            className="z-50 w-64 rounded-panel border border-(--app-border) bg-(--app-surface) p-2 text-(--app-foreground) shadow-lg outline-hidden transition duration-100 ease-out [--anchor-gap:6px] data-closed:scale-[0.98] data-closed:opacity-0"
                        >
                            <ul className="max-h-72 space-y-0.5 overflow-y-auto">
                                {hideableColumns.map((column) => {
                                    const isHidden = hidden.includes(column.index);
                                    return (
                                        <li key={column.index}>
                                            <label className="flex min-h-8 cursor-pointer items-center gap-2.5 rounded-control px-2 text-sm hover:bg-(--app-surface-muted)">
                                                <input
                                                    type="checkbox"
                                                    checked={!isHidden}
                                                    onChange={() => update({ hidden: isHidden ? hidden.filter((index) => index !== column.index) : [...hidden, column.index] })}
                                                    className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary"
                                                />
                                                <span className="min-w-0 truncate">{column.label}</span>
                                            </label>
                                        </li>
                                    );
                                })}
                            </ul>
                            <div className="mt-2 flex items-center justify-between gap-2 border-t border-(--app-border) pt-2">
                                <Button variant="ghost" size="xs" disabled={hidden.length === 0} onClick={() => update({ hidden: [] })}>{messages.showAllColumns}</Button>
                                {customised && <Button variant="ghost" size="xs" icon={<Icon path={ICONS.reset} className="h-3.5 w-3.5" />} onClick={() => update({ hidden: [], density: null, sticky: null })}>{messages.resetTable}</Button>}
                            </div>
                        </PopoverPanel>
                    </Popover>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        aria-pressed={stored.density === 'compact'}
                        aria-label={`${messages.density}: ${stored.density === 'compact' ? messages.compact : messages.comfortable}`}
                        title={`${messages.density}: ${stored.density === 'compact' ? messages.compact : messages.comfortable}`}
                        onClick={() => update({ density: stored.density === 'compact' ? 'comfortable' : 'compact' })}
                        icon={<Icon path={stored.density === 'compact' ? ICONS.compact : ICONS.comfortable} />}
                    />
                    <Button
                        variant="ghost"
                        size="icon"
                        className={cx('h-8 w-8', stored.sticky && 'bg-(--app-surface-muted) text-(--app-foreground)')}
                        aria-pressed={stored.sticky === true}
                        aria-label={messages.stickyHeader}
                        title={messages.stickyHeader}
                        onClick={() => update({ sticky: !stored.sticky })}
                        icon={<Icon path={ICONS.pin} />}
                    />
                </div>
            )}
            {children}
        </div>
    );
}
