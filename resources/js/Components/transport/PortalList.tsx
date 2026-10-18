import { Link } from '@inertiajs/react';
import { EmptyState, TableCard, buttonClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@euisis/ui';
import type { ReactNode } from 'react';
import { useTransportLabels } from './labels';

/** A provider-portal list: a "new" action and one linked row per record. */
export default function PortalList<T extends { id: string }>({ rows, columns, createHref, createLabel, editHref, footer }: {
    rows: T[];
    columns: { header: string; cell: (row: T) => ReactNode; className?: string }[];
    createHref: string;
    createLabel: string;
    editHref: (row: T) => string;
    footer?: ReactNode;
}) {
    const { t } = useTransportLabels();

    return (
        <TableCard actions={<Link href={createHref} className={buttonClassName({ variant: 'primary' })}>{createLabel}</Link>} footer={footer}>
            {rows.length === 0 ? <EmptyState title={t('transport.noRecords')} /> : (
                <>
                {/* Phones: one card per record; wider screens: the table. */}
                <ul className="divide-y divide-(--app-border) sm:hidden">
                    {rows.map((row) => (
                        <li key={row.id} className="space-y-2 px-4 py-3">
                            {columns.map((column) => (
                                <div key={column.header} className="flex items-start justify-between gap-3 text-sm">
                                    <span className="shrink-0 text-(--app-muted-foreground)">{column.header}</span>
                                    <span className="min-w-0 text-right">{column.cell(row)}</span>
                                </div>
                            ))}
                            <div className="flex justify-end"><Link href={editHref(row)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('transport.edit')}</Link></div>
                        </li>
                    ))}
                </ul>
                <div className="relative hidden overflow-x-auto sm:block">
                    <table className={tableClassName}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {columns.map((column) => <th key={column.header} className={`${tableHeaderCellClassName} ${column.className ?? ''}`}>{column.header}</th>)}
                                <th className={tableHeaderCellClassName}><span className="sr-only">{t('transport.edit')}</span></th>
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {rows.map((row) => (
                                <tr key={row.id} className={tableRowClassName}>
                                    {columns.map((column) => <td key={column.header} className={`${tableCellClassName} ${column.className ?? ''}`}>{column.cell(row)}</td>)}
                                    <td className={`${tableCellClassName} text-right`}><Link href={editHref(row)} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>{t('transport.edit')}</Link></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                </>
            )}
        </TableCard>
    );
}
