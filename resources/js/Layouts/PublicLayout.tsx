import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedUiProvider from '@/Components/ui/LocalizedUiProvider';
import PublicHeader from '@/Components/public/PublicHeader';
import PublicFooter from '@/Components/public/PublicFooter';
import { useBilingual } from '@/Components/public/bilingual';

/** SEO metadata managed per page in Public Site Management. */
export type PublicPageMeta = {
    title_en: string | null;
    title_am: string | null;
    description_en: string | null;
    description_am: string | null;
    canonical_url: string | null;
    og_image_url: string | null;
    noindex: boolean;
} | null;

interface Props {
    /** Title shown in the browser tab when no SEO title is configured. */
    title: string;
    /** Fallback meta description when no SEO description is configured. */
    description?: string;
    meta?: PublicPageMeta;
    /**
     * Force `noindex`. Used for verification pages and previews, which must
     * never appear in search results whatever the SEO settings say.
     */
    noindex?: boolean;
    children: ReactNode;
}

/**
 * The one layout for every public page: header, main, footer, and metadata.
 *
 * Navigation, footer links and SEO fields all come from Public Site
 * Management. The layout owns no content of its own — changing a menu label
 * or a page description never needs a code change.
 */
export default function PublicLayout({ title, description, meta, noindex = false, children }: Props) {
    const { t } = useLocale();
    const pick = useBilingual();

    const pageTitle = pick(meta, 'title') || title;
    const pageDescription = pick(meta, 'description') || description || '';
    const robotsNoindex = noindex || Boolean(meta?.noindex);

    return (
        <LocalizedUiProvider>
            <Head title={pageTitle}>
                {pageDescription && <meta head-key="description" name="description" content={pageDescription} />}
                {robotsNoindex && <meta head-key="robots" name="robots" content="noindex, nofollow" />}
                {meta?.canonical_url && <link head-key="canonical" rel="canonical" href={meta.canonical_url} />}
                <meta head-key="og:title" property="og:title" content={pageTitle} />
                {pageDescription && <meta head-key="og:description" property="og:description" content={pageDescription} />}
                {meta?.og_image_url && <meta head-key="og:image" property="og:image" content={meta.og_image_url} />}
            </Head>

            <div className="flex min-h-screen flex-col bg-[color:var(--app-background)]">
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-control focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-gray-900 focus:shadow focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]"
                >
                    {t('publicSite.skipToContent')}
                </a>

                <PublicHeader />

                <main id="main-content" tabIndex={-1} className="flex-1 focus:outline-none">
                    {children}
                </main>

                <PublicFooter />
            </div>
        </LocalizedUiProvider>
    );
}
