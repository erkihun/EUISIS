import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

import { buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName } from '@/Components/ui';
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
                                <Link href={route('vacancy-announcements.edit', announcement.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                    {t('common.edit')}
                                </Link>
                            )}
                            {can.publish && announcement.status === 'draft' && (
                                <button type="button" onClick={() => router.post(route('vacancy-announcements.publish', announcement.id))} className={buttonClassName({ variant: 'primary', size: 'sm' })}>
                                    {t('vacancies.publishAnnouncement')}
                                </button>
                            )}
                            {can.close && announcement.status === 'published' && (
                                <button type="button" onClick={() => router.post(route('vacancy-announcements.close', announcement.id))} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
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
                    <section className="rounded-card border border-(--app-border) bg-(--app-surface) p-5">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h3 className="text-lg font-semibold text-(--app-foreground)">{announcement.title_en}</h3>
                                {announcement.title_am && <p className="text-sm text-(--app-muted-foreground)">{announcement.title_am}</p>}
                            </div>
                            <StatusBadge status={announcement.status} />
                        </div>

                        {announcement.description_en && (
                            <p className="mt-4 whitespace-pre-line text-sm text-(--app-foreground)">{announcement.description_en}</p>
                        )}

                        {announcement.eligibility_rules && announcement.eligibility_rules.length > 0 && (
                            <div className="mt-4">
                                <p className="mb-2 text-xs font-semibold uppercase text-(--app-muted-foreground)">{t('vacancies.eligibilityRules')}</p>
                                <ul className="space-y-1">
                                    {announcement.eligibility_rules.map((rule, index) => (
                                        <li key={index} className="flex items-start gap-2 text-sm text-(--app-foreground)">
                                            <span className="mt-0.5 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-500" />
                                            {rule}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-xs font-medium text-(--app-muted-foreground)">{t('vacancies.vacancySlots')}</dt>
                                <dd className="mt-1 text-(--app-foreground)">{totalAvailable} / {totalSlots}</dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-(--app-muted-foreground)">{t('vacancies.applicationOpensAt')}</dt>
                                <dd className="mt-1 text-(--app-foreground)">
                                    {announcement.application_opens_at
                                        ? <LocalizedDateDisplay value={announcement.application_opens_at} withTime />
                                        : '-'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-(--app-muted-foreground)">{t('vacancies.applicationClosesAt')}</dt>
                                <dd className="mt-1 text-(--app-foreground)">
                                    {announcement.application_closes_at
                                        ? <LocalizedDateDisplay value={announcement.application_closes_at} withTime />
                                        : '-'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-(--app-muted-foreground)">{t('vacancies.publishedAt')}</dt>
                                <dd className="mt-1 text-(--app-foreground)">
                                    {announcement.published_at
                                        ? <LocalizedDateDisplay value={announcement.published_at} withTime />
                                        : '-'}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    {/* Positions table with apply buttons */}
                    <section className="rounded-card border border-(--app-border) bg-(--app-surface) p-5">
                        <h4 className="mb-3 text-sm font-semibold text-(--app-foreground)">{t('vacancies.includedPositions')}</h4>
                        <div className="overflow-hidden rounded-lg border border-(--app-border)">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('positionEstablishments.organization')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('positionEstablishments.organizationUnit')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('positionEstablishments.position')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('vacancies.slotsAvailable')}</th>
                                        {can.apply && <th className={cx(tableHeaderCellClassName, 'text-left')} />}
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {announcement.positions.map(position => {
                                        const selectedCount = position.applications.filter(a => ['selected', 'transferred'].includes(a.status)).length;
                                        const availableSlots = Math.max(0, position.vacancy_slots - selectedCount);
                                        const alreadyApplied = appliedPositionEntryIds.includes(position.id);

                                        return (
                                            <tr key={position.id}>
                                                <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>{position.organization?.name_en ?? '-'}</td>
                                                <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{position.organization_unit?.name_en ?? '-'}</td>
                                                <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>{position.position?.title_en ?? '-'}</td>
                                                <td className={tableCellClassName}>
                                                    <span className={`text-sm font-semibold ${availableSlots > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-(--app-muted-foreground)'}`}>
                                                        {availableSlots}
                                                        <span className="ml-1 text-xs font-normal text-(--app-muted-foreground)">/ {position.vacancy_slots}</span>
                                                    </span>
                                                </td>
                                                {can.apply && (
                                                    <td className={cx(tableCellClassName, 'text-right')}>
                                                        {alreadyApplied ? (
                                                            <span className="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-primary dark:bg-blue-950/30 dark:text-primary">
                                                                {t('vacancies.applied')}
                                                            </span>
                                                        ) : availableSlots > 0 ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => apply(position.id)}
                                                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
                                                            >
                                                                {t('vacancies.applyForPosition')}
                                                            </button>
                                                        ) : (
                                                            <span className="text-xs text-(--app-muted-foreground)">{t('vacancies.noSlotsRemaining')}</span>
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
                <section className="rounded-card border border-(--app-border) bg-(--app-surface) p-5">
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="font-semibold text-(--app-foreground)">
                            {t('vacancies.applications')}
                        </h3>
                        <span className="rounded-full bg-(--app-surface-muted) px-2.5 py-0.5 text-xs font-medium text-(--app-muted-foreground)">
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
                                    <div key={status} className="flex items-center justify-between rounded-lg border border-(--app-border) px-2 py-1">
                                        <StatusBadge status={status} />
                                        <span className="font-semibold text-(--app-foreground)">{count}</span>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {announcement.applications.length === 0 ? (
                        <p className="text-sm text-(--app-muted-foreground)">{t('common.noResults')}</p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {announcement.applications.map(application => (
                                <li key={application.id} className="flex items-center justify-between gap-3">
                                    <Link href={route('vacancy-applications.show', application.id)} className="truncate text-primary hover:underline dark:text-primary">
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
