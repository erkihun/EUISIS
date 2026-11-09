import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { compactInputCls, panelCls, primaryBtn } from '@/Components/dailyActivity/helpers';
import { CaseStatusBadge, employeeLabel, useDates, type CaseRow } from '@/Components/discipline/shared';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[] };

type Props = {
    cases: Paginated<CaseRow>;
    filters: { status?: string; track?: string; search?: string };
    statuses: string[];
    canCreate: boolean;
};

/** Disciplinary cases the user may see: organization scope or a seat on the case; never their own. */
export default function DisciplineIndex({ cases, filters, statuses, canCreate }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const { date } = useDates();
    const [search, setSearch] = useState(filters.search ?? '');

    function apply(next: Partial<Props['filters']>) {
        router.get(route('discipline.cases.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        apply({ search });
    }

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('discipline.title')} description={t('discipline.description')}
                actions={canCreate ? <Link href={route('discipline.cases.create')} className={primaryBtn}>{t('discipline.newCase')}</Link> : undefined} />}
        >
            <Head title={t('discipline.title')} />
            <div className="space-y-3">
                <form onSubmit={submit} className={`${panelCls} flex flex-wrap items-end gap-2 p-3`}>
                    <input aria-label={t('discipline.search')} placeholder={t('discipline.search')} className={`${compactInputCls} min-w-56 flex-1`} value={search} onChange={(e) => setSearch(e.target.value)} />
                    <select aria-label={t('discipline.allStatuses')} className={compactInputCls} value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined })}>
                        <option value="">{t('discipline.allStatuses')}</option>
                        {statuses.map((s) => <option key={s} value={s}>{t(`discipline.statuses.${s}`)}</option>)}
                    </select>
                    <select aria-label={t('discipline.allTracks')} className={compactInputCls} value={filters.track ?? ''} onChange={(e) => apply({ track: e.target.value || undefined })}>
                        <option value="">{t('discipline.allTracks')}</option>
                        {['expedited', 'formal'].map((s) => <option key={s} value={s}>{t(`discipline.tracks.${s}`)}</option>)}
                    </select>
                </form>

                {cases.data.length === 0 ? (
                    <p className={`${panelCls} p-4 text-sm text-gray-500 dark:text-slate-400`}>{t('discipline.empty')}</p>
                ) : (
                    <div className={`${panelCls} overflow-x-auto`}>
                        <table className="min-w-full text-sm">
                            <thead className="text-left text-xs text-gray-500 dark:text-slate-400">
                                <tr>
                                    <th className="px-4 py-2 font-medium">{t('discipline.caseNumber')}</th>
                                    <th className="px-4 py-2 font-medium">{t('discipline.accused')}</th>
                                    <th className="px-4 py-2 font-medium">{t('discipline.organization')}</th>
                                    <th className="px-4 py-2 font-medium">{t('discipline.chargedAt')}</th>
                                    <th className="px-4 py-2 font-medium">{t('discipline.nextDeadline')}</th>
                                    <th className="px-4 py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                {cases.data.map((c) => (
                                    <tr key={c.id}>
                                        <td className="px-4 py-2">
                                            <Link href={route('discipline.cases.show', c.id)} className="font-medium text-gray-900 hover:underline dark:text-slate-100">{c.case_number ?? '—'}</Link>
                                            <div className="mt-1 flex flex-wrap gap-1"><CaseStatusBadge status={c.status} /></div>
                                            <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{t(`discipline.tracks.${c.track}`)}</p>
                                        </td>
                                        <td className="px-4 py-2">{employeeLabel(c.accused, locale)}</td>
                                        <td className="px-4 py-2">{c.organization?.name ?? '—'}</td>
                                        <td className="px-4 py-2">{date(c.charged_at)}</td>
                                        <td className={`px-4 py-2 ${c.next_deadline?.overdue ? 'text-red-700 dark:text-red-400' : ''}`}>
                                            {c.next_deadline ? `${c.next_deadline.label} — ${date(c.next_deadline.due_at)}` : '—'}
                                        </td>
                                        <td className="px-4 py-2 text-right"><Link href={route('discipline.cases.show', c.id)} className="text-xs font-medium hover:underline">{t('discipline.open')}</Link></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {cases.links.length > 3 && (
                    <nav className="flex flex-wrap gap-1" aria-label="pagination">
                        {cases.links.map((link, i) => (
                            link.url
                                ? <Link key={i} href={link.url} preserveState className={`rounded-control px-2 py-1 text-xs ${link.active ? 'bg-gray-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-gray-600 dark:text-slate-300'}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                                : <span key={i} className="px-2 py-1 text-xs text-gray-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </nav>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
