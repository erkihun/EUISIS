import { useEffect, useState, type PropsWithChildren, type ReactNode } from 'react';
import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react';
import AppHeader from '../components/AppHeader';
import AppSidebar from '../components/AppSidebar';
import AppToaster from '../components/AppToaster';
import Breadcrumbs from '../components/Breadcrumbs';
import LocalizedUiProvider from '../components/LocalizedUiProvider';
import { useLocale } from '../i18n';
import { darkThemeVariant, hoverVariant, isLight, readableForeground, sidebarAccent } from '../lib/brandColor';
import { useRoute } from '../router';
import { useSettings } from '../settings';
import { buttonClassName } from '../ui';

const SIDEBAR_STORAGE_KEY = 'core-sidebar-collapsed';
// Page chrome and content share one measure, so titles line up with the content below them.
const CONTENT_WIDTH = 'mx-auto w-full max-w-[1600px]';

/** What the shell writes onto <html> from Core settings › Appearance. */
const SHELL_ROOT_PROPERTIES = [
    '--color-primary-strong', '--color-primary-soft', '--color-primary-hover-strong', '--color-primary-hover-soft',
    '--color-on-primary-strong', '--color-on-primary-soft', '--color-secondary', '--color-accent-strong', '--color-accent-soft',
    '--sidebar-bg-light', '--sidebar-bg-dark', '--sidebar-fg-light', '--sidebar-fg-dark', '--sidebar-accent-light', '--sidebar-accent-dark',
] as const;
const SHELL_ROOT_DATASET = ['buttonStyle', 'cardRadius', 'tableDensity', 'stickyTables', 'animations'] as const;

/**
 * EUISIS Layouts/AuthenticatedLayout.tsx: fixed sidebar (collapsible to an icon
 * rail, a drawer on phones), sticky header, maintenance banner, the heading band
 * with breadcrumbs, the content measure and the footer credit. Colours and
 * density come from Core settings › Appearance, applied the EUISIS way.
 */
