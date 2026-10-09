import { Link, router } from '@inertiajs/react';
import { EmptyState, Pagination } from '@euisis/ui';
import PublicLayout, { type PublicPageMeta } from '@/Layouts/PublicLayout';
import {
    PublicCardDecor,
    PublicSection,
    PublicPageHeader,
    publicCardClass,
    publicLinkClass,
} from '@/Components/public/PublicPage';
import { AnnouncementItem, PublicSearchBar, type AnnouncementSummary } from '@/Components/public/PublicItems';
import { useBilingual } from '@/Components/public/bilingual';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import type { PageSection } from '@/Components/public/PublicPage';

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type TransferSummary = {
    id: string;
    organization_name_en: string | null;
    organization_name_am: string | null;
    position_title_en: string | null;
    position_title_am: string | null;
    number_of_vacancies: number;
    closing_date: string | null;
};

interface Props {
    sections: Record<string, PageSection>;
    announcements: Paginated<AnnouncementSummary>;
    categories: string[];
    openTransfers: TransferSummary[];
    filters: { search: string; category: string };
    meta: PublicPageMeta;
}

/**
 * Announcements: general notices from Public Site Management, with any open
 * transfer opportunities listed beside them.
 *
 * Laid out as a reading list rather than a grid of raised cards. The pinging
 * "open" dots and lift-on-hover effects of the previous page are gone; status
 * is stated in words.
 */
export default function AnnouncementsIndex({ sections, announcements, categories, openTransfers, filters, meta }: Props) {
    const pick = useBilingual();
    const { t } = useLocale();
    const header = sections.header;
    const title = pick(header, 'title') || t('nav.announcements');
    const hasFilters = Boolean(filters.search || filters.category);

    const goToPage = (page: number) =>
        router.get(route('public.announcements'), { ...filters, page }, { preserveScroll: false, preserveState: true });

    return (
        <PublicLayout title={title} description={pick(header, 'subtitle')} meta={meta}>
            <PublicPageHeader title={title} description={pick(header, 'subtitle')} breadcrumbs={[{ label: title }]} />

            <PublicSection tone="muted">
                <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="min-w-0 space-y-8">
                        <PublicSearchBar
                            routeName="public.announcements"
                            search={filters.search}
                            category={filters.category}
                            categories={categories}
                            searchLabel={t('publicSite.searchAnnouncements')}
                        />

                        {announcements.data.length === 0 ? (
                            <EmptyState
                                className={publicCardClass}
                                title={hasFilters ? t('publicSite.noAnnouncements') : t('publicSite.noAnnouncementsYet')}
                                description={hasFilters ? t('publicSite.noAnnouncementsHint') : undefined}
                            />
                        ) : (
                            <>
                                <ul className="grid gap-4 sm:grid-cols-2">
                                    {announcements.data.map((item) => (
                                        <li key={item.id}><AnnouncementItem item={item} /></li>
                                    ))}
                                </ul>
                                <Pagination
                                    meta={{
                                        currentPage: announcements.current_page,
                                        lastPage: announcements.last_page,
                                        perPage: announcements.per_page,
                                        total: announcements.total,
                                    }}
                                    onPageChange={goToPage}
                                />
                            </>
                        )}
                    </div>

                    {/* Transfer opportunities: a business workflow with its own
                        pages, summarised here and linked, not duplicated. */}
                    <aside aria-labelledby="transfers-heading" className={`${publicCardClass} min-w-0 self-start`}>
                        <PublicCardDecor glow={false} />
                        <h2 id="transfers-heading" className="relative text-lg font-bold text-gray-900 dark:text-slate-100">
                            {t('publicSite.transferOpportunities')}
                        </h2>

                        {openTransfers.length === 0 ? (
                            <p className="mt-3 text-sm text-gray-500 dark:text-slate-400">{t('publicSite.noAnnouncementsYet')}</p>
                        ) : (
                            <ul className="mt-3 divide-y divide-gray-200 dark:divide-slate-800">
                                {openTransfers.map((transfer) => (
                                    <li key={transfer.id} className="py-3">
                                        <Link
                                            href={route('public.transfer-announcements.show', transfer.id)}
                                            className="text-sm font-semibold text-gray-900 hover:text-[color:var(--color-primary)] hover:underline [overflow-wrap:anywhere] dark:text-slate-100"
                                        >
                                            {pick(transfer, 'position_title')}
                                        </Link>
                                        <p className="mt-0.5 text-sm text-gray-600 dark:text-slate-400">{pick(transfer, 'organization_name')}</p>
                                        <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                            {t('publicSite.vacancies')}: {transfer.number_of_vacancies}
                                            {transfer.closing_date && (
                                                <> · {t('publicSite.closes')} <LocalizedDateDisplay value={transfer.closing_date} /></>
                                            )}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <Link href={route('public.transfer-announcements')} className={`${publicLinkClass} mt-4`}>
                            {t('publicSite.viewAllTransfers')}
                        </Link>
                    </aside>
                </div>
            </PublicSection>
        </PublicLayout>
    );
}
