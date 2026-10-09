import { Link, router } from '@inertiajs/react';
import { EmptyState, Pagination, StatusBadge } from '@euisis/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PublicLayout from '@/Layouts/PublicLayout';
import { PublicPageHeader, PublicSection, publicCardClass } from '@/Components/public/PublicPage';
import { useBilingual } from '@/Components/public/bilingual';
import { useLocale } from '@/hooks/useLocale';

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
    announcements: { data: Announcement[]; current_page: number; last_page: number; per_page: number; total: number };
}

/**
 * All published transfer announcements.
 *
 * Previously lift-on-hover cards with an animated "pinging" dot on open ones
 * and a pill for every attribute. Now a plain list: open/closed is stated as a
 * status badge in words, and the page is paginated rather than loading every
 * published announcement at once.
 */
export default function PublicTransferAnnouncements({ announcements }: Props) {
    const { t } = useLocale();
    const pick = useBilingual();
    const title = t('home.announcementsPageTitle');

    return (
        <PublicLayout title={title} description={t('home.announcementsPageSubtitle')}>
            <PublicPageHeader
                title={title}
                description={t('home.announcementsPageSubtitle')}
                breadcrumbs={[{ label: t('nav.announcements'), href: route('public.announcements') }, { label: title }]}
            />

            <PublicSection tone="muted">
                <div className="space-y-8">
                    {announcements.data.length === 0 ? (
                        <EmptyState
                            className={publicCardClass}
                            title={t('home.noAnnouncements')}
                        />
                    ) : (
                        <>
                            <ul className="divide-y divide-gray-200 border-y border-gray-200 dark:divide-slate-800 dark:border-slate-800">
                                {announcements.data.map((a) => (
                                    <li key={a.id} className="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                        <div className="min-w-0">
                                            <p className="text-sm text-gray-600 dark:text-slate-400">{pick(a, 'organization_name') || '—'}</p>
                                            <h2 className="mt-0.5 text-base font-semibold leading-snug [overflow-wrap:anywhere]">
                                                <Link
                                                    href={route('public.transfer-announcements.show', { announcement: a.id })}
                                                    className="text-gray-900 hover:text-[color:var(--color-primary)] hover:underline dark:text-slate-100"
                                                >
                                                    {pick(a, 'position_title') || '—'}
                                                </Link>
                                            </h2>
                                            <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">
                                                {a.grade_level && <>{t('transfers.gradeLevel')}: {a.grade_level} · </>}
                                                {a.number_of_vacancies} {t('transfers.vacancies')}
                                                {a.opening_date && a.closing_date && (
                                                    <> · <LocalizedDateDisplay value={a.opening_date} /> – <LocalizedDateDisplay value={a.closing_date} /></>
                                                )}
                                            </p>
                                        </div>
                                        <StatusBadge tone={a.is_open ? 'success' : 'neutral'}>
                                            {a.is_open ? t('transfers.statusOpen') : t('publicSite.closed')}
                                        </StatusBadge>
                                    </li>
                                ))}
                            </ul>

                            <Pagination
                                meta={{
                                    currentPage: announcements.current_page,
                                    lastPage: announcements.last_page,
                                    perPage: announcements.per_page,
                                    total: announcements.total,
                                }}
                                onPageChange={(page) => router.get(route('public.transfer-announcements'), { page }, { preserveState: true })}
                            />
                        </>
                    )}
                </div>
            </PublicSection>
        </PublicLayout>
    );
}
