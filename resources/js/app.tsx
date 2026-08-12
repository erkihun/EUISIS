import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Suspense, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { LocaleProvider } from '@/contexts/LocaleContext';
import ConfirmProvider from '@/Components/ConfirmProvider';
import { initTheme, type ThemePreference } from '@/lib/theme';
import AppErrorBoundary from '@/Components/errors/AppErrorBoundary';
import AppPageLoader from '@/Components/ui/AppPageLoader';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
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

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
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
                    </ConfirmProvider>
                </AppErrorBoundary>
            </LocaleProvider>,
        );
    },
    progress: {
        color: '#2563eb',
        delay: 200,
    },
});
