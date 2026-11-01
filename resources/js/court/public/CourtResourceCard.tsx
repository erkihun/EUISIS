import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { badgeClassName, cx } from '@/Components/ui';
import { usePublicText } from '@/court/i18n/usePublicText';
import type { PublicResource, ResourceType } from '@/court/types/public';

/** Each resource type's icon and colour, as the Blade cards drew them. */
const TYPE_ICON: Record<ResourceType, { path: string; tone: string }> = {
    post: {
        path: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        tone: 'bg-violet-50 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300',
    },
    form: {
        path: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
        tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
    },
    document: {
        path: 'M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z',
        tone: 'bg-primary/10 text-primary',
    },
    link: {
        path: 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1',
        tone: 'bg-orange-50 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
    },
};

const actionClass = 'inline-flex min-h-9 items-center gap-1.5 rounded-control text-sm font-semibold text-primary hover:underline focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary dark:text-blue-300';

/**
 * One published resource: cover, type, featured mark, title, description,
 * publication date and its file or link. The home page offers the file or
 * else the link; the resources page offers both (`bothLinks`), as the two
 * Blade pages did.
 */
export default function CourtResourceCard({ resource, bothLinks = false, headingLevel = 'h3' }: {
    resource: PublicResource;
    bothLinks?: boolean;
    headingLevel?: 'h2' | 'h3';
}) {
    const { t } = usePublicText();
    const Heading = headingLevel;
    const icon = TYPE_ICON[resource.type] ?? TYPE_ICON.post;
    const showLink = Boolean(resource.external_url) && (bothLinks || !resource.download_url);

    return (
        <article className="public-card group flex min-w-0 flex-col overflow-hidden border border-gray-200/80 bg-white dark:border-slate-800/80 dark:bg-slate-900">
            {resource.cover_image_url && (
                <div className="h-40 overflow-hidden bg-gray-100 dark:bg-slate-800">
                    <img
                        src={resource.cover_image_url}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className="h-full w-full object-cover transition duration-300 group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100"
                    />
                </div>
            )}

            <div className="flex flex-1 flex-col p-5">
                <div className="mb-3 flex items-center gap-2">
                    <span className={cx('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', icon.tone)} aria-hidden="true">
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={icon.path} />
                        </svg>
                    </span>
                    <span className="text-[11px] font-semibold uppercase tracking-widest text-gray-500 dark:text-slate-400">
                        {t(`cm.home.resources.types.${resource.type}`)}
                    </span>
                    {resource.is_featured && (
                        <span className={badgeClassName('warning', 'ms-auto')}>{t('cm.home.resources.featured')}</span>
                    )}
                </div>

                <Heading className="text-base font-semibold leading-snug text-gray-900 wrap-anywhere dark:text-slate-100">{resource.title}</Heading>
                {resource.description && (
                    <p className="mt-2 line-clamp-4 whitespace-pre-line text-sm leading-relaxed text-gray-600 dark:text-slate-400">{resource.description}</p>
                )}
                <div className="flex-1" />

                <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3 dark:border-slate-800">
                    {resource.published_at ? (
                        <LocalizedDateDisplay value={resource.published_at} fallback="" className="text-xs text-gray-500 dark:text-slate-400" />
                    ) : <span />}
                    <div className="flex flex-wrap gap-3">
                        {resource.download_url && (
                            <a href={resource.download_url} target="_blank" rel="noopener" download className={actionClass}>
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                </svg>
                                {t('cm.home.resources.download')}
                            </a>
                        )}
                        {showLink && resource.external_url && (
                            <a href={resource.external_url} target="_blank" rel="noopener noreferrer" className={actionClass}>
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                                {t('cm.home.resources.visit')}
                            </a>
                        )}
                    </div>
                </div>
            </div>
        </article>
    );
}
