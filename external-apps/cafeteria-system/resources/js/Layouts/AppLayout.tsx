import { useEffect, useState, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type AuthUser = { id: string; name: string; email: string; role: string };

type NamedRecord = { name: string; code: string };

/** Shared props this layout reads; pages add their own on top. */
type LayoutPageProps = {
    auth?: {
        user: AuthUser | null;
        can: { serve: boolean; manage: boolean } | null;
        context: { provider: NamedRecord | null; cafeteria: NamedRecord | null } | null;
    };
    flash?: { message?: string | null; type?: string | null };
    [key: string]: unknown;
};

type IconProps = { className?: string };

/*
 * Inline SVGs keep the sidebar dependency-free — no icon package is installed
 * in this application, and one nav bar does not justify adding one.
 */

const DashboardIcon = (p: IconProps) => (
    <svg {...p} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M4 5h6v6H4zM14 5h6v6h-6zM4 15h6v4H4zM14 15h6v4h-6z" />
    </svg>
);

const ScanIcon = (p: IconProps) => (
    <svg {...p} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M4 7V5a1 1 0 011-1h2M17 4h2a1 1 0 011 1v2M20 17v2a1 1 0 01-1 1h-2M7 20H5a1 1 0 01-1-1v-2M7 8h3v3H7zM14 8h3v3h-3zM7 14h3v3H7zM14 14h3v3h-3z" />
    </svg>
);

const ListIcon = (p: IconProps) => (
    <svg {...p} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5" />
    </svg>
);

const LedgerIcon = (p: IconProps) => (
    <svg {...p} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M5 4h11l3 3v13H5zM9 9h6M9 13h6M9 17h3" />
    </svg>
);

const ReportIcon = (p: IconProps) => (
    <svg {...p} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M3 12h4l2-5 3 10 2-6h7" />
    </svg>
);

const ProviderIcon = (p: IconProps) => (
    <svg {...p} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4a3 3 0 100 6 3 3 0 000-6zM6 20a6 6 0 0112 0M4 9l2 2M20 9l-2 2" />
    </svg>
);

const StoreIcon = (p: IconProps) => (
    <svg {...p} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M4 9l1-4h14l1 4M4 9h16v11H4zM9 20v-6h6v6" />
    </svg>
);

const BuildingIcon = (p: IconProps) => (
    <svg {...p} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M5 20V5a1 1 0 011-1h7a1 1 0 011 1v15M14 10h4a1 1 0 011 1v9M8 8h3M8 12h3M8 16h3" />
    </svg>
);

const UsersIcon = (p: IconProps) => (
    <svg {...p} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M9 11a3 3 0 100-6 3 3 0 000 6zM3 19a6 6 0 0112 0M17 11a3 3 0 10-1-5.83M21 19a5 5 0 00-4-4.9" />
    </svg>
);

const CashIcon = (p: IconProps) => (
    <svg {...p} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M3 7h18v10H3zM12 10a2 2 0 100 4 2 2 0 000-4z" />
    </svg>
);

const LogIcon = (p: IconProps) => (
    <svg {...p} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M8 4h9l3 3v13H8zM4 8v12h9M12 11h5M12 15h5" />
    </svg>
);

const CogIcon = (p: IconProps) => (
    <svg {...p} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9a3 3 0 100 6 3 3 0 000-6z" />
        <path strokeLinecap="round" strokeLinejoin="round" d="M19 12a7 7 0 00-.1-1.1l2-1.5-2-3.4-2.3 1a7 7 0 00-1.9-1.1L14.4 3H9.6l-.3 2.5a7 7 0 00-1.9 1.1l-2.3-1-2 3.4 2 1.5a7 7 0 000 2.2l-2 1.5 2 3.4 2.3-1a7 7 0 001.9 1.1l.3 2.3h4.8l.3-2.3a7 7 0 001.9-1.1l2.3 1 2-3.4-2-1.5c.06-.36.1-.73.1-1.1z" />
    </svg>
);

/**
 * Navigation grouped by purpose. `capability` hides a section from a role that
 * would only receive a 403 from it — the server still authorises every request.
 */
type NavItem = {
    labelKey: string;
    path: string;
    icon: (p: IconProps) => ReactNode;
    capability?: 'serve' | 'manage';
};

const NAV_GROUPS: Array<{ titleKey: string; items: NavItem[] }> = [
    {
        titleKey: 'nav.groupOperations',
        items: [
            { labelKey: 'nav.dashboard', path: '/', icon: DashboardIcon },
            { labelKey: 'nav.scan', path: '/scan', icon: ScanIcon, capability: 'serve' },
            { labelKey: 'nav.transactions', path: '/transactions', icon: ListIcon },
        ],
    },
    {
        titleKey: 'nav.groupFinance',
        items: [
            { labelKey: 'nav.ledger', path: '/ledger', icon: LedgerIcon },
            { labelKey: 'nav.reports', path: '/reports', icon: ReportIcon },
            { labelKey: 'nav.settlements', path: '/settlements', icon: CashIcon },
        ],
    },
    {
        titleKey: 'nav.groupAdministration',
        items: [
            { labelKey: 'nav.providers', path: '/providers', icon: ProviderIcon, capability: 'manage' },
            { labelKey: 'nav.cafeterias', path: '/cafeterias', icon: StoreIcon, capability: 'manage' },
            { labelKey: 'nav.assignments', path: '/assignments', icon: BuildingIcon, capability: 'manage' },
            { labelKey: 'nav.users', path: '/users', icon: UsersIcon, capability: 'manage' },
        ],
    },
    {
        titleKey: 'nav.groupSystem',
        items: [
            { labelKey: 'nav.apiLogs', path: '/api-logs', icon: LogIcon, capability: 'manage' },
            { labelKey: 'nav.settings', path: '/cafeteria-settings', icon: CogIcon, capability: 'manage' },
        ],
    },
];

const ROLE_LABEL_KEY: Record<string, string> = {
    provider_admin: 'users.roleProviderAdmin',
    cafeteria_manager: 'users.roleCafeteriaManager',
    scanner: 'users.roleScanner',
    report_viewer: 'users.roleReportViewer',
};

/** Two-letter monogram for the avatar; falls back to a neutral glyph. */
function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '·';
    }

    return (parts[0][0] + (parts[1]?.[0] ?? '')).toUpperCase();
}

