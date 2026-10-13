import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Suspense, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { route as ziggyRoute, type Config as ZiggyConfig } from 'ziggy-js';
import { Ziggy } from './ziggy';
import { LocaleProvider, resolveInitialLocale } from '@/contexts/LocaleContext';
import { ensureLocale } from '@/i18n/registry';
import ConfirmProvider from '@/Components/ConfirmProvider';
import { initTheme, type ThemePreference } from '@/lib/theme';
import AppErrorBoundary from '@/Components/errors/AppErrorBoundary';
import AppPageLoader from '@/Components/ui/AppPageLoader';
import SessionTimeoutManager, { type SessionPolicy } from '@/Components/SessionTimeoutManager';

// The route list is a cached asset (resources/js/ziggy.js, regenerated with
// `php artisan routes:export-js`) instead of 440 KB inlined into every HTML
// response. The origin is the page's own, never the machine the file was
// generated on.
globalThis.Ziggy = {
    ...(Ziggy as unknown as ZiggyConfig),
    url: window.location.origin,
    port: window.location.port ? Number(window.location.port) : null,
};
globalThis.route = ziggyRoute;

// Set from the shared `branding` prop before the first render (System Settings over config);
// the build-time env value is only the pre-render fallback.
let brandName: string = import.meta.env.VITE_APP_NAME || 'Addis One';
const pages = import.meta.glob('./Pages/**/*.tsx');

/** Removes the static HTML loader once React has mounted. */
function InitialLoaderRemover() {
    useEffect(() => {
        const loader = document.getElementById('app-initial-loader');
        if (!loader) return;
        loader.style.opacity = '0';
        const timer = setTimeout(() => loader.remove(), 220);
        return () => clearTimeout(timer);
    }, []);
    return null;
}

function mount() {
    return createInertiaApp({
        // "{Page} | Addis One"; a page titled with the brand itself is not doubled.
        title: (title) => (title && title !== brandName ? `${title} | ${brandName}` : brandName),
        resolve: async (name) => {
            try {
                return await resolvePageComponent(`./Pages/${name}.tsx`, pages);
            } catch (error) {
                if (import.meta.env.DEV) throw error;
                console.error(`Unable to resolve Inertia page: ${name}`, error);
                return { default: () => null };
            }
        },
        setup({ el, App, props }) {
            const initialProps = props.initialPage.props as Record<string, unknown>;
            const settings = (initialProps.settings as Record<string, unknown> | undefined) ?? {};
            const branding = initialProps.branding as { brandName?: string } | undefined;
            // Error pages (404, 403, 419…) are rendered by the exception handler without the
            // shared props, but the server still stamps the resolved brand into the document
            // head; read it from there before falling back to the build-time env value, which
            // a production build may have set to the old product name.
            const headBrand = document.querySelector('meta[name="application-name"]')?.getAttribute('content')?.trim();
            brandName = (settings['app.name'] as string | undefined) || branding?.brandName || headBrand || brandName;
            const defaultLocale = ((initialProps.locale as string | undefined) ?? 'en') as 'en' | 'am';

            initTheme(
                (settings['appearance.default_theme'] as ThemePreference | undefined) ?? 'system',
                Boolean(settings['appearance.allow_user_theme_switching'] ?? true),
            );

            const root = createRoot(el);
            root.render(
                <LocaleProvider defaultLocale={defaultLocale}>
                    <AppErrorBoundary>
                        <ConfirmProvider>
                            {/* Remove the native HTML loader as soon as React mounts */}
                            <InitialLoaderRemover />
                            <Suspense fallback={<AppPageLoader />}>
                                <App {...props} />
                            </Suspense>
                            <SessionTimeoutManager initialPolicy={(initialProps.session_policy as SessionPolicy | null | undefined) ?? null} />
                        </ConfirmProvider>
                    </AppErrorBoundary>
                </LocaleProvider>,
            );
        },
        progress: {
            color: '#122170',
            delay: 200,
        },
    });
}

// Only the active language's dictionary is downloaded (the other one loads on
// switch). It is preloaded from the HTML head, so this normally resolves at once.
// If it cannot be fetched the app still mounts: t() then shows keys instead of
// a blank page.
void ensureLocale(resolveInitialLocale())
    .catch((error: unknown) => console.error('Translations failed to load', error))
    .then(() => mount());
