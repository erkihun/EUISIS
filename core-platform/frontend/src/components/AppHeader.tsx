import { useEffect, useState } from 'react';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { ChevronDown, LogOut, MenuIcon, SearchIcon as Search, SidebarIcon } from './NavIcons';
import AppCommandPalette from './AppCommandPalette';
import LanguageSwitcher from './LanguageSwitcher';
import ThemeToggle from './ThemeToggle';
import UserAvatar from './UserAvatar';
import { useAuth } from '../auth';
import { useLocale } from '../i18n';

const focusRing = 'focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-(--app-surface)';
/* Every toolbar control is 36px on the control radius, so the row reads as one instrument. */
const iconButton = 'flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-(--app-muted-foreground) transition-colors duration-150 hover:bg-(--app-surface-muted) hover:text-(--app-foreground)';

/** EUISIS Components/AppHeader.tsx: menu, collapse, page search, theme, language, account. */
export default function AppHeader({ onMenuClick, onToggleSidebar, sidebarCollapsed = false }: {
    onMenuClick: () => void;
    onToggleSidebar?: () => void;
    sidebarCollapsed?: boolean;
}) {
    const { user, logout } = useAuth();
    const { t } = useLocale();
    const [paletteOpen, setPaletteOpen] = useState(false);
    const [shortcut, setShortcut] = useState('Ctrl K');
    const displayName = user?.name ?? t('common.user');
    const role = user ? t(`platform.roles.${user.role}`) : null;
    const scope = user ? t(user.scope_organization_id ? 'platform.ownOrganizations' : 'platform.wholeCity') : null;

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
            <header data-admin-header className="sticky top-0 z-20 flex h-16 min-w-0 shrink-0 items-center justify-between gap-2 border-b border-(--app-border) bg-[color-mix(in_srgb,var(--app-surface)_88%,transparent)] px-3 backdrop-blur-md sm:gap-4 sm:px-6 lg:px-8">
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
                        className={'hidden h-9 w-full max-w-sm items-center gap-2.5 rounded-md border border-(--app-border) bg-(--app-surface-muted) px-3 text-sm text-(--app-muted-foreground) transition-colors duration-150 hover:border-(--app-border-strong) hover:bg-(--app-surface) hover:text-(--app-foreground) sm:flex ' + focusRing}
                    >
                        <Search className="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span className="min-w-0 flex-1 truncate text-start">{t('nav.searchNavigation')}</span>
                        <kbd aria-hidden="true" className="hidden shrink-0 rounded-sm border border-(--app-border) bg-(--app-surface) px-1.5 py-0.5 font-sans text-[10px] font-medium text-(--app-muted-foreground) lg:inline">{shortcut}</kbd>
                    </button>
                </div>

                <div className="flex shrink-0 items-center gap-1 sm:gap-1.5">
                    <ThemeToggle variant="toolbar" />
                    <LanguageSwitcher variant="toolbar" />
                    <span aria-hidden="true" className="mx-1 hidden h-6 w-px bg-(--app-border) sm:block" />
                    <Menu>
                        <MenuButton
                            aria-label={t('nav.accountMenu') + ': ' + displayName}
                            className={'flex h-10 items-center gap-2.5 rounded-md px-1 text-start text-(--app-foreground) transition-colors duration-150 hover:bg-(--app-surface-muted) sm:px-1.5 ' + focusRing}
                        >
                            <UserAvatar name={displayName} size={30} className="bg-(--app-surface-muted)! text-(--app-foreground)! ring-1 ring-(--app-border)" />
                            <span className="hidden min-w-0 lg:block">
                                <span className="block max-w-40 truncate text-sm font-semibold leading-snug">{displayName}</span>
                                {role && <span className="mt-0.5 block max-w-40 truncate text-[11px] leading-snug text-(--app-muted-foreground)">{role}</span>}
                            </span>
                            <ChevronDown className="hidden h-4 w-4 shrink-0 text-(--app-muted-foreground) sm:block" aria-hidden="true" />
                        </MenuButton>
                        <MenuItems
                            anchor="bottom end"
                            transition
                            className="z-50 w-72 max-w-[calc(100vw-1rem)] overflow-hidden rounded-panel border border-(--app-border) bg-(--app-surface) text-(--app-foreground) shadow-lg outline-hidden transition duration-100 ease-out [--anchor-gap:8px] [--anchor-padding:8px] data-closed:scale-[0.98] data-closed:opacity-0"
                        >
                            <div className="border-b border-(--app-border) bg-(--app-surface-muted) px-4 py-3.5">
                                <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('common.signedInAs')}</p>
                                <p className="wrap-break-word text-sm font-semibold">{displayName}</p>
                                {user?.email && <p className="mt-0.5 break-all text-xs text-(--app-muted-foreground)">{user.email}</p>}
                                <div className="mt-2.5 flex flex-wrap gap-1.5">
                                    {[role, scope].filter(Boolean).map((tag) => (
                                        <span key={tag} className="rounded-md border border-(--app-border) bg-(--app-surface) px-2 py-0.5 text-xs text-(--app-muted-foreground)">{tag}</span>
                                    ))}
                                </div>
                            </div>
                            <div className="p-1.5">
                                <MenuItem>
                                    <button type="button" onClick={() => void logout()} className="flex min-h-10 w-full items-center gap-3 rounded-md px-3 text-start text-sm text-red-700 data-focus:bg-red-50 dark:text-red-400 dark:data-focus:bg-red-950/40">
                                        <LogOut className="h-[18px] w-[18px]" aria-hidden="true" />
                                        {t('common.signOut')}
                                    </button>
                                </MenuItem>
                            </div>
                        </MenuItems>
                    </Menu>
                </div>
            </header>
        </>
    );
}
