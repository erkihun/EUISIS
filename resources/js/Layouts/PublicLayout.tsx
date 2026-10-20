import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import LocalizedUiProvider from '@/Components/ui/LocalizedUiProvider';
import AppToaster from '@/Components/ui/AppToaster';
import PublicHeader from '@/Components/public/PublicHeader';
import PublicFooter from '@/Components/public/PublicFooter';
import PublicAssistant from '@/Components/public-ai/PublicAssistant';
import { useBilingual } from '@/Components/public/bilingual';
import '../../css/public-site.css';

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
    /**
     * Show the server's flash messages as popup toasts (default). A page that
     * already shows its flash inline turns this off so a message is not shown twice.
     */
    flashToasts?: boolean;
    children: ReactNode;
}

/**
 * The one layout for every public page: header, main, footer, and metadata.
 *
 * Navigation, footer links and SEO fields all come from Public Site
 * Management. The layout owns no content of its own — changing a menu label
 * or a page description never needs a code change.
 */
export default function PublicLayout({ title, description, meta, noindex = false, flashToasts = true, children }: Props) {
    const { t } = useLocale();
    const { getBoolean } = useSystemSettings();
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

            {/* `public-site` carries the home page's look to every public page
                (resources/css/public-site.css); motion follows the same
                appearance setting the home page reads. */}
            <div
                className="public-site flex min-h-screen flex-col bg-(--app-background)"
                data-motion={getBoolean('appearance.enable_ui_animations', true) ? 'on' : 'off'}
            >
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-60 focus:rounded-control focus:bg-(--app-surface) focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-(--app-foreground) focus:shadow-sm focus:outline-hidden focus:ring-2 focus:ring-primary"
                >
                    {t('publicSite.skipToContent')}
                </a>

                <PublicHeader />

                <main id="main-content" tabIndex={-1} className="flex-1 focus:outline-hidden">
                    {children}
                </main>

                <PublicFooter />
            </div>

            {/* Popup messages for every public page: a submission's outcome must be
                visible wherever the visitor has scrolled, on a phone as on a desktop. */}
            <AppToaster flashToasts={flashToasts} />

            {/* Addis One Help: the floating trigger; its panel loads on the first open. */}
            <PublicAssistant />
        </LocalizedUiProvider>
    );
}
