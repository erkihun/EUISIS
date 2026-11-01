import { useState } from 'react';
import EmptyState from '@/Components/EmptyState';
import { buttonClassName } from '@/Components/ui';
import PublicLayout from '@/court/public/PublicLayout';
import CourtResourceCard from '@/court/public/CourtResourceCard';
import { CourtPublicContainer, CourtPublicPageHeader } from '@/court/public/CourtPublicPage';
import { usePublicText } from '@/court/i18n/usePublicText';
import type { PublicResourcesProps, ResourceType } from '@/court/types/public';

type Filter = 'all' | ResourceType;

const FILTERS: Filter[] = ['all', 'post', 'form', 'document', 'link'];

/**
 * Every active, published resource from the landing manager
 * (PublicHomeController@resources), with the Blade page's type filter: the
 * filter only hides cards in the browser, the list is the server's.
 */
export default function PublicResources({ resources, publicSite }: PublicResourcesProps) {
    const { t, text } = usePublicText();
    const [filter, setFilter] = useState<Filter>('all');
    const shown = filter === 'all' ? resources : resources.filter((resource) => resource.type === filter);
    const title = text('resources_page_title');

    return (
        <PublicLayout title={title} description={text('resources_page_description')} publicSite={publicSite}>
            <CourtPublicPageHeader
                title={title}
                eyebrow={text('resources_page_badge')}
                meta={text('resources_page_description')}
                breadcrumbs={[{ label: t('cm.home.nav.resources') }]}
            />

            <CourtPublicContainer className="space-y-8 py-10 sm:pb-20">
                {resources.length === 0 ? (
                    <EmptyState title={text('resources_empty')} />
                ) : (
                    <>
                        <div role="group" aria-label={text('resources_filter_label')} className="flex flex-wrap gap-2">
                            {FILTERS.map((key) => (
                                <button
                                    key={key}
                                    type="button"
                                    aria-pressed={filter === key}
                                    onClick={() => setFilter(key)}
                                    className={buttonClassName({ variant: filter === key ? 'primary' : 'outline', size: 'sm' })}
                                >
                                    {text(`resources_filter_${key}`)}
                                </button>
                            ))}
                        </div>

                        {shown.length === 0 ? (
                            <EmptyState title={text('resources_empty_type')} />
                        ) : (
                            <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                {shown.map((resource) => <CourtResourceCard key={resource.id} resource={resource} bothLinks headingLevel="h2" />)}
                            </div>
                        )}
                    </>
                )}
            </CourtPublicContainer>
        </PublicLayout>
    );
}
