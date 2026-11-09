import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import PublicLayout from '@/court/public/PublicLayout';
import { CourtPublicContainer, CourtPublicPageHeader, courtPublicCardClass } from '@/court/public/CourtPublicPage';
import { usePublicText } from '@/court/i18n/usePublicText';
import type { PublicNewsItemProps } from '@/court/types/public';

/** One announcement of the court (PublicHomeController@newsItem): its date, title and text. */
export default function PublicNewsItem({ item, publicSite }: PublicNewsItemProps) {
    const { t } = usePublicText();
    const text = (key: string) => t(`court.publicSite.news.${key}`);

    return (
        <PublicLayout title={item.title} publicSite={publicSite}>
            <CourtPublicPageHeader
                title={item.title}
                eyebrow={t('court.publicSite.eyebrow.news')}
                breadcrumbs={[{ label: text('title'), href: route('court.public.news') }, { label: item.title }]}
                meta={item.created_at ? <>{text('published')}: <LocalizedDateDisplay value={item.created_at} /></> : null}
            />

            <CourtPublicContainer narrow className="pb-16 sm:pb-24">
                <article className={courtPublicCardClass}>
                    <RichTextViewer html={item.content} className="text-(--court-text)" />
                </article>
                <p className="mt-8">
                    <a href={route('court.public.news')} className="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-(--court-navy) hover:underline dark:text-(--court-brass-soft)">
                        ‹ {text('back')}
                    </a>
                </p>
            </CourtPublicContainer>
        </PublicLayout>
    );
}
