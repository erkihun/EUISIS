import { router } from '@inertiajs/react';
import { EmptyState, Pagination } from '@euisis/ui';
import PublicLayout, { type PublicPageMeta } from '@/Layouts/PublicLayout';
import { PublicPageHeader, PublicSection, publicCardClass } from '@/Components/public/PublicPage';
import { PublicSearchBar, ServiceItem, type ServiceSummary } from '@/Components/public/PublicItems';
import { useBilingual } from '@/Components/public/bilingual';
import { useLocale } from '@/hooks/useLocale';
import type { PageSection } from '@/Components/public/PublicPage';

interface Props {
    sections: Record<string, PageSection>;
    services: { data: ServiceSummary[]; current_page: number; last_page: number; per_page: number; total: number };
    filters: { search: string };
    meta: PublicPageMeta;
}

/**
 * Public services, as published in Public Site Management. Previously four
 * hard-coded tiles, each in its own colour, with no way to add, correct or
 * retire a service without a code change.
 */
export default function Services({ sections, services, filters, meta }: Props) {
    const pick = useBilingual();
    const { t } = useLocale();
    const header = sections.header;
    const title = pick(header, 'title') || t('nav.services');

    return (
        <PublicLayout title={title} description={pick(header, 'subtitle')} meta={meta}>
            <PublicPageHeader title={title} description={pick(header, 'subtitle')} breadcrumbs={[{ label: title }]} />

            <PublicSection tone="muted">
                <div className="space-y-8">
                    <PublicSearchBar routeName="public.services" search={filters.search} searchLabel={t('publicSite.searchServices')} />

                    {services.data.length === 0 ? (
                        <EmptyState className={publicCardClass} title={t('publicSite.noServices')} />
                    ) : (
                        <>
                            <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {services.data.map((service) => <li key={service.id}><ServiceItem item={service} /></li>)}
                            </ul>
                            <Pagination
                                meta={{
                                    currentPage: services.current_page,
                                    lastPage: services.last_page,
                                    perPage: services.per_page,
                                    total: services.total,
                                }}
                                onPageChange={(page) => router.get(route('public.services'), { ...filters, page }, { preserveState: true })}
                            />
                        </>
                    )}
                </div>
            </PublicSection>
        </PublicLayout>
    );
}
