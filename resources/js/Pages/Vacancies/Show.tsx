import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

type Application = {
    id: string;
    application_number: string;
    status: string;
    applied_at: string;
    employee: { name_en: string } | null;
};

type AnnouncementPosition = {
    id: string;
    vacancy_slots: number;
    organization: { name_en: string } | null;
    organization_unit: { name_en: string } | null;
    position: { title_en: string } | null;
    applications: Application[];
};

type Announcement = {
    id: string;
    announcement_number: string;
    title_en: string;
    title_am: string | null;
    description_en: string | null;
    status: string;
    application_opens_at: string | null;
    application_closes_at: string | null;
    eligibility_rules: string[] | null;
    published_at: string | null;
    closed_at: string | null;
    published_by: { name: string } | null;
    positions: AnnouncementPosition[];
    applications: Application[];
};

type Props = {
    announcement: Announcement;
    can: { update: boolean; publish: boolean; close: boolean; delete: boolean; apply: boolean };
    currentEmployeeId: string | null;
    appliedPositionEntryIds: string[];
};

export default function VacanciesShow({ announcement, can, currentEmployeeId, appliedPositionEntryIds }: Props) {
    const { t } = useLocale();

    const totalSlots = announcement.positions.reduce((sum, p) => sum + Number(p.vacancy_slots || 0), 0);
    const totalSelected = announcement.applications.filter(a => ['selected', 'transferred'].includes(a.status)).length;
    const totalAvailable = Math.max(0, totalSlots - totalSelected);

    function apply(positionEntryId: string) {
        if (!currentEmployeeId) return;
        router.post(route('vacancy-applications.store'), {
            vacancy_announcement_position_id: positionEntryId,
            employee_id: currentEmployeeId,
        });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('vacancy-announcements.index')}
                    title={announcement.title_en}
                    description={announcement.announcement_number}
                    actions={
                        <div className="flex gap-2">
                            {can.update && announcement.status === 'draft' && (
                                <Link href={route('vacancy-announcements.edit', announcement.id)} className="inline-flex items-center rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300">
                                    {t('common.edit')}
                                </Link>
                            )}
                            {can.publish && announcement.status === 'draft' && (
                                <button type="button" onClick={() => router.post(route('vacancy-announcements.publish', announcement.id))} className="inline-flex items-center rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700">
                                    {t('vacancies.publishAnnouncement')}
                                </button>
                            )}
                            {can.close && announcement.status === 'published' && (
                                <button type="button" onClick={() => router.post(route('vacancy-announcements.close', announcement.id))} className="inline-flex items-center rounded-lg border border-amber-300 px-3 py-1.5 text-sm font-medium text-amber-600 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-400">
                                    {t('vacancies.closeAnnouncement')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={announcement.title_en} />

            <div className="grid gap-6 lg:grid-cols-[1.5fr_1fr]">
                {/* Left: announcement details + positions */}
                <div className="space-y-6">
                    <section className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900 dark:text-slate-100">{announcement.title_en}</h3>
                                {announcement.title_am && <p className="text-sm text-gray-500 dark:text-slate-400">{announcement.title_am}</p>}
                            </div>
                            <StatusBadge status={announcement.status} />
                        </div>

                        {announcement.description_en && (
                            <p className="mt-4 whitespace-pre-line text-sm text-gray-700 dark:text-slate-300">{announcement.description_en}</p>
                        )}

                        {announcement.eligibility_rules && announcement.eligibility_rules.length > 0 && (
                            <div className="mt-4">
                                <p className="mb-2 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{t('vacancies.eligibilityRules')}</p>
                                <ul className="space-y-1">
                                    {announcement.eligibility_rules.map((rule, index) => (
                                        <li key={index} className="flex items-start gap-2 text-sm text-gray-700 dark:text-slate-300">
                                            <span className="mt-0.5 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-500" />
                                            {rule}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('vacancies.vacancySlots')}</dt>
                                <dd className="mt-1 text-gray-800 dark:text-slate-200">{totalAvailable} / {totalSlots}</dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('vacancies.applicationOpensAt')}</dt>
                                <dd className="mt-1 text-gray-800 dark:text-slate-200">
                                    {announcement.application_opens_at
                                        ? <LocalizedDateDisplay value={announcement.application_opens_at} withTime />
                                        : '-'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('vacancies.applicationClosesAt')}</dt>
                                <dd className="mt-1 text-gray-800 dark:text-slate-200">
                                    {announcement.application_closes_at
                                        ? <LocalizedDateDisplay value={announcement.application_closes_at} withTime />
                                        : '-'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('vacancies.publishedAt')}</dt>
                                <dd className="mt-1 text-gray-800 dark:text-slate-200">
                                    {announcement.published_at
                                        ? <LocalizedDateDisplay value={announcement.published_at} withTime />
                                        : '-'}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    {/* Positions table with apply buttons */}
                    <section className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h4 className="mb-3 text-sm font-semibold text-gray-900 dark:text-slate-100">{t('vacancies.includedPositions')}</h4>
                        <div className="overflow-hidden rounded-lg border border-gray-200 dark:border-slate-700">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-gray-50 dark:bg-slate-950">
                                    <tr>
                                        <th className="px-3 py-2 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{t('positionEstablishments.organization')}</th>
                                        <th className="px-3 py-2 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{t('positionEstablishments.organizationUnit')}</th>
                                        <th className="px-3 py-2 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{t('positionEstablishments.position')}</th>
                                        <th className="px-3 py-2 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{t('vacancies.slotsAvailable')}</th>
                                        {can.apply && <th className="px-3 py-2" />}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {announcement.positions.map(position => {
                                        const selectedCount = position.applications.filter(a => ['selected', 'transferred'].includes(a.status)).length;
                                        const availableSlots = Math.max(0, position.vacancy_slots - selectedCount);
                                        const alreadyApplied = appliedPositionEntryIds.includes(position.id);
                                        const canApplyHere = can.apply && availableSlots > 0 && !alreadyApplied;

                                        return (
                                            <tr key={position.id}>
                                                <td className="px-3 py-2.5 text-gray-700 dark:text-slate-200">{position.organization?.name_en ?? '-'}</td>
                                                <td className="px-3 py-2.5 text-gray-500 dark:text-slate-400">{position.organization_unit?.name_en ?? '-'}</td>
                                                <td className="px-3 py-2.5 text-gray-700 dark:text-slate-200">{position.position?.title_en ?? '-'}</td>
                                                <td className="px-3 py-2.5">
                                                    <span className={`text-sm font-semibold ${availableSlots > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400 dark:text-slate-500'}`}>
                                                        {availableSlots}
                                                        <span className="ml-1 text-xs font-normal text-gray-400 dark:text-slate-500">/ {position.vacancy_slots}</span>
                                                    </span>
                                                </td>
                                                {can.apply && (
                                                    <td className="px-3 py-2.5 text-right">
                                                        {alreadyApplied ? (
                                                            <span className="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-600 dark:bg-blue-950/30 dark:text-blue-400">
                                                                {t('vacancies.applied')}
                                                            </span>
                                                        ) : availableSlots > 0 ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => apply(position.id)}
                                                                className="rounded-lg bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700"
                                                            >
                                                                {t('vacancies.applyForPosition')}
                                                            </button>
                                                        ) : (
                                                            <span className="text-xs text-gray-400 dark:text-slate-500">{t('vacancies.noSlotsRemaining')}</span>
                                                        )}
                                                    </td>
                                                )}
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                {/* Right: applications list */}
                <section className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                            {t('vacancies.applications')}
                        </h3>
                        <span className="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-slate-800 dark:text-slate-300">
                            {announcement.applications.length}
                        </span>
                    </div>

                    {/* Pipeline counts */}
                    {announcement.applications.length > 0 && (
                        <div className="mb-4 grid grid-cols-2 gap-2 text-xs">
                            {(['submitted', 'screened', 'shortlisted', 'selected', 'transferred', 'rejected'] as const).map(status => {
                                const count = announcement.applications.filter(a => a.status === status).length;
                                if (count === 0) return null;
                                return (
                                    <div key={status} className="flex items-center justify-between rounded-lg border border-gray-100 px-2 py-1 dark:border-slate-700">
                                        <StatusBadge status={status} />
                                        <span className="font-semibold text-gray-700 dark:text-slate-200">{count}</span>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {announcement.applications.length === 0 ? (
                        <p className="text-sm text-gray-400 dark:text-slate-500">{t('common.noResults')}</p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {announcement.applications.map(application => (
                                <li key={application.id} className="flex items-center justify-between gap-3">
                                    <Link href={route('vacancy-applications.show', application.id)} className="truncate text-blue-600 hover:underline dark:text-blue-400">
                                        {application.employee?.name_en ?? application.application_number}
                                    </Link>
                                    <StatusBadge status={application.status} />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
