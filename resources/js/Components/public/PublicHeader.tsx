import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Dialog, DialogPanel } from '@headlessui/react';
import { buttonClassName } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeToggle from '@/Components/ThemeToggle';
import UserAvatar from '@/Components/UserAvatar';
import Dropdown from '@/Components/Dropdown';
import { LogOut, MenuIcon, SettingsIcon, X } from '@/Components/Icons';
import { useBilingual } from './bilingual';
import type { PageProps } from '@/types';

export type PublicLink = {
    id: string;
    label_en: string;
    label_am: string | null;
    href: string;
    route_name: string | null;
    external: boolean;
};

type SharedProps = PageProps & {
    publicSite?: { navigation: PublicLink[] };
    registration_enabled?: boolean;
    is_employee_user?: boolean;
};

/** Current path from Inertia, so the active state is correct on every visit. */
function usePath(): string {
    const { url } = usePage();
    return url.split('?')[0].split('#')[0] || '/';
}

function isActive(path: string, href: string): boolean {
    if (href === '/') return path === '/';
    return path === href || path.startsWith(`${href}/`);
}

/**
 * The public site header: identity, the navigation managed in Public Site
 * Management, language/theme, and sign-in.
 *
 * One breakpoint (`lg`) switches between the inline navigation and the menu
 * button. The old header used `sm` for one and `lg` for the other, which left
 * every width from 640px to 1023px — most tablets — with no navigation at all.
 */
