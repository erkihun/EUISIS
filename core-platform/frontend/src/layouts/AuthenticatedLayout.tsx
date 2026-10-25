import { useEffect, useState, type PropsWithChildren, type ReactNode } from 'react';
import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react';
import AppHeader from '../components/AppHeader';
import AppSidebar from '../components/AppSidebar';
import AppToaster from '../components/AppToaster';
import Breadcrumbs from '../components/Breadcrumbs';
import LocalizedUiProvider from '../components/LocalizedUiProvider';
import { useLocale } from '../i18n';
import { useRoute } from '../router';
import { buttonClassName } from '../ui';

const SIDEBAR_STORAGE_KEY = 'core-sidebar-collapsed';
// Page chrome and content share one measure, so titles line up with the content below them.
const CONTENT_WIDTH = 'mx-auto w-full max-w-[1600px]';

/**
 * EUISIS Layouts/AuthenticatedLayout.tsx: fixed sidebar (collapsible to an icon
 * rail, a drawer on phones), sticky header, the heading band with breadcrumbs,
 * the content measure and the footer credit. The shipped brand colours apply
 * (the EUISIS appearance settings are not part of the Core).
 */
export default function AuthenticatedLayout({ header, children }: PropsWithChildren<{ header?: ReactNode }>) {
    const { t } = useLocale();
    const route = useRoute();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [collapsed, setCollapsed] = useState<boolean>(() => {
        try {
            return localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'true';
        } catch {
            return false;
        }
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

    // The EUISIS shell defaults (System Settings › Appearance) on <html>.
    useEffect(() => {
        const root = document.documentElement;
        root.dataset.buttonStyle = 'rounded';
        root.dataset.cardRadius = 'xl';
        root.dataset.tableDensity = 'comfortable';
        root.dataset.stickyTables = 'true';
        root.dataset.animations = 'true';
    }, []);

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

                    {/* Breadcrumbs sit above the title they lead to, in the same band. */}
                    {header && (
                        <div className="app-page-heading app-dot-grid shrink-0 border-b border-(--app-border) bg-(--app-surface)">
                            <div className={CONTENT_WIDTH + ' px-4 py-5 sm:px-6 lg:px-8'}>
                                <Breadcrumbs route={route} className="mb-2.5" />
                                {header}
                            </div>
                        </div>
                    )}

                    <main id="main-content" tabIndex={-1} className="min-w-0 flex-1 px-4 py-6 outline-hidden sm:px-6 lg:px-8">
                        <div className={CONTENT_WIDTH}>
                            {!header && <Breadcrumbs route={route} className="mb-4" />}
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
