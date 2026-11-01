import '../css/app.css';
// The court module's own styles (its case-page sheet and sign-in motion; ADR 0020).
import '../css/court.css';
import './bootstrap';

import { axiosAdapter } from '@inertiajs/core';
import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Suspense, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { route as ziggyRoute, type Config as ZiggyConfig } from 'ziggy-js';
import { Ziggy } from './ziggy';
import { LocaleProvider, resolveInitialLocale } from '@/contexts/LocaleContext';
import { ensureLocale } from '@/i18n/registry';
import ConfirmProvider from '@/Components/ConfirmProvider';
import { initTheme, type ThemePreference } from '@/lib/theme';
import { BRANDING_FALLBACK, brandNameFor, type Branding } from '@/lib/branding';
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

// Set from the shared settings and `branding` prop before the first render (System Settings
// over config); the build-time env value is only the pre-render fallback. The title follows
// the page language: አገልጋይ on Amharic pages, Agelgay elsewhere.
let brandNames: Record<'en' | 'am', string> = {
    en: import.meta.env.VITE_APP_NAME || BRANDING_FALLBACK.brandNameEn,
    am: BRANDING_FALLBACK.brandNameAm,
};
const currentBrand = (): string => (document.documentElement.lang === 'am' ? brandNames.am : brandNames.en);
const pages = import.meta.glob<{ default: ResolvedComponent }>('./Pages/**/*.tsx');

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
        // Inertia 3 has its own HTTP client; page visits stay on the app's axios
        // instance so its idle-timeout redirect (bootstrap.ts) and XSRF-cookie
        // handling keep covering navigation, forms and partial reloads.
        http: axiosAdapter(window.axios),
        // "{Page} | Agelgay"; a page titled with the brand itself is not doubled.
        title: (title) => {
            const brand = currentBrand();
            return title && title !== brand ? `${title} | ${brand}` : brand;
        },
        // Inertia 3: an async resolver returns the component itself, not its module.
        resolve: async (name): Promise<ResolvedComponent> => {
            try {
                return (await resolvePageComponent(`./Pages/${name}.tsx`, pages)).default;
            } catch (error) {
                if (import.meta.env.DEV) throw error;
                console.error(`Unable to resolve Inertia page: ${name}`, error);
                return () => null;
            }
        },
        setup({ el, App, props }) {
            const initialProps = props.initialPage.props as Record<string, unknown>;
            const settings = (initialProps.settings as Record<string, unknown> | undefined) ?? {};
            const branding = initialProps.branding as Partial<Branding> | undefined;
            if (settings['app.name'] || branding) {
                brandNames = { en: brandNameFor('en', settings, branding), am: brandNameFor('am', settings, branding) };
            } else {
                // Error pages (404, 403, 419…) are rendered by the exception handler without the
                // shared props, but the server still stamps the resolved brand (in the request's
                // language) into the document head; prefer it over the build-time env value, which
                // a production build may have set to a former product name.
                const headBrand = document.querySelector('meta[name="application-name"]')?.getAttribute('content')?.trim();
                if (headBrand) brandNames = { en: headBrand, am: headBrand };
            }
            const defaultLocale = ((initialProps.locale as string | undefined) ?? 'en') as 'en' | 'am';

            initTheme(
                (settings['appearance.default_theme'] as ThemePreference | undefined) ?? 'system',
                Boolean(settings['appearance.allow_user_theme_switching'] ?? true),
            );

            const root = createRoot(el);
            root.render(
                <LocaleProvider defaultLocale={defaultLocale} serverLocale={initialProps.textLocale === 'en' || initialProps.textLocale === 'am' ? initialProps.textLocale : undefined}>
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
