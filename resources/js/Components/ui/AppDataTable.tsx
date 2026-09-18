import { ReactNode } from 'react';
import {
    useReactTable,
    getCoreRowModel,
    flexRender,
    type ColumnDef,
    type Table,
} from '@tanstack/react-table';
import { router } from '@inertiajs/react';
import EmptyState from '@/Components/EmptyState';
import { AppTableSkeleton } from '@/Components/ui/AppSkeleton';
import { useLocale } from '@/hooks/useLocale';

export type { ColumnDef };
export { createColumnHelper } from '@tanstack/react-table';

export interface AppDataTableMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

interface AppDataTableProps<TData> {
    /** Row data. */
    data: TData[];
    /** TanStack column definitions. */
    columns: ColumnDef<TData, any>[];
    /** Pagination metadata from Laravel paginator. */
    meta?: AppDataTableMeta;
    /** Active filter values — spread into pagination router.get calls. */
    filters?: Record<string, string | number | undefined | null>;
    /** Inertia route name used for page navigation. Required when `meta` is provided. */
    routeName?: string;
    /** Show skeleton rows while loading. */
    loading?: boolean;
    /** Rows per skeleton when loading. Defaults to per_page or 8. */
    skeletonRows?: number;
    /** Text shown in the empty state. */
    emptyTitle?: string;
    emptyDescription?: string;
    emptyAction?: ReactNode;
    /** Extra classes on the outer wrapper. */
    className?: string;
}

/**
 * Reusable server-side data table built on TanStack Table v8.
 *
 * Handles:
 * - consistent thead/tbody/tr/td styling
 * - loading skeleton (replaces tbody content)
 * - empty state
 * - pagination (Inertia router.get)
 *
 * @example
 * ```tsx
 * const columns: ColumnDef<Employee>[] = [
 *   { accessorKey: 'full_name', header: 'Name' },
 *   { accessorKey: 'status', header: 'Status', cell: ({ getValue }) => <Badge>{getValue()}</Badge> },
 *   { id: 'actions', header: '', cell: ({ row }) => <AppActionMenu items={[...]} /> },
 * ];
 *
 * <AppDataTable data={employees} columns={columns} meta={meta} filters={filters} routeName="employees.index" />
 * ```
 */
export default function AppDataTable<TData>({
    data,
    columns,
    meta,
    filters = {},
    routeName,
    loading = false,
    skeletonRows,
    emptyTitle,
    emptyDescription,
    emptyAction,
    className = '',
}: AppDataTableProps<TData>) {
    const { t } = useLocale();

    const table = useReactTable({
        data,
        columns,
        getCoreRowModel: getCoreRowModel(),
        manualPagination: true,
        pageCount: meta?.last_page ?? 1,
    });

    const colCount = columns.length;
    const effectiveSkeletonRows = skeletonRows ?? meta?.per_page ?? 8;

    function goToPage(page: number) {
        if (!routeName) return;
        const clean: Record<string, string> = {};
        Object.entries(filters).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') clean[k] = String(v);
        });
        router.get(route(routeName), { ...clean, page }, { preserveState: true });
    }

    return (
        <div className={`space-y-3 ${className}`}>
            {/* Table card */}
            <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                {!loading && data.length === 0 ? (
                    <EmptyState
                        title={emptyTitle ?? t('common.noResults')}
                        description={emptyDescription}
                        action={emptyAction}
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                {table.getHeaderGroups().map((headerGroup) => (
                                    <tr
                                        key={headerGroup.id}
                                        className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50"
                                    >
                                        {headerGroup.headers.map((header) => (
                                            <th
                                                key={header.id}
                                                colSpan={header.colSpan}
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400"
                                                style={header.column.columnDef.meta as React.CSSProperties | undefined}
                                            >
                                                {header.isPlaceholder
                                                    ? null
                                                    : flexRender(header.column.columnDef.header, header.getContext())}
                                            </th>
                                        ))}
                                    </tr>
                                ))}
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                {loading ? (
                                    <AppTableSkeleton rows={effectiveSkeletonRows} cols={colCount} />
                                ) : (
                                    table.getRowModel().rows.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="hover:bg-gray-50 dark:hover:bg-slate-800/40"
                                        >
                                            {row.getVisibleCells().map((cell) => (
                                                <td
                                                    key={cell.id}
                                                    className="px-4 py-3 text-gray-700 dark:text-slate-300"
                                                    style={cell.column.columnDef.meta as React.CSSProperties | undefined}
                                                >
                                                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                                </td>
                                            ))}
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* Pagination */}
            {meta && meta.last_page > 1 && (
                <AppDataTablePagination meta={meta} onPageChange={goToPage} />
            )}
        </div>
    );
}

/** Standalone pagination row — can be used outside AppDataTable too. */
export function AppDataTablePagination({
    meta,
    onPageChange,
}: {
    meta: AppDataTableMeta;
    onPageChange: (page: number) => void;
}) {
    const { t } = useLocale();
    const { current_page, last_page, total, per_page } = meta;

    const start = (current_page - 1) * per_page + 1;
    const end = Math.min(current_page * per_page, total);

    // Show at most 7 page buttons; ellipsis beyond that
    const pages = buildPageRange(current_page, last_page);

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 px-1 text-sm text-gray-600 dark:text-slate-400">
            <p className="text-xs">
                {start}–{end} / {total} {t('common.results')}
            </p>

            <div className="flex items-center gap-1">
                <button
                    onClick={() => onPageChange(current_page - 1)}
                    disabled={current_page <= 1}
                    className="rounded px-2 py-1 hover:bg-gray-100 disabled:pointer-events-none disabled:opacity-40 dark:hover:bg-slate-800"
                    aria-label={t('common.previous')}
                >
                    ‹
                </button>

                {pages.map((p, i) =>
                    p === '...' ? (
                        <span key={`ellipsis-${i}`} className="px-1">
                            …
                        </span>
                    ) : (
                        <button
                            key={p}
                            onClick={() => onPageChange(Number(p))}
                            className={[
                                'min-w-[2rem] rounded px-2 py-1 font-medium transition-colors',
                                Number(p) === current_page
                                    ? 'bg-[color:var(--color-primary)] text-white'
                                    : 'hover:bg-gray-100 dark:hover:bg-slate-800',
                            ].join(' ')}
                            aria-current={Number(p) === current_page ? 'page' : undefined}
                        >
                            {p}
                        </button>
                    ),
                )}

                <button
                    onClick={() => onPageChange(current_page + 1)}
                    disabled={current_page >= last_page}
                    className="rounded px-2 py-1 hover:bg-gray-100 disabled:pointer-events-none disabled:opacity-40 dark:hover:bg-slate-800"
                    aria-label={t('common.next')}
                >
                    ›
                </button>
            </div>
        </div>
    );
}

/** Build a compact page range with ellipsis, e.g. [1, '...', 4, 5, 6, '...', 20]. */
function buildPageRange(current: number, total: number): (number | '...')[] {
    if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);

    const delta = 2;
    const range: (number | '...')[] = [];
    const left = Math.max(2, current - delta);
    const right = Math.min(total - 1, current + delta);

    range.push(1);
    if (left > 2) range.push('...');
    for (let i = left; i <= right; i++) range.push(i);
    if (right < total - 1) range.push('...');
    range.push(total);

    return range;
}
