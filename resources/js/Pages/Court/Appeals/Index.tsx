import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import {
    ListPagination, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { AppealsIndexProps } from '@/court/types/misc';

const TONES: Record<string, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
    draft: 'neutral', submitted: 'info', under_review: 'warning', allowed: 'success', dismissed: 'danger', rejected: 'danger',
};

/** The appeals register: each appeal with its case, title, state and who decided it. */
export default function AppealsIndex({ appeals, canCreate }: AppealsIndexProps) {
    const { t } = useLocale();
    const title = t('cm.app.Appeals');
    const statusLabel = (status: string) => status.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} actions={canCreate ? <Link href={route('court.appeals.create')} className={buttonClassName({})}>{t('court.appeals.newAppeal')}</Link> : undefined} />
        )}>
            <Head title={title} />

            <div className={tableContainerClassName}>
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[t('court.appeals.number'), t('cm.cases.table.case_number'), t('cm.json.Title'), t('cm.json.Status'), t('court.appeals.decidedBy'), t('cm.cases.table.actions')].map((heading) => (
                                    <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {appeals.data.map((appeal) => (
                                <tr key={appeal.id} className={tableRowClassName}>
                                    <td className={cx(tableCellClassName, 'font-mono font-semibold text-(--app-foreground)')}>{appeal.appeal_number}</td>
                                    <td className={cx(tableCellClassName, 'font-mono text-(--app-muted-foreground)')}>{appeal.case_number}</td>
                                    <td className={cx(tableCellClassName, 'max-w-md')}>{appeal.title ?? '-'}</td>
                                    <td className={tableCellClassName}><span className={badgeClassName(TONES[appeal.status] ?? 'neutral')}>{statusLabel(appeal.status)}</span></td>
                                    <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{appeal.decided_by ?? '-'}</td>
                                    <td className={tableCellClassName}>
                                        <Link href={route('court.appeals.show', appeal.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('court.appeals.open')}</Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {appeals.data.length === 0 && <EmptyState title={t('court.appeals.empty')} />}
                <ListPagination meta={appeals} />
            </div>
        </AuthenticatedLayout>
    );
}
