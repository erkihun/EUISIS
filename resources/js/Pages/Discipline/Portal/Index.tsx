import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { panelCls } from '@/Components/dailyActivity/helpers';
import { CaseStatusBadge, useDates, type CaseRow } from '@/Components/discipline/shared';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

/** My Portal → My Disciplinary Cases. Charged cases only; drafts are internal. */
export default function MyDisciplinaryCases({ cases }: { cases: CaseRow[] }): JSX.Element {
    const { t } = useLocale();
    const { date } = useDates();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('discipline.myTitle')} description={t('discipline.myDescription')} />}>
            <Head title={t('discipline.myTitle')} />
            {cases.length === 0 ? (
                <p className={`${panelCls} p-4 text-sm text-gray-500 dark:text-slate-400`}>{t('discipline.myEmpty')}</p>
            ) : (
                <ul className={`${panelCls} divide-y divide-gray-100 dark:divide-slate-800`}>
                    {cases.map((c) => (
                        <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                            <div className="min-w-0">
                                <Link href={route('employee.discipline.show', c.id)} className="text-sm font-medium text-gray-900 hover:underline dark:text-slate-100">{c.case_number}</Link>
                                <p className="text-xs text-gray-500 dark:text-slate-400">{t(`discipline.tracks.${c.track}`)} · {t('discipline.chargedAt')}: {date(c.charged_at)}</p>
                                {c.next_deadline && <p className="text-xs text-gray-500 dark:text-slate-400">{c.next_deadline.label}: {date(c.next_deadline.due_at)}</p>}
                            </div>
                            <CaseStatusBadge status={c.status} />
                        </li>
                    ))}
                </ul>
            )}
        </AuthenticatedLayout>
    );
}
