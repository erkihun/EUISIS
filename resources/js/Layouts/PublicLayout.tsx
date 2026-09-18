import { ReactNode, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeToggle from '@/Components/ThemeToggle';
import UserAvatar from '@/Components/UserAvatar';
import Dropdown from '@/Components/Dropdown';
import {
    LayoutDashboard,
    MenuIcon,
    X,
    MegaphoneIcon,
    CreditCard,
    LogOut,
    SettingsIcon,
} from '@/Components/Icons';
import type { PageProps } from '@/types';
import { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement>;

function HouseIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
            <polyline points="9 22 9 12 15 12 15 22" />
        </svg>
    );
}

function BadgeCheckIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
            <polyline points="9 12 11 14 15 10" />
        </svg>
    );
}

function LayersIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <polygon points="12 2 2 7 12 12 22 7 12 2" />
            <polyline points="2 17 12 22 22 17" />
            <polyline points="2 12 12 17 22 12" />
        </svg>
    );
}

function LifeBuoyIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <circle cx="12" cy="12" r="10" />
            <circle cx="12" cy="12" r="4" />
            <line x1="4.93" y1="4.93" x2="9.17" y2="9.17" />
            <line x1="14.83" y1="14.83" x2="19.07" y2="19.07" />
            <line x1="14.83" y1="9.17" x2="19.07" y2="4.93" />
            <line x1="4.93" y1="19.07" x2="9.17" y2="14.83" />
        </svg>
    );
}

function LogInIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
            <polyline points="10 17 15 12 10 7" />
            <line x1="15" y1="12" x2="3" y2="12" />
        </svg>
    );
}

function UserPlusIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
            <circle cx="8.5" cy="7" r="4" />
            <line x1="20" y1="8" x2="20" y2="14" />
            <line x1="23" y1="11" x2="17" y2="11" />
        </svg>
    );
}

interface WelcomeProps extends PageProps {
    registration_enabled?: boolean;
    announcement_count?: number;
    is_employee_user?: boolean;
}

interface NavLinkProps {
    href: string;
    active?: boolean;
    icon: (p: IconProps) => JSX.Element;
    label: string;
    badge?: number;
    onClick?: () => void;
    mobile?: boolean;
}

function NavItem({ href, active, icon: Icon, label, badge, onClick, mobile }: NavLinkProps) {
    const base = mobile
        ? 'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors'
        : 'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]';

    const color = active
        ? 'bg-[color:var(--color-primary)]/10 text-[color:var(--color-primary)]'
        : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100';

    return (
        <Link href={href} className={`${base} ${color}`} onClick={onClick}>
            <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{label}</span>
            {badge != null && badge > 0 && (
                <span className="ml-0.5 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-[color:var(--color-primary)] px-1 text-[10px] font-bold text-white">
                    {badge > 99 ? '99+' : badge}
                </span>
            )}
        </Link>
    );
}

interface Props {
    title: string;
    children: ReactNode;
}

