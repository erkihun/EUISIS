import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Application = {
    id: string;
    application_number: string;
    status: string;
    applied_at: string;
    employee: { name_en: string } | null;
    announcement: { title_en: string } | null;
    positionEntry: {
        organization: { name_en: string } | null;
        position: { title_en: string } | null;
    } | null;
};

type Meta = {
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

type Props = {
    applications: { data: Application[]; meta: Meta };
    filters: { vacancy_announcement_id?: string; status?: string };
};

const STATUSES = ['submitted', 'screened', 'shortlisted', 'selected', 'rejected', 'withdrawn', 'transferred'] as const;

export default function VacancyApplicationsIndex({ applications, filters }: Props) {
    const { t } = useLocale();

    function applyFilter(status: string) {
        router.get(route('vacancy-applications.index'), { ...filters, status: status || undefined }, { preserveState: true, preserveScroll: true });
    }

    function goToPage(page: number) {
        router.get(route('vacancy-applications.index'), { ...filters, page }, { preserveState: true, preserveScroll: true });
    }

    const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('vacancies.applications')} />}
        >
            <Head title={t('vacancies.applications')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <select
                        className={inputCls}
                        value={filters.status ?? ''}
                        onChange={e => applyFilter(e.target.value)}
                    >
                        <option value="">{t('common.status')} — {t('common.all')}</option>
                        {STATUSES.map(s => (
                            <option key={s} value={s}>
                                {t(`vacancies.appStatus${s.charAt(0).toUpperCase()}${s.slice(1)}` as never)}
                            </option>
                        ))}
                    </select>
                    {filters.status && (
                        <button type="button" onClick={() => applyFilter('')} className="text-xs text-blue-600 hover:underline dark:text-blue-400">
                            {t('common.clear')}
                        </button>
                    )}
                    <span className="ml-auto text-sm text-gray-500 dark:text-slate-400">
                        {applications.meta.from ?? 0}–{applications.meta.to ?? 0} / {applications.meta.total}
                    </span>
                </div>

                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {[
                                    t('vacancies.applicationNumber'),
                                    t('employees.employee'),
                                    t('vacancies.announcement'),
                                    t('positionEstablishments.position'),
                                    t('positionEstablishments.organization'),
                                    t('vacancies.appliedAt'),
                                    t('common.status'),
                                ].map(h => (
                                    <th key={h} className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {applications.data.map(a => (
                                <tr key={a.id} className="hover:bg-gray-50 dark:hover:bg-slate-800">
                                    <td className="px-4 py-3">
                                        <Link href={route('vacancy-applications.show', a.id)} className="font-mono text-blue-600 hover:underline dark:text-blue-400">
                                            {a.application_number}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-gray-700 dark:text-slate-200">{a.employee?.name_en ?? '—'}</td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{a.announcement?.title_en ?? '—'}</td>
                                    <td className="px-4 py-3 text-gray-700 dark:text-slate-200">{a.positionEntry?.position?.title_en ?? '—'}</td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{a.positionEntry?.organization?.name_en ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={a.applied_at} /></td>
                                    <td className="px-4 py-3"><StatusBadge status={a.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {applications.data.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">{t('common.noResults')}</p>
                    )}
                </div>

                {applications.meta.last_page > 1 && (
                    <div className="flex items-center justify-end gap-2 text-sm">
                        <button
                            type="button"
                            disabled={applications.meta.current_page <= 1}
                            onClick={() => goToPage(applications.meta.current_page - 1)}
                            className="rounded-lg border border-gray-300 px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700"
                        >
                            {t('common.previous')}
                        </button>
                        <span className="text-gray-500 dark:text-slate-400">
                            {t('common.page')} {applications.meta.current_page} {t('common.of')} {applications.meta.last_page}
                        </span>
                        <button
                            type="button"
                            disabled={applications.meta.current_page >= applications.meta.last_page}
                            onClick={() => goToPage(applications.meta.current_page + 1)}
                            className="rounded-lg border border-gray-300 px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700"
                        >
                            {t('common.next')}
                        </button>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
