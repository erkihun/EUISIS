import { useLocale } from '@/hooks/useLocale';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PublicLayout from '@/Layouts/PublicLayout';
import { MegaphoneIcon, ChevronRight } from '@/Components/Icons';
import { Link } from '@inertiajs/react';
import { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement>;

function CalendarIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
            <line x1="16" y1="2" x2="16" y2="6" />
            <line x1="8" y1="2" x2="8" y2="6" />
            <line x1="3" y1="10" x2="21" y2="10" />
        </svg>
    );
}

function UsersIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
    );
}

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
    published_at: string | null;
    is_open: boolean;
};

interface Props {
    announcements: Announcement[];
}

export default function PublicTransferAnnouncements({ announcements }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';

    const open = announcements.filter((a) => a.is_open);
    const other = announcements.filter((a) => !a.is_open);

    return (
        <PublicLayout title={t('home.announcementsPageTitle')}>
            <div className="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
                <div className="mb-8 flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-white">
                        <MegaphoneIcon className="h-5 w-5" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-slate-100">{t('home.announcementsPageTitle')}</h1>
                        <p className="text-sm text-gray-500 dark:text-slate-400">{t('home.announcementsPageSubtitle')}</p>
                    </div>
                </div>

                {announcements.length === 0 ? (
                    <div className="rounded-2xl border border-gray-200 bg-white p-12 text-center dark:border-slate-800 dark:bg-slate-900">
                        <MegaphoneIcon className="mx-auto h-10 w-10 text-gray-300 dark:text-slate-600" aria-hidden="true" />
                        <p className="mt-4 text-sm text-gray-500 dark:text-slate-400">{t('home.noAnnouncements')}</p>
                    </div>
                ) : (
                    <div className="space-y-8">
                        {open.length > 0 && (
                            <section>
                                <h2 className="mb-3 text-xs font-semibold uppercase tracking-widest text-emerald-600 dark:text-emerald-400">
                                    {t('transfers.statusPublished')} — {t('transfers.statusOpen')}
                                </h2>
                                <div className="space-y-3">
                                    {open.map((a) => <AnnouncementCard key={a.id} a={a} useAmharic={useAmharic} t={t} />)}
                                </div>
                            </section>
                        )}
                        {other.length > 0 && (
                            <section>
                                <h2 className="mb-3 text-xs font-semibold uppercase tracking-widest text-gray-400 dark:text-slate-500">
                                    {t('transfers.statusPublished')}
                                </h2>
                                <div className="space-y-3">
                                    {other.map((a) => <AnnouncementCard key={a.id} a={a} useAmharic={useAmharic} t={t} />)}
                                </div>
                            </section>
                        )}
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}

function AnnouncementCard({ a, useAmharic, t }: { a: Announcement; useAmharic: boolean; t: (k: string) => string }) {
    const orgName = (useAmharic ? a.organization_name_am : null) ?? a.organization_name_en ?? '—';
    const posTitle = (useAmharic ? a.position_title_am : null) ?? a.position_title_en ?? '—';

    return (
        <Link
            href={route('public.transfer-announcements.show', { announcement: a.id })}
            className={`group relative block overflow-hidden rounded-2xl border bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:bg-slate-900 ${a.is_open ? 'border-emerald-200 dark:border-emerald-900/60' : 'border-gray-200 dark:border-slate-800'}`}
        >
            {a.is_open && <div className="absolute left-0 top-0 h-full w-1 rounded-l-2xl bg-emerald-500" aria-hidden="true" />}

            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 pl-2">
                    <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{orgName}</p>
                    <p className="mt-0.5 text-base font-semibold text-gray-900 dark:text-slate-100">{posTitle}</p>
                    <div className="mt-3 flex flex-wrap gap-3 text-xs text-gray-500 dark:text-slate-400">
                        {a.grade_level && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 dark:bg-slate-800">
                                {t('transfers.gradeLevel')}: <strong className="text-gray-700 dark:text-slate-200">{a.grade_level}</strong>
                            </span>
                        )}
                        <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300">
                            <UsersIcon className="h-3.5 w-3.5" aria-hidden="true" />
                            {a.number_of_vacancies} {t('transfers.vacancies')}
                        </span>
                        {a.opening_date && a.closing_date && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 dark:bg-slate-800">
                                <CalendarIcon className="h-3.5 w-3.5" aria-hidden="true" />
                                <LocalizedDateDisplay value={a.opening_date} /> → <LocalizedDateDisplay value={a.closing_date} />
                            </span>
                        )}
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-2 sm:flex-col sm:items-end">
                    {a.is_open ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                            <span className="relative flex h-2 w-2" aria-hidden="true">
                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                            </span>
                            {t('transfers.statusOpen')}
                        </span>
                    ) : (
                        <span className="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-500 dark:bg-slate-800 dark:text-slate-400">
                            {t('transfers.statusPublished')}
                        </span>
                    )}
                    <ChevronRight className="h-4 w-4 text-gray-300 group-hover:text-gray-500 dark:text-slate-600 dark:group-hover:text-slate-400" aria-hidden="true" />
                </div>
            </div>
        </Link>
    );
}
