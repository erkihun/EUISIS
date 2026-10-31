import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import { EyeIcon, SearchIcon } from '@/Components/Icons';
import {
    badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useRespondentText } from '@/court/i18n/useRespondentText';
import type { RespondentMyCasesProps } from '@/court/types/respondent';
import { CASE_STATUS_TONES, useStatusLabels } from './Partials/status';

/** The cases this respondent has opened, newest view first, each with its state and type. */
export default function RespondentMyCases({ cases }: RespondentMyCasesProps) {
    const { t } = useRespondentText();
    const { caseStatus } = useStatusLabels();
    const title = t('cm.respondent.my_cases');

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} actions={(
                <Link href={route('court.respondent.case.search')} className={buttonClassName({})}><SearchIcon className="h-4 w-4" />{t('cm.respondent.find_case')}</Link>
            )} />
        )}>
            <Head title={title} />

            {cases.length === 0 ? (
                <EmptyState title={t('cm.cases.table.no_cases_viewed_yet')} />
            ) : (
                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cm.cases.case_number')}</th>
                                    {/* The Blade header said "Case title" over the case type; the column shows the type. */}
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cm.cases.case_type')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cm.app.Actions')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {cases.map((item) => (
                                    <tr key={item.id} className={tableRowClassName}>
                                        <td className={tableCellClassName}>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-mono font-semibold text-(--app-foreground)">{item.case_number}</span>
                                                <span className={badgeClassName(CASE_STATUS_TONES[item.status] ?? 'neutral')}>{caseStatus(item.status)}</span>
                                            </div>
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{item.case_type || '—'}</td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            <Link href={item.show_url} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                <EyeIcon className="h-4 w-4" />{t('cm.respondent.view_case_details')}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
