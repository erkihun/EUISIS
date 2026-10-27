import { ReactNode } from 'react';
import {
    createColumnHelper as createFeatureColumnHelper,
    flexRender,
    tableFeatures,
    useTable,
    type CellData,
    type ColumnDef as FeatureColumnDef,
    type RowData,
} from '@tanstack/react-table';
import { router } from '@inertiajs/react';
import EmptyState from '@/Components/EmptyState';
import { AppTableSkeleton } from '@/Components/ui/AppSkeleton';
import ListPagination, { type LaravelPageMeta } from '@/Components/ui/ListPagination';
import { useLocale } from '@/hooks/useLocale';
import { cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@euisis/ui';

/**
 * TanStack Table 9 types every column by the table's feature set. These tables
 * use none (rows are drawn as given; pagination is Laravel's), so one empty set
 * is bound here and pages keep writing ColumnDef<Row> / createColumnHelper<Row>().
 */
const features = tableFeatures({});
type Features = typeof features;

export type ColumnDef<TData extends RowData, TValue extends CellData = CellData> = FeatureColumnDef<Features, TData, TValue>;
export const createColumnHelper = <TData extends RowData>() => createFeatureColumnHelper<Features, TData>();

export type AppDataTableMeta = LaravelPageMeta;

interface AppDataTableProps<TData extends RowData> {
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
 * Server-side data table built on TanStack Table v9, drawn with the shared
 * table classes: header, rows, loading skeleton, empty state and the
 * pagination bar as the card's footer.
 *
 * @example
 * ```tsx
 * <AppDataTable data={employees} columns={columns} meta={meta} filters={filters} routeName="employees.index" />
 * ```
 */
export default function AppDataTable<TData extends RowData>({
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

    const table = useTable({ features, data, columns });

    function goToPage(page: number) {
        if (!routeName) return;
        const clean: Record<string, string> = {};
        Object.entries(filters).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') clean[k] = String(v);
        });
        router.get(route(routeName), { ...clean, page }, { preserveState: true, preserveScroll: true });
    }

    return (
        <div className={cx(tableContainerClassName, className)}>
            {!loading && data.length === 0 ? (
                <EmptyState title={emptyTitle ?? t('common.noResults')} description={emptyDescription} action={emptyAction} />
            ) : (
                <div className="overflow-x-auto">
                    <table className={tableClassName}>
                        <thead className={tableHeadClassName}>
                            {table.getHeaderGroups().map((headerGroup) => (
                                <tr key={headerGroup.id}>
                                    {headerGroup.headers.map((header) => (
                                        <th
                                            key={header.id}
                                            colSpan={header.colSpan}
                                            scope="col"
                                            className={cx(tableHeaderCellClassName, 'text-left')}
                                            style={header.column.columnDef.meta as React.CSSProperties | undefined}
                                        >
                                            {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                                        </th>
                                    ))}
                                </tr>
                            ))}
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {loading ? (
                                <AppTableSkeleton rows={skeletonRows ?? meta?.per_page ?? 8} cols={columns.length} />
                            ) : (
                                table.getRowModel().rows.map((row) => (
                                    <tr key={row.id} className={tableRowClassName}>
                                        {row.getAllCells().map((cell) => (
                                            <td
                                                key={cell.id}
                                                className={tableCellClassName}
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

            <ListPagination meta={meta} onPageChange={routeName ? goToPage : undefined} disabled={loading} />
        </div>
    );
}

/** The pagination bar on its own (kept for existing imports; prefer ListPagination). */
export function AppDataTablePagination({ meta, onPageChange }: { meta: AppDataTableMeta; onPageChange: (page: number) => void }) {
    return <ListPagination meta={meta} onPageChange={onPageChange} bare />;
}