export default function PublicHeader() {
    const { auth, publicSite, registration_enabled, is_employee_user } = usePage<SharedProps>().props;
    const { t } = useLocale();
    const { getString } = useSystemSettings();
    const pick = useBilingual();
    const path = usePath();
    const [menuOpen, setMenuOpen] = useState(false);

    const links = publicSite?.navigation ?? [];
    const user = auth?.user ?? null;
    const appName = getString('app.short_name', 'AA Employee ID');
    const logoUrl = getString('general.identity_system_logo_url');

    // Any navigation — link, back button, redirect — closes the menu.
    useEffect(() => router.on('navigate', () => setMenuOpen(false)), []);

    const navLinkClass = (active: boolean) =>
        [
            'relative inline-flex h-16 items-center px-3 text-sm font-medium transition-colors',
            'focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[color:var(--color-primary)]',
            active
                ? 'text-[color:var(--color-primary)]'
                : 'text-gray-600 hover:text-gray-900 dark:text-slate-300 dark:hover:text-white',
        ].join(' ');

    const accountLinks = user ? (
        <Link
            href={is_employee_user ? route('employee.portal') : route('dashboard')}
            className={buttonClassName({ variant: 'ghost', size: 'sm' })}
        >
            {is_employee_user ? t('nav.myPortal') : t('nav.dashboard')}
        </Link>
    ) : (
        <>
            <Link href={route('login')} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>
                {t('nav.login')}
            </Link>
            {registration_enabled && (
                <Link href={route('register')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                    {t('nav.register')}
                </Link>
            )}
        </>
    );

    return (
        <header className="sticky top-0 z-40 border-b border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            {/* The accent rail: the one place the signal colour is always present. */}
            <div aria-hidden="true" className="h-[3px] bg-[color:var(--color-accent)]" />

            <div className="mx-auto flex h-16 max-w-7xl items-center gap-4 px-4 sm:px-6 lg:px-8">
                <Link
                    href="/"
                    className="flex min-w-0 items-center gap-2.5 rounded-control focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]"
                >
                    {logoUrl ? (
                        <img src={logoUrl} alt="" className="h-9 w-auto max-w-[88px] shrink-0 object-contain" />
                    ) : null}
                    <span className="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">{appName}</span>
                </Link>

                <nav aria-label={t('publicSite.mainNavigation')} className="hidden flex-1 justify-center lg:flex">
                    <ul className="flex items-center">
                        {links.map((link) => {
                            const active = !link.external && isActive(path, link.href);
                            return (
                                <li key={link.id}>
                                    {link.external ? (
                                        <a href={link.href} className={navLinkClass(false)} rel="noopener noreferrer">
                                            {pick(link, 'label')}
                                        </a>
                                    ) : (
                                        <Link href={link.href} className={navLinkClass(active)} aria-current={active ? 'page' : undefined}>
                                            {pick(link, 'label')}
                                            {active && (
                                                <span aria-hidden="true" className="absolute inset-x-3 bottom-0 h-0.5 bg-[color:var(--color-primary)]" />
                                            )}
                                        </Link>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                <div className="ms-auto hidden items-center gap-1.5 lg:flex">
                    <LanguageSwitcher />
                    <ThemeToggle />
                    {user ? (
                        <>
                            {accountLinks}
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button
                                        type="button"
                                        aria-label={user.name}
                                        className="rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]"
                                    >
                                        <UserAvatar src={user.profile_photo_url} name={user.name} size={30} />
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content contentClasses="py-1 bg-white dark:bg-slate-800 border border-gray-100 dark:border-slate-700 min-w-[180px]">
                                    <div className="border-b border-gray-100 px-4 py-2 dark:border-slate-700">
                                        <p className="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">{user.name}</p>
                                        <p className="truncate text-xs text-gray-500 dark:text-slate-400">{user.email}</p>
                                    </div>
                                    <Dropdown.Link href={route('profile.edit')}>
                                        <span className="flex items-center gap-2"><SettingsIcon className="h-4 w-4" aria-hidden="true" />{t('nav.profile')}</span>
                                    </Dropdown.Link>
                                    <Dropdown.Link href={route('logout')} method="post" as="button">
                                        <span className="flex items-center gap-2"><LogOut className="h-4 w-4" aria-hidden="true" />{t('nav.logout')}</span>
                                    </Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </>
                    ) : (
                        accountLinks
                    )}
                </div>

                <button
                    type="button"
                    onClick={() => setMenuOpen(true)}
                    aria-label={t('nav.openMenu')}
                    aria-haspopup="dialog"
                    aria-expanded={menuOpen}
                    aria-controls="public-mobile-menu"
                    className={buttonClassName({ variant: 'ghost', size: 'icon', className: 'ms-auto lg:hidden' })}
                >
                    <MenuIcon className="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            {/*
             * Mobile menu. Headless UI's Dialog traps focus, closes on Escape
             * and on a backdrop tap, and returns focus to the menu button —
             * none of which the previous inline drawer did.
             */}
            <Dialog open={menuOpen} onClose={setMenuOpen} className="relative z-50 lg:hidden">
                <div className="fixed inset-0 bg-black/40" aria-hidden="true" />
                <DialogPanel
                    id="public-mobile-menu"
                    className="fixed inset-y-0 right-0 flex w-full max-w-xs flex-col bg-white shadow-xl dark:bg-slate-950"
                >
                    <div className="flex h-16 items-center justify-between border-b border-gray-200 px-4 dark:border-slate-800">
                        <span className="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">{appName}</span>
                        <button
                            type="button"
                            onClick={() => setMenuOpen(false)}
                            aria-label={t('nav.closeMenu')}
                            className={buttonClassName({ variant: 'ghost', size: 'icon' })}
                        >
                            <X className="h-5 w-5" aria-hidden="true" />
                        </button>
                    </div>

                    <nav aria-label={t('publicSite.mainNavigation')} className="flex-1 overflow-y-auto px-2 py-3">
                        <ul className="space-y-0.5">
                            {links.map((link) => {
                                const active = !link.external && isActive(path, link.href);
                                const cls = [
                                    'flex min-h-[44px] items-center rounded-control px-3 text-sm font-medium',
                                    active
                                        ? 'bg-[color:var(--color-primary)]/10 text-[color:var(--color-primary)]'
                                        : 'text-gray-700 hover:bg-gray-100 dark:text-slate-200 dark:hover:bg-slate-800',
                                ].join(' ');

                                return (
                                    <li key={link.id}>
                                        {link.external ? (
                                            <a href={link.href} className={cls} rel="noopener noreferrer">{pick(link, 'label')}</a>
                                        ) : (
                                            <Link href={link.href} className={cls} aria-current={active ? 'page' : undefined}>
                                                {pick(link, 'label')}
                                            </Link>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </nav>

                    <div className="space-y-3 border-t border-gray-200 p-4 dark:border-slate-800">
                        <div className="flex items-center justify-between gap-2">
                            <LanguageSwitcher />
                            <ThemeToggle />
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {accountLinks}
                            {user && (
                                <Link
                                    href={route('logout')}
                                    method="post"
                                    as="button"
                                    className={buttonClassName({ variant: 'ghost', size: 'sm' })}
                                >
                                    {t('nav.logout')}
                                </Link>
                            )}
                        </div>
                    </div>
                </DialogPanel>
            </Dialog>
        </header>
    );
}