export default function AppLayout({ title, children }: { title: string; children: ReactNode }) {
    const { t, locale, setLocale, localeOptions } = useLocale();
    const page = usePage<LayoutPageProps>();
    const user = page.props.auth?.user ?? null;
    const can = page.props.auth?.can ?? null;
    const context = page.props.auth?.context ?? null;
    const flash = page.props.flash ?? null;
    const current = page.url.split('?')[0];

    // Off-canvas on mobile, always visible from `lg` upwards.
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [profileOpen, setProfileOpen] = useState(false);
    const [flashVisible, setFlashVisible] = useState(true);

    // Re-show the toast whenever a new message arrives, and auto-dismiss it so
    // it never sits over the scan result an operator is reading.
    useEffect(() => {
        if (!flash?.message) {
            return;
        }

        setFlashVisible(true);
        const timer = window.setTimeout(() => setFlashVisible(false), 5000);

        return () => window.clearTimeout(timer);
    }, [flash?.message]);

    // Close the profile menu on an outside click or Escape.
    useEffect(() => {
        if (!profileOpen) {
            return;
        }

        const close = (event: MouseEvent | KeyboardEvent) => {
            if (event instanceof KeyboardEvent && event.key !== 'Escape') {
                return;
            }

            setProfileOpen(false);
        };

        document.addEventListener('click', close);
        document.addEventListener('keydown', close);

        return () => {
            document.removeEventListener('click', close);
            document.removeEventListener('keydown', close);
        };
    }, [profileOpen]);

    const visibleGroups = NAV_GROUPS
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => item.capability === undefined || can?.[item.capability]),
        }))
        .filter((group) => group.items.length > 0);

    const navLink = (item: NavItem) => {
        const active = current === item.path;
        const Icon = item.icon;

        return (
            <Link
                key={item.path}
                href={item.path}
                onClick={() => setSidebarOpen(false)}
                aria-current={active ? 'page' : undefined}
                className={[
                    'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                    active
                        ? 'bg-emerald-50 font-semibold text-emerald-700'
                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                ].join(' ')}
            >
                <Icon
                    className={[
                        'h-[18px] w-[18px] shrink-0',
                        active ? 'text-emerald-600' : 'text-slate-400 group-hover:text-slate-600',
                    ].join(' ')}
                />
                <span className="truncate">{t(item.labelKey)}</span>
            </Link>
        );
    };

    return (
        <div className="min-h-screen bg-gray-50">
            {/* Mobile backdrop */}
            {sidebarOpen && (
                <button
                    type="button"
                    aria-label={t('nav.closeMenu')}
                    onClick={() => setSidebarOpen(false)}
                    className="fixed inset-0 z-20 bg-slate-900/40 lg:hidden"
                />
            )}

            {/* ── Sidebar ── */}
            <aside
                className={[
                    'fixed inset-y-0 left-0 z-30 flex h-screen w-64 flex-col border-r border-gray-200 bg-white transition-transform',
                    sidebarOpen ? 'translate-x-0' : '-translate-x-full',
                    'lg:translate-x-0',
                ].join(' ')}
            >
                <div className="flex h-14 shrink-0 items-center gap-2 border-b border-gray-200 px-4">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-600 text-sm font-bold text-white">
                        C
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-semibold text-slate-900">{t('app.name')}</span>
                        <span className="block truncate text-[10px] text-slate-500">{t('app.tagline')}</span>
                    </span>
                </div>

                {/* Which service point this terminal is bound to. */}
                {context?.cafeteria && (
                    <div className="shrink-0 border-b border-gray-100 bg-emerald-50/50 px-4 py-2.5">
                        <p className="text-[10px] font-semibold uppercase tracking-wide text-emerald-700">
                            {t('nav.servingAt')}
                        </p>
                        <p className="truncate text-sm font-medium text-slate-900">{context.cafeteria.name}</p>
                        {context.provider && (
                            <p className="truncate text-[11px] text-slate-500">{context.provider.name}</p>
                        )}
                    </div>
                )}

                <nav aria-label={t('nav.mainNavigation')} className="flex-1 overflow-y-auto px-2 py-3">
                    {visibleGroups.map((group) => (
                        <div key={group.titleKey} className="mb-3 last:mb-0">
                            <p className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">
                                {t(group.titleKey)}
                            </p>
                            <div className="space-y-0.5">{group.items.map(navLink)}</div>
                        </div>
                    ))}
                </nav>

                {user && (
                    <Link
                        href="/profile"
                        onClick={() => setSidebarOpen(false)}
                        className="flex shrink-0 items-center gap-2.5 border-t border-gray-200 p-3 transition-colors hover:bg-slate-50"
                    >
                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-200 text-xs font-bold text-slate-700">
                            {initials(user.name)}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium text-slate-900">{user.name}</span>
                            <span className="block truncate text-xs text-slate-500">
                                {t(ROLE_LABEL_KEY[user.role] ?? 'common.role')}
                            </span>
                        </span>
                    </Link>
                )}
            </aside>

            {/* ── Content column, offset by the fixed sidebar ── */}
            <div className="flex min-h-screen flex-col lg:ml-64">
                <header className="sticky top-0 z-10 flex h-14 shrink-0 items-center gap-3 border-b border-gray-200 bg-white px-4 sm:px-6">
                    <button
                        type="button"
                        onClick={() => setSidebarOpen(true)}
                        aria-label={t('nav.openMenu')}
                        className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden"
                    >
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <h1 className="min-w-0 flex-1 truncate text-sm font-semibold text-slate-900">{title}</h1>

                    <div
                        className="flex items-center gap-1 rounded-lg bg-slate-100 p-0.5"
                        role="group"
                        aria-label={t('nav.language')}
                    >
                        {localeOptions.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => setLocale(option.value)}
                                aria-pressed={locale === option.value}
                                className={[
                                    'rounded-md px-2 py-1 text-xs font-semibold transition-colors',
                                    locale === option.value
                                        ? 'bg-white text-emerald-700 shadow-sm'
                                        : 'text-slate-500 hover:text-slate-800',
                                ].join(' ')}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>

                    {user && (
                        <div className="relative">
                            <button
                                type="button"
                                onClick={(event) => {
                                    // Stop the document listener closing it in the same tick.
                                    event.stopPropagation();
                                    setProfileOpen((open) => !open);
                                }}
                                aria-haspopup="menu"
                                aria-expanded={profileOpen}
                                className="flex items-center gap-2 rounded-lg p-1 pr-2 transition-colors hover:bg-slate-100"
                            >
                                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-600 text-xs font-bold text-white">
                                    {initials(user.name)}
                                </span>
                                <span className="hidden max-w-[10rem] truncate text-sm text-slate-700 sm:inline">
                                    {user.name}
                                </span>
                            </button>

                            {profileOpen && (
                                <div
                                    role="menu"
                                    onClick={(event) => event.stopPropagation()}
                                    className="absolute right-0 top-full z-20 mt-1 w-56 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg"
                                >
                                    <div className="border-b border-gray-100 px-4 py-3">
                                        <p className="truncate text-sm font-semibold text-slate-900">{user.name}</p>
                                        <p className="truncate text-xs text-slate-500">{user.email}</p>
                                        <p className="mt-1 inline-block rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600">
                                            {t(ROLE_LABEL_KEY[user.role] ?? 'common.role')}
                                        </p>
                                    </div>

                                    <Link
                                        href="/profile"
                                        onClick={() => setProfileOpen(false)}
                                        className="block px-4 py-2.5 text-sm text-slate-700 transition-colors hover:bg-slate-50"
                                    >
                                        {t('nav.myProfile')}
                                    </Link>

                                    <button
                                        type="button"
                                        onClick={() => router.post('/logout')}
                                        className="block w-full border-t border-gray-100 px-4 py-2.5 text-left text-sm text-red-600 transition-colors hover:bg-red-50"
                                    >
                                        {t('nav.signOut')}
                                    </button>
                                </div>
                            )}
                        </div>
                    )}
                </header>

                {/* Flash toast. The layout previously rendered nothing, so a
                    successful save gave the operator no confirmation at all. */}
                {flash?.message && flashVisible && (
                    <div
                        role="status"
                        className={[
                            'mx-4 mt-4 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm sm:mx-6',
                            flash.type === 'error'
                                ? 'border-red-200 bg-red-50 text-red-800'
                                : 'border-emerald-200 bg-emerald-50 text-emerald-800',
                        ].join(' ')}
                    >
                        <span className="flex-1">{flash.message}</span>
                        <button
                            type="button"
                            onClick={() => setFlashVisible(false)}
                            aria-label={t('nav.dismiss')}
                            className="shrink-0 opacity-60 transition-opacity hover:opacity-100"
                        >
                            &#10005;
                        </button>
                    </div>
                )}

                <main className="flex-1 p-4 sm:p-6">{children}</main>
            </div>
        </div>
    );
}
