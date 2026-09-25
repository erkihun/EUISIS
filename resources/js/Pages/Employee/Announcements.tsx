import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import type { PageProps } from '@/types';

/**
 * Open transfer announcements, inside the portal.
 *
 * The public listing renders in PublicLayout, so following an announcement
 * from /my-portal used to drop the employee out of the signed-in chrome. This
 * screen keeps the browse step under the portal.
 */
type Announcement = {
    id: string;
    organization_name_en: string | null;
    organization_name_am: string | null;
    position_title_en: string | null;
    position_title_am: string | null;
    grade_level: string | null;
    number_of_vacancies: number;
    opening_date: string | null;
    closing_date: string | null;
    is_open: boolean;
};

type Paginated = {
    data: Announcement[];
    current_page: number;
    last_page: number;
    total: number;
};

type Props = PageProps & {
    announcements: Paginated;
    applied_ids: string[];
    has_employee: boolean;
};

export default function Announcements({ announcements, applied_ids, has_employee }: Props) {
    const { t, locale } = useLocale();
    const name = (en: string | null, am: string | null) => localizedName(en ?? '', am, locale) || '—';

    function goToPage(page: number) {
        router.get(route('employee.announcements'), { page }, { preserveState: true, preserveScroll: true });
    }

    return (
        <PortalPage
            title={t('employeePortal.openAnnouncements')}
            backHref={route('employee.transfer-applications')}
            actions={
                        <Link
                            href={route('employee.transfer-applications')}
                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        >
                            {t('transfers.myApplications')}
                        </Link>
            }
        >

            {!has_employee && (
                <div className="mb-4 rounded-panel border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-300">
                    {t('transfers.noEmployeeProfile')}
                </div>
            )}

            {announcements.data.length === 0 ? (
                <div className="rounded-panel border border-gray-200 bg-white p-10 text-center dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-sm text-gray-400 dark:text-slate-500">{t('transfers.noAnnouncements')}</p>
                </div>
            ) : (
                <div className="space-y-3">
                    {announcements.data.map((a) => {
                        const applied = applied_ids.includes(a.id);

                        return (
                            <Link
                                key={a.id}
                                href={route('employee.announcements.show', { announcement: a.id })}
                                className="block rounded-panel border border-gray-200 bg-white p-5 transition hover:border-[var(--color-primary)]/40 dark:border-slate-800 dark:bg-slate-900"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="font-semibold text-gray-900 dark:text-slate-100">
                                            {name(a.position_title_en, a.position_title_am)}
                                        </p>
                                        <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">
                                            {name(a.organization_name_en, a.organization_name_am)}
                                            {a.grade_level ? ` · ${t('transfers.gradeLevel')} ${a.grade_level}` : ''}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        {applied && (
                                            <span className="rounded-full bg-blue-100 px-2.5 py-0.5 text-[11px] font-semibold text-blue-700 dark:bg-blue-950/50 dark:text-blue-300">
                                                {t('transfers.applicationAlreadySubmitted')}
                                            </span>
                                        )}
                                        <span
                                            className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${
                                                a.is_open
                                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'
                                                    : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'
                                            }`}
                                        >
                                            {a.is_open ? t('transfers.statusOpen') : t('transfers.statusClosed')}
                                        </span>
                                    </div>
                                </div>

                                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-400 dark:text-slate-500">
                                    <span>
                                        {t('transfers.vacancies')}: {a.number_of_vacancies}
                                    </span>
                                    {a.closing_date && (
                                        <span>
                                            {t('transfers.closes')}: <LocalizedDateDisplay value={a.closing_date} />
                                        </span>
                                    )}
                                </div>
                            </Link>
                        );
                    })}
                </div>
            )}

            {announcements.last_page > 1 && (
                <nav
                    aria-label={t('employeePortal.openAnnouncements')}
                    className="mt-4 flex items-center justify-between text-xs text-gray-500 dark:text-slate-400"
                >
                    <span>
                        {t('common.page')} {announcements.current_page} / {announcements.last_page}
                    </span>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            disabled={announcements.current_page <= 1}
                            onClick={() => goToPage(announcements.current_page - 1)}
                            className="rounded-lg border border-gray-200 px-3 py-1 font-medium disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700"
                        >
                            {t('common.previous')}
                        </button>
                        <button
                            type="button"
                            disabled={announcements.current_page >= announcements.last_page}
                            onClick={() => goToPage(announcements.current_page + 1)}
                            className="rounded-lg border border-gray-200 px-3 py-1 font-medium disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700"
                        >
                            {t('common.next')}
                        </button>
                    </div>
                </nav>
            )}
        </PortalPage>
    );
}
