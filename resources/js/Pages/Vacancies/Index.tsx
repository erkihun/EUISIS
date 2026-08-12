import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

type Announcement = {
    id: string;
    announcement_number: string;
    title_en: string;
    title_am: string | null;
    status: string;
    application_closes_at: string | null;
    applications_count: number;
    positions: Array<{
        id: string;
        vacancy_slots: number;
        organization: { name_en: string; name_am: string | null } | null;
        position: { title_en: string; title_am: string | null } | null;
    }>;
};

type Props = {
    announcements: { data: Announcement[]; links: unknown[] };
    filters: { status?: string };
    can: { create: boolean };
};

const STATUSES = ['draft', 'published', 'closed', 'cancelled'] as const;

function DeadlineBadge({ closesAt, t }: { closesAt: string | null; t: (k: never) => string }): JSX.Element | null {
    if (!closesAt) return null;
    const days = Math.ceil((new Date(closesAt).getTime() - Date.now()) / 86400000);
    if (days < 0) return null;
    if (days === 0) return <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-300">{t('vacancies.closingToday' as never)}</span>;
    if (days <= 3) return <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">{days} {t('vacancies.daysLeft' as never)}</span>;
    return null;
}

export default function VacanciesIndex({ announcements, filters, can }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';

    function applyStatus(status: string) {
        router.get(route('vacancy-announcements.index'), status ? { status } : {}, { preserveState: true });
    }

    const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('vacancies.announcements')}
                    actions={
                        can.create ? (
                            <Link href={route('vacancy-announcements.create')} className="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                {t('vacancies.createAnnouncement')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('vacancies.announcements')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <select className={inputCls} value={filters.status ?? ''} onChange={e => applyStatus(e.target.value)}>
                        <option value="">{t('common.status')} — {t('common.all')}</option>
                        {STATUSES.map(s => (
                            <option key={s} value={s}>
                                {t(`vacancies.status${s.charAt(0).toUpperCase()}${s.slice(1)}` as never)}
                            </option>
                        ))}
                    </select>
                    {filters.status && (
                        <button type="button" onClick={() => applyStatus('')} className="text-xs text-blue-600 hover:underline dark:text-blue-400">
                            {t('common.clear')}
                        </button>
                    )}
                </div>

                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {[
                                    t('vacancies.announcementNumber'),
                                    t('vacancies.titleEn'),
                                    t('vacancies.includedPositions'),
                                    t('vacancies.applications'),
                                    t('vacancies.applicationClosesAt'),
                                    t('common.status'),
                                ].map((heading) => (
                                    <th key={heading} className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{heading}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {announcements.data.map((announcement) => {
                                const totalSlots = announcement.positions.reduce((sum, p) => sum + Number(p.vacancy_slots || 0), 0);
                                const positionSummary = announcement.positions
                                    .slice(0, 2)
                                    .map(p => (useAmharic ? p.position?.title_am : p.position?.title_en) ?? p.position?.title_en ?? '-')
                                    .join(', ');

                                return (
                                    <tr key={announcement.id} className="hover:bg-gray-50 dark:hover:bg-slate-800">
                                        <td className="px-4 py-3">
                                            <Link href={route('vacancy-announcements.show', announcement.id)} className="font-mono text-blue-600 hover:underline dark:text-blue-400">
                                                {announcement.announcement_number}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-gray-700 dark:text-slate-200">
                                            {(useAmharic ? announcement.title_am : null) ?? announcement.title_en}
                                        </td>
                                        <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                            {positionSummary || '-'}
                                            {announcement.positions.length > 2 && (
                                                <span className="ml-1 text-xs text-gray-400"> +{announcement.positions.length - 2}</span>
                                            )}
                                            <span className="ml-2 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500 dark:bg-slate-800 dark:text-slate-300">
                                                {totalSlots} {t('vacancies.vacancySlots')}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`font-semibold ${announcement.applications_count > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400 dark:text-slate-500'}`}>
                                                {announcement.applications_count}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-col gap-1">
                                                <span className="text-gray-500 dark:text-slate-400">
                                                    {announcement.application_closes_at
                                                        ? <LocalizedDateDisplay value={announcement.application_closes_at} />
                                                        : '-'}
                                                </span>
                                                {announcement.status === 'published' && (
                                                    <DeadlineBadge closesAt={announcement.application_closes_at} t={t as never} />
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3"><StatusBadge status={announcement.status} /></td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                    {announcements.data.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">{t('common.noResults')}</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
