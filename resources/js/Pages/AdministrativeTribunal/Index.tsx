import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState, type JSX } from 'react';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

import { FilterField, FilterPanel, ListPagination, Select, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName, type LaravelPageMeta } from '@/Components/ui';
type TribunalCase = {
    id: string; case_number: string; status: string; hearing_date: string | null; decision_date: string | null;
    court_case_number: string | null;
    grievance: {
        reference_number: string; subject: string;
        organization: { name_en: string; name_am: string | null } | null;
        category: { name_en: string; name_am: string | null } | null;
    } | null;
    assigned_to_user: { name: string } | null;
};

type Props = {
    cases: LaravelPageMeta & { data: TribunalCase[] };
    filters: { status?: string };
    statuses: string[];
};

export default function TribunalIndex({ cases, filters, statuses }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';
    const [status, setStatus] = useState(filters.status ?? '');

    return (
        <AuthenticatedLayout header={<PageHeader title={t('grievances.tribunalCases')} actions={(
            <Link href={route('tribunal-cases.letters.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('courtCases.letters.title')}</Link>
        )} />}>
            <Head title={t('grievances.tribunalCases')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('tribunal-cases.index'), status ? { status } : {}, { preserveState: true })}
                    onReset={() => router.get(route('tribunal-cases.index'))}
                    hasActiveFilters={Boolean(filters.status)}
                >
                    <FilterField label={t('grievances.status')}>
                        <Select value={status} onChange={e => setStatus(e.target.value)}>
                            <option value="">{t('common.allStatuses')}</option>
                            {statuses.map(s => (
                                <option key={s} value={s}>{t(`grievances.tribunalStatus${s.charAt(0).toUpperCase() + s.slice(1)}` as never)}</option>
                            ))}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[t('grievances.caseNumber'), t('grievances.grievance'), t('grievances.organization'), t('grievances.hearingDate'), t('grievances.decisionDate'), t('grievances.status')].map(h => (
                                    <th key={h} className={cx(tableHeaderCellClassName, 'text-left whitespace-nowrap')}>{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {cases.data.map(c => (
                                <tr key={c.id} className={tableRowClassName}>
                                    <td className={tableCellClassName}>
                                        <Link href={route('tribunal-cases.show', c.id)} className="font-mono text-primary hover:underline dark:text-primary">{c.case_number}</Link>
                                        {c.court_case_number && <span className="block font-mono text-xs text-(--app-muted-foreground)">{c.court_case_number}</span>}
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-(--app-foreground) max-w-xs truncate')}>
                                        {c.grievance?.subject ?? '-'}
                                        <div className="text-xs text-(--app-muted-foreground)">{c.grievance?.reference_number}</div>
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{(am ? c.grievance?.organization?.name_am : null) ?? c.grievance?.organization?.name_en ?? '-'}</td>
                                    <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                        {c.hearing_date ? <LocalizedDateDisplay value={c.hearing_date} /> : '-'}
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                        {c.decision_date ? <LocalizedDateDisplay value={c.decision_date} /> : '-'}
                                    </td>
                                    <td className={tableCellClassName}><StatusBadge status={c.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                    {cases.data.length === 0 && (
                        <EmptyState description={filters.status ? t('common.noMatchingRecords') : undefined} />
                    )}
                    <ListPagination meta={cases} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
