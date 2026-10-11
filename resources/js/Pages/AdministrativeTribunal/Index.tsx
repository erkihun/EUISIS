import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

import { cx, selectClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
type TribunalCase = {
    id: string; case_number: string; status: string; hearing_date: string | null; decision_date: string | null;
    grievance: {
        reference_number: string; subject: string;
        organization: { name_en: string; name_am: string | null } | null;
        category: { name_en: string; name_am: string | null } | null;
    } | null;
    assigned_to_user: { name: string } | null;
};

type Props = {
    cases: { data: TribunalCase[]; links: unknown[] };
    filters: { status?: string };
    statuses: string[];
};

const inputCls = selectClassName;

export default function TribunalIndex({ cases, filters, statuses }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    return (
        <AuthenticatedLayout header={<PageHeader title={t('grievances.tribunalCases')} />}>
            <Head title={t('grievances.tribunalCases')} />

            <div className="space-y-4">
                <div className="flex items-center gap-2">
                    <select className={inputCls} value={filters.status ?? ''} onChange={e => router.get(route('tribunal-cases.index'), e.target.value ? { status: e.target.value } : {}, { preserveState: true })}>
                        <option value="">{t('grievances.filterByStatus')} — {t('common.all')}</option>
                        {statuses.map(s => (
                            <option key={s} value={s}>{t(`grievances.tribunalStatus${s.charAt(0).toUpperCase() + s.slice(1)}` as never)}</option>
                        ))}
                    </select>
                </div>

                <div className="overflow-hidden rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
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
                                        <Link href={route('tribunal-cases.show', c.id)} className="font-mono text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">{c.case_number}</Link>
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)] max-w-xs truncate')}>
                                        {c.grievance?.subject ?? '-'}
                                        <div className="text-xs text-[color:var(--app-muted-foreground)]">{c.grievance?.reference_number}</div>
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>{(am ? c.grievance?.organization?.name_am : null) ?? c.grievance?.organization?.name_en ?? '-'}</td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                        {c.hearing_date ? <LocalizedDateDisplay value={c.hearing_date} /> : '-'}
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                        {c.decision_date ? <LocalizedDateDisplay value={c.decision_date} /> : '-'}
                                    </td>
                                    <td className={tableCellClassName}><StatusBadge status={c.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {cases.data.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-[color:var(--app-muted-foreground)]">{t('common.noResults')}</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
