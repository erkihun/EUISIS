import { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { MenuIcon, ChevronDown, LogOut, SettingsIcon, SearchIcon as Search, SidebarIcon } from '@/Components/NavIcons';
import ThemeToggle from '@/Components/ThemeToggle';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import UserAvatar from '@/Components/UserAvatar';
import CommunicationButton from '@/Components/TopNav/CommunicationButton';
import NotificationBell from '@/Components/NotificationBell';
import AppCommandPalette from '@/Components/ui/AppCommandPalette';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';

interface Props {
    /** Opens the navigation drawer (below the lg breakpoint). */
    onMenuClick: () => void;
    /** Collapses the desktop sidebar to its icon rail and back; absent on layouts without one. */
    onToggleSidebar?: () => void;
    sidebarCollapsed?: boolean;
}

const focusRing = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2 focus-visible:ring-offset-[color:var(--app-surface)]';
/* Every toolbar control is 36px on the control radius, so the row reads as one instrument. */
const iconButton = 'flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-[color:var(--app-muted-foreground)] transition-colors duration-150 hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)]';

export default function AppHeader({ onMenuClick, onToggleSidebar, sidebarCollapsed = false }: Props) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;
    const roles = auth.roles ?? [];
    const { t } = useLocale();
    const [paletteOpen, setPaletteOpen] = useState(false);
    const [shortcut, setShortcut] = useState('Ctrl K');
    const displayName = user?.name ?? t('common.user');

    useEffect(() => {
        setShortcut(/Mac|iPhone|iPad/.test(navigator.platform) ? '⌘ K' : 'Ctrl K');
        function onKey(event: KeyboardEvent) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && !event.repeat) {
                event.preventDefault();
                setPaletteOpen((open) => !open);
            }
        }
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, []);

    return (
        <>
            <AppCommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
            <header data-admin-header className="sticky top-0 z-20 flex h-16 min-w-0 shrink-0 items-center justify-between gap-2 border-b border-[color:var(--app-border)] bg-[color:color-mix(in_srgb,var(--app-surface)_88%,transparent)] px-3 backdrop-blur-md sm:gap-4 sm:px-6 lg:px-8">
                <div className="flex min-w-0 items-center gap-1 sm:flex-1 sm:gap-2">
                    <button type="button" onClick={onMenuClick} aria-label={t('nav.openMenu')} className={iconButton + ' lg:hidden ' + focusRing}>
                        <MenuIcon className="h-5 w-5" aria-hidden="true" />
                    </button>
                    {onToggleSidebar && (
                        <button
                            type="button"
                            onClick={onToggleSidebar}
                            aria-label={t(sidebarCollapsed ? 'nav.expandSidebar' : 'nav.collapseSidebar')}
                            aria-expanded={!sidebarCollapsed}
                            className={iconButton + ' hidden lg:flex ' + focusRing}
                        >
                            <SidebarIcon className="h-5 w-5" aria-hidden="true" />
                        </button>
                    )}
                    <button type="button" onClick={() => setPaletteOpen(true)} aria-label={t('nav.commandMenu')} aria-haspopup="dialog" className={iconButton + ' sm:hidden ' + focusRing}>
                        <Search className="h-5 w-5" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        onClick={() => setPaletteOpen(true)}
                        aria-label={t('nav.commandMenu')}
                        aria-haspopup="dialog"
                        aria-keyshortcuts="Control+k Meta+k"
                        className={'hidden h-9 w-full max-w-sm items-center gap-2.5 rounded-md border border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-3 text-sm text-[color:var(--app-muted-foreground)] transition-colors duration-150 hover:border-[color:var(--app-border-strong)] hover:bg-[color:var(--app-surface)] hover:text-[color:var(--app-foreground)] sm:flex ' + focusRing}
                    >
                        <Search className="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span className="min-w-0 flex-1 truncate text-start">{t('nav.searchNavigation')}</span>
                        <kbd aria-hidden="true" className="hidden shrink-0 rounded border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-1.5 py-0.5 font-sans text-[10px] font-medium text-[color:var(--app-muted-foreground)] lg:inline">{shortcut}</kbd>
                    </button>
                </div>

                <div className="flex shrink-0 items-center gap-1 sm:gap-1.5">
                    <CommunicationButton />
                    <NotificationBell />
                    <ThemeToggle variant="toolbar" />
                    <LanguageSwitcher variant="toolbar" />
                    <span aria-hidden="true" className="mx-1 hidden h-6 w-px bg-[color:var(--app-border)] sm:block" />
                    <Menu>
                        <MenuButton
                            aria-label={t('nav.accountMenu') + ': ' + displayName}
                            className={'flex h-10 items-center gap-2.5 rounded-md px-1 text-start text-[color:var(--app-foreground)] transition-colors duration-150 hover:bg-[color:var(--app-surface-muted)] sm:px-1.5 ' + focusRing}
                        >
                            <UserAvatar src={user?.profile_photo_url} name={displayName} size={30} className="!bg-[color:var(--app-surface-muted)] !text-[color:var(--app-foreground)] ring-1 ring-[color:var(--app-border)]" />
                            <span className="hidden min-w-0 lg:block">
                                <span className="block max-w-40 truncate text-sm font-semibold leading-snug">{displayName}</span>
                                {roles[0] && <span className="mt-0.5 block max-w-40 truncate text-[11px] leading-snug text-[color:var(--app-muted-foreground)]">{roles[0]}</span>}
                            </span>
                            <ChevronDown className="hidden h-4 w-4 shrink-0 text-[color:var(--app-muted-foreground)] sm:block" aria-hidden="true" />
                        </MenuButton>
                        <MenuItems
                            anchor="bottom end"
                            transition
                            className="z-50 w-72 max-w-[calc(100vw-1rem)] overflow-hidden rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] shadow-lg outline-none transition duration-100 ease-out [--anchor-gap:8px] [--anchor-padding:8px] data-[closed]:scale-[0.98] data-[closed]:opacity-0"
                        >
                            <div className="border-b border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-4 py-3.5">
                                <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-[color:var(--app-muted-foreground)]">{t('common.signedInAs')}</p>
                                <p className="break-words text-sm font-semibold">{displayName}</p>
                                {user?.email && <p className="mt-0.5 break-all text-xs text-[color:var(--app-muted-foreground)]">{user.email}</p>}
                                {roles.length > 0 && <div className="mt-2.5 flex flex-wrap gap-1.5">
                                    {roles.map((role) => <span key={role} className="rounded-md border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-2 py-0.5 text-xs text-[color:var(--app-muted-foreground)]">{role}</span>)}
                                </div>}
                            </div>
                            <div className="p-1.5">
                                <MenuItem>
                                    <Link href={route('profile.edit')} className="flex min-h-10 items-center gap-3 rounded-md px-3 text-sm data-[focus]:bg-[color:var(--app-surface-muted)]">
                                        <SettingsIcon className="h-[18px] w-[18px] text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                                        {t('common.profileSettings')}
                                    </Link>
                                </MenuItem>
                            </div>
                            <div className="border-t border-[color:var(--app-border)] p-1.5">
                                <MenuItem>
                                    <Link href={route('logout')} method="post" as="button" className="flex min-h-10 w-full items-center gap-3 rounded-md px-3 text-start text-sm text-red-700 data-[focus]:bg-red-50 dark:text-red-400 dark:data-[focus]:bg-red-950/40">
                                        <LogOut className="h-[18px] w-[18px]" aria-hidden="true" />
                                        {t('common.signOut')}
                                    </Link>
                                </MenuItem>
                            </div>
                        </MenuItems>
                    </Menu>
                </div>
            </header>
        </>
    );
}
