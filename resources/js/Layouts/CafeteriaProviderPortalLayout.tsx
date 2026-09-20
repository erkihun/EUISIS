import { Link, router, usePage } from '@inertiajs/react';
import { CSSProperties, PropsWithChildren, ReactNode, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import ThemeToggle from '@/Components/ThemeToggle';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import AppToaster from '@/Components/ui/AppToaster';
import LocalizedUiProvider from '@/Components/ui/LocalizedUiProvider';
import Dropdown from '@/Components/Dropdown';
import {
    LayoutDashboard, QrCodeIcon, ReceiptTextIcon, ScrollText,
    Inbox, ActivityIcon, UserIcon, LogOut, MenuIcon, X, ChevronDown,
} from '@/Components/Icons';
import type { PageProps } from '@/types';

// ─────────────────────────────────────────────────────────────────────────────
const PRIMARY = '#f97316';   // orange-500
const ACCENT  = '#ea580c';   // orange-600
const SIDEBAR_KEY = 'portal-sidebar-v2';

// ── Types ────────────────────────────────────────────────────────────────────
type Provider = { id: string; code: string; name_en: string; name_am?: string | null };

// ── Portal nav items ─────────────────────────────────────────────────────────
const NAV = [
    { routeName: 'provider.portal.dashboard', labelKey: 'providerPortal.dashboard', Icon: LayoutDashboard,  color: 'text-violet-500' },
    { routeName: 'provider.portal.scan', labelKey: 'providerPortal.scan', Icon: QrCodeIcon,       color: 'text-orange-500' },
    { routeName: 'provider.portal.transactions.index', labelKey: 'providerPortal.transactions', Icon: ReceiptTextIcon, color: 'text-blue-500'   },
    { routeName: 'provider.portal.menus.index', labelKey: 'providerPortal.menus', Icon: ForkKnifeIcon,    color: 'text-green-500'  },
    { routeName: 'provider.portal.orders.index', labelKey: 'providerPortal.orders', Icon: Inbox,            color: 'text-amber-500'  },
    { routeName: 'provider.portal.ledger.index', labelKey: 'providerPortal.ledger', Icon: ScrollText,       color: 'text-cyan-500'   },
    { routeName: 'provider.portal.reports.index', labelKey: 'providerPortal.reports', Icon: ActivityIcon,     color: 'text-rose-500'   },
    { routeName: 'provider.portal.profile.show', labelKey: 'providerPortal.profile', Icon: UserIcon,         color: 'text-slate-400'  },
] as const;

// ── Inline mini-icons ─────────────────────────────────────────────────────────
function ForkKnifeIcon(p: React.SVGProps<SVGSVGElement>) {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}
            strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 002-2V2" />
            <path d="M7 2v20M21 15V2a5 5 0 00-5 5v6c0 1.1.9 2 2 2h3zm0 0v7" />
        </svg>
    );
}
function ChevronLeftSmall(p: React.SVGProps<SVGSVGElement>) {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.5}
            strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M15 18l-6-6 6-6" />
        </svg>
    );
}


