import { PropsWithChildren, ReactNode, useEffect, useState } from 'react';
import AppSidebar from '@/Components/AppSidebar';
import AppHeader from '@/Components/AppHeader';
import Breadcrumbs from '@/Components/Breadcrumbs';
import AppToaster from '@/Components/ui/AppToaster';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { darkThemeVariant, isLight, readableForeground, sidebarAccent } from '@/lib/brandColor';

const SIDEBAR_STORAGE_KEY = 'euisis-sidebar-collapsed';

export default function Authenticated({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const { locale } = useLocale();
    const { getBoolean, getString } = useSystemSettings();

    /* Defaults mirror the brand tokens in app.css. They are repeated here
       because this effect writes them onto the root element unconditionally,
       so a stale default would silently override the stylesheet. */
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
    const maintenanceMessage =
        locale === 'am'
            ? getString('security.maintenance_banner_message_am')
            : getString('security.maintenance_banner_message_en');

    const [sidebarOpen, setSidebarOpen] = useState(false);

    const [sidebarCollapsed, setSidebarCollapsed] = useState<boolean>(() => {
        try {
            const stored = localStorage.getItem(SIDEBAR_STORAGE_KEY);
            if (stored !== null) return stored === 'true';
        } catch {}
        return sidebarCompactDefault;
    });

    const toggleSidebarCollapse = () => {
        setSidebarCollapsed((prev) => {
            const next = !prev;
            try { localStorage.setItem(SIDEBAR_STORAGE_KEY, String(next)); } catch {}
            return next;
        });
    };

    useEffect(() => {
        const root = document.documentElement;
        /* Write the two theme variants, never `--color-primary` itself: an
           inline value would outrank the `.dark` rule in app.css and leave
           dark mode stuck on the light-theme colour. */
        root.style.setProperty('--color-primary-strong', primary);
        root.style.setProperty('--color-primary-soft', darkThemeVariant(primary));
        root.style.setProperty('--color-secondary', secondary);
        root.style.setProperty('--color-accent-strong', accent);
        root.style.setProperty('--color-accent-soft', darkThemeVariant(accent));

        /*
         * Sidebar surface, per theme.
         *
         * Light mode uses the configured colour as chosen. Dark mode only
         * honours it when it is already dark — a white sidebar beside a dark
         * page is not a look anyone picks on purpose, so a light choice falls
         * back to the dark surface rather than overriding the theme.
         *
         * The foreground is derived from whichever background ends up in play,
         * which is what allows the setting to be a free colour picker: the
         * labels and icons follow it automatically instead of needing their
         * own setting (and their own way to be set wrong).
         */
        const sidebarDark = isLight(sidebarColor) ? '#0b1020' : sidebarColor;
        root.style.setProperty('--sidebar-bg-light', sidebarColor);
        root.style.setProperty('--sidebar-bg-dark', sidebarDark);
        root.style.setProperty('--sidebar-fg-light', readableForeground(sidebarColor));
        root.style.setProperty('--sidebar-fg-dark', readableForeground(sidebarDark));

        /* Selected-item colour, kept legible against whatever the sidebar is. */
        root.style.setProperty('--sidebar-accent-light', sidebarAccent(primary, sidebarColor));
        root.style.setProperty(
            '--sidebar-accent-dark',
            sidebarAccent(darkThemeVariant(primary), sidebarDark),
        );
        root.dataset.buttonStyle = buttonStyle;
        root.dataset.cardRadius = cardRadius;
        root.dataset.tableDensity = tableDensity;
        root.dataset.stickyTables = String(stickyTableHeaders);
        root.dataset.animations = String(enableAnimations);
    }, [primary, secondary, accent, sidebarColor, buttonStyle, cardRadius, tableDensity, stickyTableHeaders, enableAnimations]);

    return (
        <div className="min-h-screen bg-gray-50 dark:bg-slate-950">
            <AppToaster />

            {/* Desktop sidebar — fixed, does not scroll with page content */}
            <div className="hidden lg:block">
                <div
                    className={[
                        'fixed inset-y-0 left-0 z-30 flex h-screen flex-col',
                        'transition-[width] duration-200 ease-in-out overflow-hidden',
                        sidebarCollapsed ? 'w-16' : 'w-64',
                    ].join(' ')}
                >
                    <AppSidebar
                        collapsed={sidebarCollapsed}
                        onToggleCollapse={toggleSidebarCollapse}
                    />
                </div>
            </div>

            {/* Mobile sidebar drawer */}
            {sidebarOpen && (
                <div className="lg:hidden">
                    <div
                        className="fixed inset-0 z-20 bg-black/50"
                        onClick={() => setSidebarOpen(false)}
                        aria-hidden="true"
                    />
                    <div className="fixed inset-y-0 left-0 z-30 w-64 shadow-xl">
                        <AppSidebar onClose={() => setSidebarOpen(false)} />
                    </div>
                </div>
            )}

            {/* Main area — offset by sidebar width on desktop, scrolls independently */}
            <div
                className={[
                    'flex min-h-screen flex-col',
                    'transition-[margin-left] duration-200 ease-in-out',
                    sidebarCollapsed ? 'lg:ml-16' : 'lg:ml-64',
                ].join(' ')}
            >
                <AppHeader onMenuClick={() => setSidebarOpen(true)} />

                {maintenanceEnabled && maintenanceMessage && (
                    <div
                        role="status"
                        className="shrink-0 border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
                    >
                        {maintenanceMessage}
                    </div>
                )}

                {header && (
                    <div className="shrink-0 border-b border-gray-200 bg-white px-4 py-4 sm:px-6 dark:border-slate-800 dark:bg-slate-900">
                        {header}
                    </div>
                )}

                {showBreadcrumbs && <Breadcrumbs />}

                <main className="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    {children}
                </main>
            </div>
        </div>
    );
}
