import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PublicLayout from '@/court/public/PublicLayout';
import { CourtPublicContainer, CourtPublicPageHeader } from '@/court/public/CourtPublicPage';
import { usePublicText } from '@/court/i18n/usePublicText';
import type { PublicNewsProps } from '@/court/types/public';

/**
 * News and announcements (PublicHomeController@newsIndex): the court's active
 * announcements, newest first, ten to a page. They are written under
 * Announcements in the staff application.
 */
export default function PublicNews({ items, pagination, publicSite }: PublicNewsProps) {
    const { t, fill } = usePublicText();
    const text = (key: string) => t(`court.publicSite.news.${key}`);
    const page = (number: number) => `${route('court.public.news')}${number > 1 ? `?page=${number}` : ''}`;

    return (
        <PublicLayout title={text('title')} description={text('description')} publicSite={publicSite}>
            <CourtPublicPageHeader title={text('title')} eyebrow={t('court.publicSite.eyebrow.news')} meta={text('description')} breadcrumbs={[{ label: text('title') }]} />

            <CourtPublicContainer className="-mt-8 pb-16 sm:-mt-10 sm:pb-24">
                {items.length === 0 ? (
                    <p className="court-card px-6 py-12 text-center text-sm text-(--court-muted)">{text('none')}</p>
                ) : (
                    <ul className="court-card divide-y divide-(--court-line) overflow-hidden">
                        {items.map((item) => (
                            <li key={item.id}>
                                <a href={route('court.public.news.show', item.id)} className="group grid gap-2 px-6 py-6 transition hover:bg-(--court-parchment)/60 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-8 sm:px-8">
                                    <LocalizedDateDisplay value={item.created_at} className="text-xs font-semibold uppercase tracking-[0.12em] text-(--court-brass-strong) sm:pt-1" />
                                    <span className="min-w-0">
                                        <span className="block text-lg font-semibold text-(--court-ink) group-hover:underline dark:text-white">{item.title}</span>
                                        {item.excerpt && <span className="mt-2 block text-sm leading-relaxed text-(--court-muted)">{item.excerpt}</span>}
                                    </span>
                                </a>
                            </li>
                        ))}
                    </ul>
                )}

                {pagination.last > 1 && (
                    <nav aria-label={text('pagination')} className="mt-8 flex items-center justify-between gap-4 text-sm">
                        {pagination.current > 1
                            ? <a href={page(pagination.current - 1)} className="font-semibold text-(--court-navy) hover:underline dark:text-(--court-brass-soft)">‹ {text('previous')}</a>
                            : <span />}
                        <span className="text-(--court-muted)">{fill(text('pageOf'), { current: pagination.current, last: pagination.last })}</span>
                        {pagination.current < pagination.last
                            ? <a href={page(pagination.current + 1)} className="font-semibold text-(--court-navy) hover:underline dark:text-(--court-brass-soft)">{text('next')} ›</a>
                            : <span />}
                    </nav>
                )}
            </CourtPublicContainer>
        </PublicLayout>
    );
}