export default function AuthenticatedLayout({ header, children }: PropsWithChildren<{ header?: ReactNode }>) {
    const { t, locale } = useLocale();
    const { getBoolean, getString } = useSettings();
    const route = useRoute();

    const primary = getString('appearance.primary_color', '#122170');
    const secondary = getString('appearance.secondary_color', '#1d3084');
    const accent = getString('appearance.accent_color', '#d12908');
    const sidebarColor = getString('appearance.sidebar_color', '#ffffff');
    const buttonStyle = getString('appearance.button_style', 'rounded');
    const cardRadius = getString('appearance.card_radius', 'xl');
    const tableDensity = getString('appearance.table_density', 'comfortable');
    const stickyTableHeaders = getBoolean('appearance.sticky_table_headers', true);
    const enableAnimations = getBoolean('appearance.enable_ui_animations', true);
    const showBreadcrumbs = getBoolean('appearance.show_breadcrumbs', true);
    const sidebarCompactDefault = getBoolean('appearance.sidebar_compact_default', false);
    const maintenanceEnabled = getBoolean('security.maintenance_banner_enabled', false);
    const maintenanceMessage = getString(locale === 'am' ? 'security.maintenance_banner_message_am' : 'security.maintenance_banner_message_en');

    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [collapsed, setCollapsed] = useState<boolean>(() => {
        try {
            const stored = localStorage.getItem(SIDEBAR_STORAGE_KEY);
            if (stored !== null) return stored === 'true';
        } catch { /* optional */ }
        return sidebarCompactDefault;
    });

    const toggleSidebarCollapse = () => {
        setCollapsed((previous) => {
            const next = !previous;
            try { localStorage.setItem(SIDEBAR_STORAGE_KEY, String(next)); } catch { /* optional */ }
            return next;
        });
    };

    // Close the mobile drawer after every navigation so the destination is immediately visible.
    useEffect(() => setSidebarOpen(false), [route]);

    useEffect(() => {
        const root = document.documentElement;
        /* Write the two theme variants, never `--color-primary` itself: an inline
           value would outrank the `.dark` rule and leave dark mode on the light colour. */
        root.style.setProperty('--color-primary-strong', primary);
        root.style.setProperty('--color-primary-soft', darkThemeVariant(primary));
        root.style.setProperty('--color-primary-hover-strong', hoverVariant(primary));
        root.style.setProperty('--color-primary-hover-soft', hoverVariant(darkThemeVariant(primary)));
        root.style.setProperty('--color-on-primary-strong', readableForeground(primary));
        root.style.setProperty('--color-on-primary-soft', readableForeground(darkThemeVariant(primary)));
        root.style.setProperty('--color-secondary', secondary);
        root.style.setProperty('--color-accent-strong', accent);
        root.style.setProperty('--color-accent-soft', darkThemeVariant(accent));

        // A light sidebar colour is not carried into dark mode; the dark surface stands in for it.
        const sidebarDark = isLight(sidebarColor) ? '#0b1020' : sidebarColor;
        root.style.setProperty('--sidebar-bg-light', sidebarColor);
        root.style.setProperty('--sidebar-bg-dark', sidebarDark);
        root.style.setProperty('--sidebar-fg-light', readableForeground(sidebarColor));
        root.style.setProperty('--sidebar-fg-dark', readableForeground(sidebarDark));
        root.style.setProperty('--sidebar-accent-light', sidebarAccent(primary, sidebarColor));
        root.style.setProperty('--sidebar-accent-dark', sidebarAccent(darkThemeVariant(primary), sidebarDark));
        root.dataset.buttonStyle = buttonStyle;
        root.dataset.cardRadius = cardRadius;
        root.dataset.tableDensity = tableDensity;
        root.dataset.stickyTables = String(stickyTableHeaders);
        root.dataset.animations = String(enableAnimations);

        // Signing out lands on the sign-in page, which keeps the shipped look.
        return () => {
            for (const name of SHELL_ROOT_PROPERTIES) root.style.removeProperty(name);
            for (const key of SHELL_ROOT_DATASET) delete root.dataset[key];
        };
    }, [primary, secondary, accent, sidebarColor, buttonStyle, cardRadius, tableDensity, stickyTableHeaders, enableAnimations]);

    return (
        <LocalizedUiProvider>
            <div className="admin-shell min-h-screen bg-(--app-background)">
                <a href="#main-content" onClick={(event) => { event.preventDefault(); document.getElementById('main-content')?.focus(); }} className={buttonClassName({ variant: 'primary', className: 'sr-only fixed left-4 top-4 z-60' })}>
                    {t('publicSite.skipToContent')}
                </a>
                <AppToaster />

                {/* Desktop sidebar — fixed, does not scroll with page content */}
                <div className="hidden lg:block">
                    <div className={['fixed inset-y-0 left-0 z-30 flex h-screen flex-col', 'transition-[width] duration-200 ease-in-out overflow-hidden', collapsed ? 'w-16' : 'w-70'].join(' ')}>
                        <AppSidebar route={route} collapsed={collapsed} onToggleCollapse={toggleSidebarCollapse} />
                    </div>
                </div>

                {/* Mobile sidebar drawer. Headless UI owns focus trapping, Escape, scroll locking and focus restoration. */}
                <Dialog open={sidebarOpen} onClose={setSidebarOpen} className="relative z-50 lg:hidden">
                    <DialogBackdrop transition className="fixed inset-0 bg-slate-950/60 backdrop-blur-[2px] transition-opacity duration-200 data-closed:opacity-0" />
                    <div className="fixed inset-0 flex">
                        <DialogPanel transition className="h-full w-[min(20rem,calc(100vw-3rem))] overflow-hidden rounded-e-2xl shadow-2xl transition duration-200 ease-out data-closed:-translate-x-full">
                            <DialogTitle className="sr-only">{t('publicSite.mainNavigation')}</DialogTitle>
                            <AppSidebar route={route} onClose={() => setSidebarOpen(false)} />
                        </DialogPanel>
                    </div>
                </Dialog>

                {/* Main area — offset by sidebar width on desktop, scrolls independently */}
                <div className={['flex min-h-screen flex-col', 'transition-[margin-left] duration-200 ease-in-out', collapsed ? 'lg:ml-16' : 'lg:ml-70'].join(' ')}>
                    <AppHeader onMenuClick={() => setSidebarOpen(true)} onToggleSidebar={toggleSidebarCollapse} sidebarCollapsed={collapsed} />

                    {maintenanceEnabled && maintenanceMessage && (
                        <div role="status" className="shrink-0 border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                            {maintenanceMessage}
                        </div>
                    )}

                    {/* Breadcrumbs sit above the title they lead to, in the same band. */}
                    {header && (
                        <div className="app-page-heading app-dot-grid shrink-0 border-b border-(--app-border) bg-(--app-surface)">
                            <div className={CONTENT_WIDTH + ' px-4 py-5 sm:px-6 lg:px-8'}>
                                {showBreadcrumbs && <Breadcrumbs route={route} className="mb-2.5" />}
                                {header}
                            </div>
                        </div>
                    )}

                    <main id="main-content" tabIndex={-1} className="min-w-0 flex-1 px-4 py-6 outline-hidden sm:px-6 lg:px-8">
                        <div className={CONTENT_WIDTH}>
                            {!header && showBreadcrumbs && <Breadcrumbs route={route} className="mb-4" />}
                            {children}
                        </div>
                    </main>

                    <footer className="shrink-0 border-t border-(--app-border) px-4 py-3 text-xs text-(--app-muted-foreground) sm:px-6 lg:px-8">
                        <p className={CONTENT_WIDTH}>{t('common.designCredit')}</p>
                    </footer>
                </div>
            </div>
        </LocalizedUiProvider>
    );
}
