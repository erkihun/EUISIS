import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { MenuIcon, ChevronDown, LogOut, SettingsIcon, SearchIcon as Search } from '@/Components/Icons';
import ThemeToggle from '@/Components/ThemeToggle';
import Dropdown from '@/Components/Dropdown';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import UserAvatar from '@/Components/UserAvatar';
import AppCommandPalette from '@/Components/ui/AppCommandPalette';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';

interface Props {
    onMenuClick: () => void;
}

export default function AppHeader({ onMenuClick }: Props) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;
    const roles = auth.roles ?? [];
    const { t } = useLocale();
    const [paletteOpen, setPaletteOpen] = useState(false);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                setPaletteOpen((v) => !v);
            }
        }
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, []);

    return (
        <>
        <AppCommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
        <header className="flex h-14 shrink-0 items-center border-b border-gray-200 bg-white px-4 sm:px-6 dark:border-slate-800 dark:bg-slate-900" style={{ borderTop: '3px solid var(--color-accent)' }}>
            {/* Mobile menu button */}
            <button
                type="button"
                onClick={onMenuClick}
                className="mr-3 rounded-md p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)] dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100 lg:hidden"
                aria-label="Open sidebar"
            >
                <MenuIcon className="h-5 w-5" aria-hidden="true" />
            </button>

            {/* Search / command trigger */}
            <button
                type="button"
                onClick={() => setPaletteOpen(true)}
                className="hidden items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm text-gray-500 transition-colors hover:bg-gray-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400 dark:hover:bg-slate-700 sm:flex"
                aria-label={t('nav.commandMenu')}
            >
                <Search className="h-3.5 w-3.5" aria-hidden="true" />
                <span className="hidden md:inline">{t('common.search')}…</span>
                <kbd className="ml-1 hidden rounded border border-gray-200 px-1 py-0.5 text-[10px] font-medium dark:border-slate-700 lg:block">
                    Ctrl K
                </kbd>
            </button>

            <div className="flex-1" />

            {/* Right: language switcher + theme toggle + user menu */}
            <div className="ml-3 flex items-center gap-2">
                <LanguageSwitcher />
                <ThemeToggle />

                <Dropdown>
                    <Dropdown.Trigger>
                        <button
                            type="button"
                            className="inline-flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            <UserAvatar
                                src={user?.profile_photo_url}
                                name={user?.name ?? t('common.user')}
                                size={28}
                            />
                            <span className="hidden max-w-[120px] truncate sm:inline">
                                {user?.name ?? t('common.user')}
                            </span>
                            <ChevronDown
                                className="h-4 w-4 text-gray-400 dark:text-slate-500"
                                aria-hidden="true"
                            />
                        </button>
                    </Dropdown.Trigger>

                    <Dropdown.Content
                        contentClasses="py-1 bg-white dark:bg-slate-800 border border-gray-100 dark:border-slate-700 min-w-[200px]"
                    >
                        {/* User info block */}
                        <div className="border-b border-gray-100 px-4 py-3 dark:border-slate-700">
                            <div className="flex items-center gap-3 mb-2">
                                <UserAvatar
                                    src={user?.profile_photo_url}
                                    name={user?.name ?? t('common.user')}
                                    size={40}
                                />
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">
                                        {user?.name ?? t('common.user')}
                                    </p>
                                    <p className="truncate text-xs text-gray-500 dark:text-slate-400">
                                        {user?.email ?? '-'}
                                    </p>
                                </div>
                            </div>
                            <p className="text-xs text-gray-500 dark:text-slate-400">
                                {t('common.signedInAs')}
                            </p>
                            {roles.length > 0 && (
                                <div className="mt-1.5 flex flex-wrap gap-1">
                                    {roles.map((role) => (
                                        <span
                                            key={role}
                                            className="inline-block rounded px-1.5 py-0.5 text-[10px] font-medium"
                                            style={{ background: 'color-mix(in srgb, var(--color-primary) 12%, transparent)', color: 'var(--color-primary)' }}
                                        >
                                            {role}
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>
                        <Dropdown.Link href={route('profile.edit')}>
                            <span className="flex items-center gap-2">
                                <SettingsIcon className="h-4 w-4" aria-hidden="true" />
                                {t('common.profileSettings')}
                            </span>
                        </Dropdown.Link>
                        <Dropdown.Link href={route('logout')} method="post" as="button">
                            <span className="flex items-center gap-2">
                                <LogOut className="h-4 w-4" aria-hidden="true" />
                                {t('common.signOut')}
                            </span>
                        </Dropdown.Link>
                    </Dropdown.Content>
                </Dropdown>
            </div>
        </header>
        </>
    );
}
