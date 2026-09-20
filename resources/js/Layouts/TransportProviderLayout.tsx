import { Link, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import ThemeToggle from '@/Components/ThemeToggle';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import AppToaster from '@/Components/ui/AppToaster';
import LocalizedUiProvider from '@/Components/ui/LocalizedUiProvider';
import Dropdown from '@/Components/Dropdown';
import { ActivityIcon, ChevronDown, LayoutDashboard, LogOut, MenuIcon, QrCodeIcon, ReceiptTextIcon, ScrollText, UserIcon, X } from '@/Components/Icons';
import type { PageProps } from '@/types';

type ProviderPortal = {
    user?: { name?: string | null; email?: string | null; initials?: string | null } | null;
    provider?: { code?: string | null; name_en?: string | null; name_am?: string | null } | null;
};

const NAV = [
    ['provider.portal.transport.dashboard', 'transport.dashboard', LayoutDashboard],
    ['provider.portal.transport.scan', 'transport.scan_id', QrCodeIcon],
    ['provider.portal.transport.transactions.index', 'transport.transactions', ReceiptTextIcon],
    ['provider.portal.transport.routes.index', 'transport.routes', ScrollText],
    ['provider.portal.transport.vehicles.index', 'transport.vehicles', ActivityIcon],
    ['provider.portal.transport.drivers.index', 'transport.drivers', UserIcon],
    ['provider.portal.transport.trips.index', 'transport.trips', ActivityIcon],
    ['provider.portal.transport.reports.index', 'transport.reports', ScrollText],
] as const;

function BusIcon(props: React.SVGProps<SVGSVGElement>) {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...props}>
            <path d="M6 17h12M6 6h12M5 11h14M7 21h.01M17 21h.01" />
            <rect x="4" y="3" width="16" height="15" rx="2" />
        </svg>
    );
}

function Sidebar({ onClose }: { onClose?: () => void }) {
    const { t, locale } = useLocale();
    const { providerPortal } = usePage<PageProps & { providerPortal?: ProviderPortal | null }>().props;
    const provider = providerPortal?.provider;
    const providerName = locale === 'am' && provider?.name_am ? provider.name_am : provider?.name_en;
    const currentRoute = route().current();

    return (
        <aside className="flex h-full w-full flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            <div className="flex h-16 items-center gap-3 border-b border-slate-200 px-4 dark:border-slate-800">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-600 text-white">
                    <BusIcon className="h-5 w-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-slate-950 dark:text-white">{t('transport.portal')}</p>
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">{providerName ?? provider?.code}</p>
                </div>
                {onClose && (
                    <button type="button" onClick={onClose} className="rounded-md p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">
                        <X className="h-4 w-4" />
                    </button>
                )}
            </div>
            <nav className="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                {NAV.map(([routeName, labelKey, Icon]) => {
                    const active = currentRoute === routeName;

                    return (
                        <Link
                            key={routeName}
                            href={route(routeName)}
                            className={[
                                'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
                                active
                                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
                            ].join(' ')}
                        >
                            <Icon className="h-4 w-4" />
                            <span className="truncate">{t(labelKey)}</span>
                        </Link>
                    );
                })}
            </nav>
            <div className="border-t border-slate-200 px-4 py-3 dark:border-slate-800">
                <span className="inline-flex rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                    {t('transport.badge')}
                </span>
            </div>
        </aside>
    );
}

function TopBar({ title, onMenuClick }: { title: string; onMenuClick: () => void }) {
    const { t } = useLocale();
    const { providerPortal } = usePage<PageProps & { providerPortal?: ProviderPortal | null }>().props;
    const user = providerPortal?.user;
    const initials = user?.initials ?? (user?.name ?? 'TP').split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase();

    return (
        <header className="sticky top-0 z-10 flex h-14 items-center gap-3 border-b border-slate-200 bg-white px-4 dark:border-slate-800 dark:bg-slate-900">
            <button type="button" onClick={onMenuClick} className="rounded-md p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 lg:hidden">
                <MenuIcon className="h-5 w-5" />
            </button>
            <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{title}</p>
            <div className="flex-1" />
            <LanguageSwitcher />
            <ThemeToggle />
            <Dropdown>
                <Dropdown.Trigger>
                    <button type="button" className="flex items-center gap-2 rounded-md px-2 py-1 hover:bg-slate-100 dark:hover:bg-slate-800">
                        <span className="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-600 text-xs font-semibold text-white">{initials}</span>
                        <ChevronDown className="h-4 w-4 text-slate-400" />
                    </button>
                </Dropdown.Trigger>
                <Dropdown.Content>
                    <div className="px-4 py-2 text-sm text-slate-700 dark:text-slate-200">{user?.name}</div>
                    <Dropdown.Link href={route('provider.portal.logout')} method="post" as="button">
                        <span className="flex items-center gap-2 text-red-600"><LogOut className="h-4 w-4" /> {t('providerPortal.logout')}</span>
                    </Dropdown.Link>
                </Dropdown.Content>
            </Dropdown>
        </header>
    );
}

export default function TransportProviderLayout({ title, header, children }: PropsWithChildren<{ title: string; header?: ReactNode }>) {
    const [open, setOpen] = useState(false);

    return (
        <LocalizedUiProvider>
        <div className="min-h-screen bg-slate-50 dark:bg-slate-950">
            <AppToaster />
            <div className="hidden lg:fixed lg:inset-y-0 lg:left-0 lg:block lg:w-64">
                <Sidebar />
            </div>
            {open && (
                <div className="lg:hidden">
                    <div className="fixed inset-0 z-20 bg-black/40" onClick={() => setOpen(false)} />
                    <div className="fixed inset-y-0 left-0 z-30 w-64 shadow-xl">
                        <Sidebar onClose={() => setOpen(false)} />
                    </div>
                </div>
            )}
            <div className="flex min-h-screen flex-col lg:ml-64">
                <TopBar title={title} onMenuClick={() => setOpen(true)} />
                {header && <div className="border-b border-slate-200 bg-white px-4 py-4 dark:border-slate-800 dark:bg-slate-900">{header}</div>}
                <main className="flex-1 px-4 py-6 sm:px-6 lg:px-8">{children}</main>
            </div>
        </div>
        </LocalizedUiProvider>
    );
}