// ─────────────────────────────────────────────────────────────────────────────
// SIDEBAR
// ─────────────────────────────────────────────────────────────────────────────
function Sidebar({
    providers, selectedProviderId, collapsed, onToggle, onClose,
}: {
    providers: Provider[];
    selectedProviderId?: string | null;
    collapsed: boolean;
    onToggle: () => void;
    onClose?: () => void;
}) {
    const { t, locale } = useLocale();
    const currentRoute = route().current();
    const page = usePage();
    const selected = providers.find(p => p.id === selectedProviderId) ?? providers[0];
    const providerName = (p: Provider) => (locale === 'am' && p.name_am) ? p.name_am : p.name_en;

    function switchProvider(id: string) {
        router.get(page.url.split('?')[0], { provider_id: id }, { preserveScroll: true });
    }

    return (
        <div className="flex h-full w-full flex-col bg-white dark:bg-slate-950 border-r border-gray-200 dark:border-slate-800">

            {/* ── Brand header ── */}
            <div className={[
                'flex h-[60px] shrink-0 items-center border-b border-gray-200 dark:border-slate-800',
                collapsed ? 'justify-center px-0' : 'px-4 gap-3',
            ].join(' ')}>
                {collapsed ? (
                    /* Icon-only: clicking expands */
                    <button onClick={onToggle} title="Expand" aria-label="Expand sidebar"
                        className="flex h-10 w-10 items-center justify-center rounded-card transition-transform hover:scale-105 focus:outline-none"
                        style={{ background: `linear-gradient(135deg,${PRIMARY},${ACCENT})` }}>
                        <svg className="h-5 w-5 text-white" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </button>
                ) : (
                    <>
                        {/* Logo + title */}
                        <div
                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-card shadow-md"
                            style={{ background: `linear-gradient(135deg,${PRIMARY},${ACCENT})` }}
                        >
                            <svg className="h-[18px] w-[18px] text-white" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="text-[14px] font-bold leading-tight text-gray-900 dark:text-white">Cafeteria</p>
                            <p className="text-[11px] leading-tight text-gray-500 dark:text-slate-500">Provider Portal</p>
                        </div>

                        {/* Collapse / close buttons */}
                        {onClose ? (
                            <button onClick={onClose} aria-label="Close"
                                className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-slate-800 dark:hover:text-slate-200 focus:outline-none">
                                <X className="h-4 w-4" />
                            </button>
                        ) : (
                            <button onClick={onToggle} title="Collapse" aria-label="Collapse sidebar"
                                className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-slate-800 dark:hover:text-slate-300 focus:outline-none">
                                <ChevronLeftSmall className="h-4 w-4" />
                            </button>
                        )}
                    </>
                )}
            </div>

            {/* ── Provider selector ── */}
            {providers.length > 0 && (
                <div className={[
                    'shrink-0 border-b border-gray-200 dark:border-slate-800',
                    collapsed ? 'flex justify-center py-3' : 'px-4 py-3',
                ].join(' ')}>
                    {collapsed ? (
                        /* Collapsed: just the initial badge */
                        selected && (
                            <div
                                title={providerName(selected)}
                                className="flex h-8 w-8 items-center justify-center rounded-lg text-[11px] font-bold text-white shadow-sm"
                                style={{ background: `linear-gradient(135deg,${PRIMARY},${ACCENT})` }}
                            >
                                {selected.code.slice(0, 2).toUpperCase()}
                            </div>
                        )
                    ) : (
                        <>
                            <p className="mb-1.5 text-[10px] font-semibold text-gray-400 dark:text-slate-600">
                                {t('providerPortal.provider')}
                            </p>
                            {providers.length > 1 ? (
                                <select
                                    value={selectedProviderId ?? ''}
                                    onChange={e => switchProvider(e.target.value)}
                                    className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm text-gray-900 focus:border-orange-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-orange-400/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                                >
                                    {providers.map(p => (
                                        <option key={p.id} value={p.id}>{providerName(p)}</option>
                                    ))}
                                </select>
                            ) : selected && (
                                <div className="flex items-center gap-2.5 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-2 dark:border-slate-700 dark:bg-slate-900/60">
                                    <div
                                        className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-[11px] font-bold text-white shadow-sm"
                                        style={{ background: `linear-gradient(135deg,${PRIMARY},${ACCENT})` }}
                                    >
                                        {selected.code.slice(0, 2).toUpperCase()}
                                    </div>
                                    <div className="min-w-0">
                                        <p className="truncate text-[12px] font-semibold text-gray-900 dark:text-slate-100">{providerName(selected)}</p>
                                        <p className="font-mono text-[10px] text-gray-400 dark:text-slate-500">{selected.code}</p>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>
            )}

            {/* ── Navigation ── */}
            <nav className="flex-1 overflow-y-auto py-3" aria-label="Portal navigation">
                {!collapsed && (
                    <p className="mb-1 px-4 text-[10px] font-semibold text-gray-400 dark:text-slate-600">
                        Menu
                    </p>
                )}
                <ul role="list" className={collapsed ? 'flex flex-col items-center gap-0.5 px-1.5' : 'space-y-0.5 px-2'}>
                    {NAV.map(({ routeName, labelKey, Icon, color }) => {
                        const isActive = currentRoute === routeName;
                        const label = t(labelKey as Parameters<typeof t>[0]);

                        if (collapsed) {
                            return (
                                <li key={routeName} className="w-full">
                                    <Link
                                        href={route(routeName)}
                                        title={label}
                                        aria-label={label}
                                        aria-current={isActive ? 'page' : undefined}
                                        className={[
                                            'relative flex h-10 w-full items-center justify-center rounded-card transition-all duration-150 focus:outline-none',
                                            isActive
                                                ? 'bg-orange-500/10 dark:bg-orange-500/15'
                                                : 'hover:bg-gray-100 dark:hover:bg-slate-800/70',
                                        ].join(' ')}
                                    >
                                        <Icon className={['h-[19px] w-[19px]', isActive ? 'text-orange-500' : color + ' opacity-70'].join(' ')} />
                                        {isActive && (
                                            <span className="absolute right-0 top-1/2 h-5 w-[3px] -translate-y-1/2 rounded-l-full bg-orange-500" />
                                        )}
                                    </Link>
                                </li>
                            );
                        }

                        return (
                            <li key={routeName}>
                                <Link
                                    href={route(routeName)}
                                    aria-current={isActive ? 'page' : undefined}
                                    className={[
                                        'group flex items-center gap-3 rounded-card px-3 py-2.5 text-[13.5px] font-medium transition-all duration-150 focus:outline-none',
                                        isActive
                                            ? 'bg-orange-500/10 text-orange-600 dark:bg-orange-500/15 dark:text-orange-400'
                                            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-800/70 dark:hover:text-slate-100',
                                    ].join(' ')}
                                >
                                    {/* icon wrapper */}
                                    <span className={[
                                        'flex h-7 w-7 shrink-0 items-center justify-center rounded-lg transition-colors',
                                        isActive
                                            ? 'bg-orange-500/15'
                                            : 'bg-gray-100 group-hover:bg-gray-200 dark:bg-slate-800 dark:group-hover:bg-slate-700',
                                    ].join(' ')}>
                                        <Icon className={['h-[15px] w-[15px]', isActive ? 'text-orange-500' : color].join(' ')} />
                                    </span>
                                    <span className="truncate">{label}</span>
                                    {isActive && (
                                        <span className="ml-auto h-1.5 w-1.5 rounded-full bg-orange-500" />
                                    )}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>

            {/* ── Footer ── */}
            {!collapsed && (
                <div className="shrink-0 border-t border-gray-200 px-4 py-3 dark:border-slate-800">
                    <p className="text-[11px] text-gray-400 dark:text-slate-600">© {new Date().getFullYear()} Provider Portal</p>
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// TOP BAR
// ─────────────────────────────────────────────────────────────────────────────
function TopBar({ onMenuClick, title }: { onMenuClick: () => void; title: string }) {
    const { cafeteriaProviderAuth } = usePage<PageProps & { cafeteriaProviderAuth?: { user?: { name?: string; email?: string; initials?: string } | null } }>().props;
    const { t } = useLocale();
    const user = cafeteriaProviderAuth?.user ?? null;

    const initials = user?.initials ?? (user?.name ?? 'P')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w: string) => w[0].toUpperCase())
        .join('');

    return (
        <header
            className="sticky top-0 z-10 flex h-14 shrink-0 items-center gap-3 border-b border-gray-200 bg-white/95 backdrop-blur-sm px-4 sm:px-6 dark:border-slate-800 dark:bg-slate-900/95"
            style={{ borderTop: `3px solid ${ACCENT}` }}
        >
            {/* Mobile hamburger */}
            <button
                type="button"
                onClick={onMenuClick}
                aria-label="Open sidebar"
                className="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none dark:text-slate-400 dark:hover:bg-slate-800 lg:hidden"
            >
                <MenuIcon className="h-5 w-5" />
            </button>

            {/* Page title — mobile only */}
            <span className="truncate text-sm font-semibold text-gray-800 dark:text-slate-200 lg:hidden">{title}</span>

            <div className="flex-1" />

            {/* Right side */}
            <div className="flex items-center gap-1">
                <LanguageSwitcher />
                <ThemeToggle />
                <span className="mx-1.5 h-5 w-px bg-gray-200 dark:bg-slate-700" />

                {/* ── User dropdown ── */}
                <Dropdown>
                    <Dropdown.Trigger>
                        <button
                            type="button"
                            className="flex items-center gap-2.5 rounded-card px-2 py-1.5 transition-colors hover:bg-gray-100 focus:outline-none dark:hover:bg-slate-800"
                        >
                            {/* Avatar */}
                            <span
                                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white ring-2 ring-orange-200 dark:ring-orange-900/60"
                                style={{ background: `linear-gradient(135deg,${PRIMARY},${ACCENT})` }}
                                aria-hidden="true"
                            >
                                {initials}
                            </span>

                            {/* Name + role badge */}
                            <div className="hidden text-left sm:block">
                                <p className="max-w-[130px] truncate text-[13px] font-semibold leading-tight text-gray-900 dark:text-slate-100">
                                    {user?.name ?? t('common.user')}
                                </p>
                                <p className="text-[10px] font-medium leading-tight" style={{ color: PRIMARY }}>
                                    Provider
                                </p>
                            </div>

                            <ChevronDown className="h-3.5 w-3.5 text-gray-400 dark:text-slate-500" />
                        </button>
                    </Dropdown.Trigger>

                    <Dropdown.Content contentClasses="py-1.5 bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-700/80 min-w-[230px] shadow-xl rounded-card">
                        {/* User card */}
                        <div className="mx-1.5 mb-1.5 rounded-lg bg-gradient-to-br from-orange-50 to-amber-50 p-3 dark:from-orange-950/30 dark:to-amber-950/20">
                            <div className="flex items-center gap-3">
                                <span
                                    className="flex h-11 w-11 shrink-0 items-center justify-center rounded-card text-sm font-bold text-white shadow-sm"
                                    style={{ background: `linear-gradient(135deg,${PRIMARY},${ACCENT})` }}
                                >
                                    {initials}
                                </span>
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-bold text-gray-900 dark:text-slate-100">
                                        {user?.name ?? '—'}
                                    </p>
                                    <p className="truncate text-xs text-gray-500 dark:text-slate-400">{user?.email ?? '—'}</p>
                                    <span
                                        className="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                        style={{ background: `color-mix(in srgb,${PRIMARY} 15%,transparent)`, color: ACCENT }}
                                    >
                                        <span className="h-1.5 w-1.5 rounded-full" style={{ background: PRIMARY }} />
                                        Provider
                                    </span>
                                </div>
                            </div>
                        </div>

                        <Dropdown.Link href={route('provider.portal.profile.show')}>
                            <span className="flex items-center gap-2.5">
                                <UserIcon className="h-4 w-4 text-gray-400" />
                                {t('providerPortal.profile')}
                            </span>
                        </Dropdown.Link>

                        <div className="mx-2 my-1 h-px bg-gray-100 dark:bg-slate-700/60" />

                        <Dropdown.Link href={route('provider.portal.logout')} method="post" as="button">
                            <span className="flex items-center gap-2.5 text-red-600 dark:text-red-400">
                                <LogOut className="h-4 w-4" />
                                {t('providerPortal.logout')}
                            </span>
                        </Dropdown.Link>
                    </Dropdown.Content>
                </Dropdown>
            </div>
        </header>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// LAYOUT
// ─────────────────────────────────────────────────────────────────────────────
type Props = PropsWithChildren<{
    title: string;
    header?: ReactNode;
    providers?: Provider[];
    selectedProviderId?: string | null;
}>;

export default function CafeteriaProviderPortalLayout({
    title, header, providers = [], selectedProviderId, children,
}: Props) {
    const [mobileOpen, setMobileOpen] = useState(false);
    const [collapsed, setCollapsed] = useState<boolean>(() => {
        try { return localStorage.getItem(SIDEBAR_KEY) === 'true'; } catch { return false; }
    });

    function toggleCollapse() {
        setCollapsed(prev => {
            const next = !prev;
            try { localStorage.setItem(SIDEBAR_KEY, String(next)); } catch {}
            return next;
        });
    }

    return (
        <LocalizedUiProvider>
        <div
            className="min-h-screen bg-gray-50 dark:bg-slate-950"
            style={{ '--color-primary': PRIMARY, '--color-accent': ACCENT } as CSSProperties}
        >
            <AppToaster />

            {/* ── Desktop sidebar (fixed, animated width) ── */}
            <div className={[
                'hidden lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:flex lg:flex-col',
                'transition-[width] duration-200 ease-in-out overflow-hidden',
                collapsed ? 'lg:w-[68px]' : 'lg:w-64',
            ].join(' ')}>
                <Sidebar
                    providers={providers}
                    selectedProviderId={selectedProviderId}
                    collapsed={collapsed}
                    onToggle={toggleCollapse}
                />
            </div>

            {/* ── Mobile sidebar drawer ── */}
            {mobileOpen && (
                <div className="lg:hidden">
                    <div
                        className="fixed inset-0 z-20 bg-black/40 backdrop-blur-sm"
                        onClick={() => setMobileOpen(false)}
                        aria-hidden="true"
                    />
                    <div className="fixed inset-y-0 left-0 z-30 w-64 shadow-2xl">
                        <Sidebar
                            providers={providers}
                            selectedProviderId={selectedProviderId}
                            collapsed={false}
                            onToggle={() => {}}
                            onClose={() => setMobileOpen(false)}
                        />
                    </div>
                </div>
            )}

            {/* ── Main content (offset by sidebar) ── */}
            <div className={[
                'flex min-h-screen flex-col',
                'transition-[margin-left] duration-200 ease-in-out',
                collapsed ? 'lg:ml-[68px]' : 'lg:ml-64',
            ].join(' ')}>
                <TopBar onMenuClick={() => setMobileOpen(true)} title={title} />

                {header && (
                    <div className="shrink-0 border-b border-gray-200 bg-white px-4 py-4 sm:px-6 dark:border-slate-800 dark:bg-slate-900">
                        {header}
                    </div>
                )}

                <main className="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    {children}
                </main>
            </div>
        </div>
        </LocalizedUiProvider>
    );
}
