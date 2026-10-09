import { PropsWithChildren, ReactNode, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import AppToaster from '@/Components/ui/AppToaster';
import LocalizedUiProvider from '@/Components/ui/LocalizedUiProvider';
import ThemeToggle from '@/Components/ThemeToggle';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import UserAvatar from '@/Components/UserAvatar';
import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import { ChevronDown, LogOut, SettingsIcon, MenuIcon, X } from '@/Components/Icons';
import type { PageProps } from '@/types';

// ── sidebar nav items ────────────────────────────────────────────────────────
const NAV_ITEMS = [
    {
        labelKey: 'cafeteria.providerDashboard',
        routeName: 'cafeteria.providers.dashboard',
        icon: (
            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.75} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
            </svg>
        ),
    },
    {
        labelKey: 'cafeteria.providers',
        routeName: 'cafeteria.providers.index',
        icon: (
            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.75} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
        ),
    },
    {
        labelKey: 'cafeteria.providerUsers',
        routeName: 'cafeteria.settings.index',
        query: '?tab=provider-users',
        icon: (
            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.75} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
        ),
    },
    {
        labelKey: 'cafeteria.settings',
        routeName: 'cafeteria.settings.index',
        icon: (
            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.75} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.75} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
        ),
    },
] as const;

// ── helpers ──────────────────────────────────────────────────────────────────
function isActive(routeName: string): boolean {
    const current = route().current() ?? '';
    if (routeName === 'cafeteria.providers.dashboard') return current === routeName;
    if (routeName === 'cafeteria.providers.index') {
        return current.startsWith('cafeteria.providers') && current !== 'cafeteria.providers.dashboard';
    }
    return current === routeName;
}