export default function PublicLayout({ title, children }: Props) {
    const { auth, registration_enabled, announcement_count, is_employee_user } = usePage<WelcomeProps>().props;
    const isAuthenticated = Boolean(auth?.user);
    const user = auth?.user ?? null;
    const { t } = useLocale();
    const { getString } = useSystemSettings();
    const [mobileOpen, setMobileOpen] = useState(false);

    const appName = getString('app.short_name', 'AA Employee ID');
    const logoUrl = getString('general.identity_system_logo_url');
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

    const isActive = (path: string) => currentPath === path || currentPath.startsWith(path + '/');

    const navLinks = [
        { href: '/', icon: HouseIcon, labelKey: 'nav.home', active: currentPath === '/' },
        { href: '/announcements', icon: MegaphoneIcon, labelKey: 'nav.announcements', badge: announcement_count, active: isActive('/announcements') },
        { href: '/verify', icon: BadgeCheckIcon, labelKey: 'nav.verifyIdCard', active: isActive('/verify') },
        { href: '/services', icon: LayersIcon, labelKey: 'nav.services', active: isActive('/services') },
        { href: '/support', icon: LifeBuoyIcon, labelKey: 'nav.support', active: isActive('/support') },
    ];

    return (
        <>
            <Head title={title} />

            <header
                className="sticky top-0 z-40 w-full border-b border-gray-200 bg-white/95 backdrop-blur-sm dark:border-slate-800 dark:bg-slate-950/95"
                style={{ borderTop: '3px solid var(--color-accent, #d12908)' }}
            >
                <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                    {/* Logo */}
                    <Link href="/" className="flex items-center gap-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] rounded-lg">
                        {logoUrl ? (
                            <img src={logoUrl} alt="" className="h-9 w-auto max-w-[88px] object-contain" />
                        ) : (
                            <CreditCard className="h-8 w-8 text-orange-600 dark:text-orange-400" aria-hidden="true" />
                        )}
                        <span className="hidden text-sm font-bold text-gray-900 sm:block dark:text-slate-100">{appName}</span>
                    </Link>

                    {/* Desktop nav */}
                    <nav className="hidden items-center gap-0.5 lg:flex" aria-label="Public navigation">
                        {navLinks.map(({ href, icon, labelKey, badge, active }) => (
                            <NavItem key={href} href={href} icon={icon} label={t(labelKey)} badge={badge} active={active} />
                        ))}
                    </nav>

                    {/* Right controls */}
                    <div className="hidden items-center gap-1.5 sm:flex">
                        <LanguageSwitcher />
                        <ThemeToggle />

                        {isAuthenticated && user ? (
                            <>
                                {is_employee_user && (
                                    <NavItem href={route('employee.portal')} icon={LayoutDashboard} label={t('nav.myPortal') || 'My Portal'} active={isActive('/my-portal')} />
                                )}
                                {!is_employee_user && (
                                    <NavItem href="/dashboard" icon={LayoutDashboard} label={t('nav.dashboard')} active={isActive('/dashboard')} />
                                )}
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <button
                                            type="button"
                                            className="inline-flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
                                        >
                                            <UserAvatar src={user.profile_photo_url} name={user.name} size={28} />
                                            <span className="hidden max-w-[100px] truncate md:inline">{user.name}</span>
                                        </button>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content contentClasses="py-1 bg-white dark:bg-slate-800 border border-gray-100 dark:border-slate-700 min-w-[180px]">
                                        <div className="border-b border-gray-100 px-4 py-2 dark:border-slate-700">
                                            <p className="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">{user.name}</p>
                                            <p className="truncate text-xs text-gray-500 dark:text-slate-400">{user.email}</p>
                                        </div>
                                        <Dropdown.Link href={route('profile.edit')}>
                                            <span className="flex items-center gap-2"><SettingsIcon className="h-4 w-4" />{t('nav.profile')}</span>
                                        </Dropdown.Link>
                                        <Dropdown.Link href={route('logout')} method="post" as="button">
                                            <span className="flex items-center gap-2"><LogOut className="h-4 w-4" />{t('nav.logout')}</span>
                                        </Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </>
                        ) : (
                            <>
                                <Link
                                    href={route('login')}
                                    className="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
                                >
                                    <LogInIcon className="h-4 w-4" aria-hidden="true" />
                                    {t('nav.login')}
                                </Link>
                                {registration_enabled && (
                                    <Link
                                        href={route('register')}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-[color:var(--color-primary)] px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
                                    >
                                        <UserPlusIcon className="h-4 w-4" aria-hidden="true" />
                                        {t('nav.register')}
                                    </Link>
                                )}
                            </>
                        )}
                    </div>

                    {/* Mobile hamburger */}
                    <div className="flex items-center gap-1 sm:hidden">
                        <ThemeToggle />
                        <button
                            type="button"
                            onClick={() => setMobileOpen((v) => !v)}
                            className="rounded-md p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:text-slate-400 dark:hover:bg-slate-800"
                            aria-label={mobileOpen ? t('nav.closeMenu') : t('nav.openMenu')}
                            aria-expanded={mobileOpen}
                        >
                            {mobileOpen ? <X className="h-5 w-5" /> : <MenuIcon className="h-5 w-5" />}
                        </button>
                    </div>
                </div>

                {/* Mobile drawer */}
                {mobileOpen && (
                    <div className="border-t border-gray-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-950 sm:hidden">
                        <nav className="flex flex-col gap-1" aria-label="Mobile navigation">
                            {navLinks.map(({ href, icon, labelKey, badge, active }) => (
                                <NavItem key={href} href={href} icon={icon} label={t(labelKey)} badge={badge} active={active} mobile onClick={() => setMobileOpen(false)} />
                            ))}
                            <div className="my-2 h-px bg-gray-200 dark:bg-slate-800" />
                            <LanguageSwitcher />
                            {isAuthenticated && user ? (
                                <>
                                    {is_employee_user ? (
                                        <NavItem href={route('employee.portal')} icon={LayoutDashboard} label={t('nav.myPortal') || 'My Portal'} active={isActive('/my-portal')} mobile onClick={() => setMobileOpen(false)} />
                                    ) : (
                                        <NavItem href="/dashboard" icon={LayoutDashboard} label={t('nav.dashboard')} active={isActive('/dashboard')} mobile onClick={() => setMobileOpen(false)} />
                                    )}
                                    <Link
                                        href={route('logout')}
                                        method="post"
                                        as="button"
                                        className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-red-600 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30"
                                        onClick={() => setMobileOpen(false)}
                                    >
                                        <LogOut className="h-4 w-4" />{t('nav.logout')}
                                    </Link>
                                </>
                            ) : (
                                <>
                                    <Link
                                        href={route('login')}
                                        className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100 dark:text-slate-400 dark:hover:bg-slate-800"
                                        onClick={() => setMobileOpen(false)}
                                    >
                                        <LogInIcon className="h-4 w-4" />{t('nav.login')}
                                    </Link>
                                    {registration_enabled && (
                                        <Link
                                            href={route('register')}
                                            className="flex items-center gap-3 rounded-lg bg-[color:var(--color-primary)] px-3 py-2.5 text-sm font-semibold text-white"
                                            onClick={() => setMobileOpen(false)}
                                        >
                                            <UserPlusIcon className="h-4 w-4" />{t('nav.register')}
                                        </Link>
                                    )}
                                </>
                            )}
                        </nav>
                    </div>
                )}
            </header>

            <main className="min-h-screen bg-gray-50 dark:bg-slate-950">
                {children}
            </main>

            <footer className="border-t border-gray-200 bg-white py-6 dark:border-slate-800 dark:bg-slate-950">
                <p className="text-center text-xs text-gray-400 dark:text-slate-500">
                    &copy; {new Date().getFullYear()} {getString('general.organization_name', appName)}
                </p>
            </footer>
        </>
    );
}
