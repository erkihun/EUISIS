import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import {
    PublicContainer,
    PublicPageHeader,
    RichText,
    publicButtonSecondary,
    publicHeadingClass,
} from '@/Components/public/PublicPage';
import { useBilingual } from '@/Components/public/bilingual';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import type { AnnouncementSummary } from '@/Components/public/PublicItems';

type Announcement = AnnouncementSummary & {
    content_html_en: string;
    content_html_am: string;
    expires_at: string | null;
    attachments: Array<{ id: string; name: string; mime: string; size: number; url: string }>;
};

function formatSize(bytes: number): string {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * One announcement, set at a reading width (max-w-3xl) with the body rendered
 * from sanitized HTML. Preview mode renders the same component, so what an
 * administrator previews is exactly what the public will read.
 */
export default function AnnouncementShow({ announcement, preview = false }: { announcement: Announcement; preview?: boolean }) {
    const pick = useBilingual();
    const { t } = useLocale();
    const title = pick(announcement, 'title');
    const sectionTitle = t('nav.announcements');

    return (
        <PublicLayout title={title} description={pick(announcement, 'summary')} noindex={preview}>
            {preview && (
                <div role="status" className="border-b border-amber-300 bg-amber-50 px-4 py-2 text-center text-sm font-medium text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    {t('publicSite.previewBanner')}
                </div>
            )}

            <PublicPageHeader
                title={title}
                breadcrumbs={[{ label: sectionTitle, href: route('public.announcements') }, { label: title }]}
                meta={
                    <span className="flex flex-wrap gap-x-4 gap-y-1">
                        {announcement.published_at && (
                            <span>{t('publicSite.published')}: <LocalizedDateDisplay value={announcement.published_at} /></span>
                        )}
                        {announcement.expires_at && (
                            <span>{t('publicSite.expires')}: <LocalizedDateDisplay value={announcement.expires_at} /></span>
                        )}
                        {announcement.category && <span>{announcement.category}</span>}
                    </span>
                }
            />

            <div className="bg-white py-16 sm:py-20 dark:bg-slate-950">
                <PublicContainer narrow>
                    {announcement.image_url && (
                        <img
                            src={announcement.image_url}
                            alt=""
                            className="mb-6 max-h-96 w-full rounded-card border border-gray-200 object-cover dark:border-slate-800"
                        />
                    )}

                    <RichText html={pick(announcement, 'content_html')} />

                    {announcement.attachments.length > 0 && (
                        <section aria-labelledby="attachments-heading" className="mt-8">
                            <h2 id="attachments-heading" className={publicHeadingClass}>
                                {t('publicSite.attachments')}
                            </h2>
                            <ul className="mt-3 divide-y divide-gray-200 border-y border-gray-200 dark:divide-slate-800 dark:border-slate-800">
                                {announcement.attachments.map((file) => (
                                    <li key={file.id} className="flex items-center justify-between gap-3 py-3">
                                        <a
                                            href={file.url}
                                            className="min-w-0 truncate text-sm font-medium text-[color:var(--color-primary)] hover:underline"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            {file.name}
                                        </a>
                                        <span className="shrink-0 text-xs text-gray-500 dark:text-slate-400">{formatSize(file.size)}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <div className="mt-10 border-t border-gray-200 pt-6 dark:border-slate-800">
                        <Link href={route('public.announcements')} className={publicButtonSecondary}>
                            {t('publicSite.backToAnnouncements')}
                        </Link>
                    </div>
                </PublicContainer>
            </div>
        </PublicLayout>
    );
}