// ── sidebar ──────────────────────────────────────────────────────────────────
function Sidebar({ onClose }: { onClose?: () => void }) {
    const { t } = useLocale();

    return (
        <aside className="flex h-full flex-col text-white" style={{ background: 'linear-gradient(180deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%)' }}>
            {/* Brand */}
            <div className="flex h-14 shrink-0 items-center justify-between border-b border-slate-700/60 px-4">
                <Link href={route('cafeteria.providers.dashboard')} className="flex items-center gap-2.5">
                    <span className="flex h-8 w-8 items-center justify-center rounded-card bg-gradient-to-br from-orange-400 to-orange-600 shadow-lg">
                        <svg className="h-4 w-4 text-white" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </span>
                    <div className="leading-tight">
                        <p className="text-xs font-semibold text-white">Cafeteria</p>
                        <p className="text-[10px] text-slate-400">Provider Admin</p>
                    </div>
                </Link>
                {onClose && (
                    <button type="button" onClick={onClose} className="rounded p-1 text-slate-400 hover:text-white">
                        <X className="h-4 w-4" />
                    </button>
                )}
            </div>

            {/* Nav */}
            <nav className="flex-1 overflow-y-auto px-3 py-4">
                <ul className="space-y-0.5">
                    {NAV_ITEMS.map((item) => {
                        const active = isActive(item.routeName);
                        return (
                            <li key={item.routeName + (item as {query?: string}).query}>
                                <Link
                                    href={route(item.routeName) + ((item as {query?: string}).query ?? '')}
                                    className={[
                                        'group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors',
                                        active
                                            ? 'bg-orange-500 text-white shadow-md shadow-orange-500/25'
                                            : 'text-slate-300 hover:bg-white/10 hover:text-white',
                                    ].join(' ')}
                                >
                                    <span className={active ? 'text-white' : 'text-slate-500 group-hover:text-white'}>
                                        {item.icon}
                                    </span>
                                    {t(item.labelKey as Parameters<typeof t>[0])}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>

            {/* Footer — back to main admin */}
            <div className="shrink-0 border-t border-slate-700/60 px-3 py-3">
                <Link
                    href={route('cafeteria.dashboard')}
                    className="flex items-center gap-2 rounded-lg px-3 py-2 text-xs text-slate-400 transition-colors hover:bg-slate-800 hover:text-white"
                >
                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 17l-5-5m0 0l5-5m-5 5h12" />
                    </svg>
                    {t('cafeteria.dashboard')}
                </Link>
            </div>
        </aside>
    );
}

// ── top bar ──────────────────────────────────────────────────────────────────
function TopBar({ onMenuClick }: { onMenuClick: () => void }) {
    const { auth } = usePage<PageProps>().props;
    const { t } = useLocale();
    const user = auth.user;

    return (
        <header className="flex h-14 shrink-0 items-center gap-3 border-b border-gray-200 bg-white px-4 sm:px-6 dark:border-slate-800 dark:bg-slate-900">
            {/* Mobile hamburger */}
            <button
                type="button"
                onClick={onMenuClick}
                className="rounded-md p-2 text-gray-500 hover:bg-gray-100 dark:text-slate-400 dark:hover:bg-slate-800 lg:hidden"
            >
                <MenuIcon className="h-5 w-5" />
            </button>

            <div className="flex-1" />

            {/* Right controls */}
            <div className="flex items-center gap-2">
                <LanguageSwitcher />
                <ThemeToggle />

                <Dropdown>
                    <Dropdown.Trigger>
                        <button type="button" className="inline-flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800">
                            <UserAvatar src={user?.profile_photo_url} name={user?.name ?? ''} size={28} />
                            <span className="hidden max-w-[120px] truncate sm:inline">{user?.name}</span>
                            <ChevronDown className="h-4 w-4 text-gray-400" />
                        </button>
                    </Dropdown.Trigger>
                    <Dropdown.Content contentClasses="py-1 bg-white dark:bg-slate-800 border border-gray-100 dark:border-slate-700 min-w-[180px]">
                        <div className="border-b border-gray-100 px-4 py-3 dark:border-slate-700">
                            <p className="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">{user?.name}</p>
                            <p className="truncate text-xs text-gray-500 dark:text-slate-400">{user?.email}</p>
                        </div>
                        <Dropdown.Link href={route('profile.edit')}>
                            <span className="flex items-center gap-2">
                                <SettingsIcon className="h-4 w-4" /> {t('common.profileSettings')}
                            </span>
                        </Dropdown.Link>
                        <Dropdown.Link href={route('logout')} method="post" as="button">
                            <span className="flex items-center gap-2">
                                <LogOut className="h-4 w-4" /> {t('common.signOut')}
                            </span>
                        </Dropdown.Link>
                    </Dropdown.Content>
                </Dropdown>
            </div>
        </header>
    );
}

// ── main layout ──────────────────────────────────────────────────────────────
type Props = PropsWithChildren<{
    title: string;
    header?: ReactNode;
}>;

export default function CafeteriaProviderAdminLayout({ title, header, children }: Props) {
    const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);

    return (
        <LocalizedUiProvider>
        <div className="min-h-screen bg-gray-50 dark:bg-slate-950">
            <AppToaster />

            {/* Desktop sidebar — fixed left */}
            <div className="hidden lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:flex lg:w-60 lg:flex-col">
                <Sidebar />
            </div>

            {/* Mobile sidebar drawer */}
            {mobileSidebarOpen && (
                <div className="lg:hidden">
                    <div
                        className="fixed inset-0 z-20 bg-black/50"
                        onClick={() => setMobileSidebarOpen(false)}
                    />
                    <div className="fixed inset-y-0 left-0 z-30 w-60 shadow-xl">
                        <Sidebar onClose={() => setMobileSidebarOpen(false)} />
                    </div>
                </div>
            )}

            {/* Main area — offset by sidebar */}
            <div className="flex min-h-screen flex-col lg:ml-60">
                <TopBar onMenuClick={() => setMobileSidebarOpen(true)} />

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
