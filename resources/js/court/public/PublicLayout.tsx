import { useRef, type ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import LocalizedUiProvider from '@/Components/ui/LocalizedUiProvider';
import AppToaster from '@/Components/ui/AppToaster';
import CourtPublicHeader from '@/court/public/CourtPublicHeader';
import CourtPublicFooter from '@/court/public/CourtPublicFooter';
import { useReveal } from '@/court/public/motion';
import type { PublicSiteChrome } from '@/court/types/public';
import '../../../css/public-site.css';
import '../../../css/court-public.css';

interface Props {
    /** Browser tab title. */
    title: string;
    description?: string;
    /** Footer and navigation data the server sends with every public page. */
    publicSite?: PublicSiteChrome | null;
    children: ReactNode;
}

/**
 * The court's public site layout: skip link, header, main, footer and the
 * toaster, with the shared `public-site` base (resources/css/public-site.css)
 * and the court's own face over it (resources/css/court-public.css). The
 * header and footer carry the landing manager's contact settings and the
 * way to My Portal for the parties.
 */
export default function PublicLayout({ title, description, publicSite = null, children }: Props) {
    const { t } = useLocale();
    const { getBoolean } = useSystemSettings();
    const root = useRef<HTMLDivElement>(null);
    // Sections and cards rise into place as they come into view (court/public/motion.tsx).
    useReveal(root);

    return (
        <LocalizedUiProvider>
            <Head title={title}>
                {description && <meta head-key="description" name="description" content={description} />}
                <meta head-key="og:title" property="og:title" content={title} />
            </Head>

            <div
                ref={root}
                className="public-site court-site flex min-h-screen flex-col"
                data-motion={getBoolean('appearance.enable_ui_animations', true) ? 'on' : 'off'}
            >
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-60 focus:rounded-md focus:bg-(--court-paper) focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:shadow-sm"
                >
                    {t('publicSite.skipToContent')}
                </a>

                <CourtPublicHeader
                    portalUrl={publicSite?.portalUrl ?? null}
                    authorityName={publicSite?.footer?.authority_name ?? null}
                    officeHours={publicSite?.footer?.office_hours ?? null}
                />

                <main id="main-content" tabIndex={-1} className="flex-1 focus:outline-hidden">
                    {children}
                </main>

                <CourtPublicFooter
                    footer={publicSite?.footer ?? null}
                    userManualEnabled={publicSite?.userManualEnabled ?? false}
                    userManualTitle={publicSite?.userManualTitle ?? null}
                />
            </div>

            <AppToaster />
        </LocalizedUiProvider>
    );
}
