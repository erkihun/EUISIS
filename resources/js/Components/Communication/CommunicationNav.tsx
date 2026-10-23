import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type JSX, type ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { ActionMenu } from '@/Components/Communication/MessageBubble';
import AssistantDockButton from '@/Components/ai/AssistantDockButton';
import { ChatIcon, ChecklistIcon, HashIcon, MegaphoneIcon, MenuDotsIcon, WidgetsIcon } from '@/Components/NavIcons';
import type { PageProps } from '@/types';
import type { WorkspaceLinks } from '@/types/communication';

type Shared = { nav: WorkspaceLinks | null } | null;

/**
 * The Communication workspace's own navigation, drawn by the layout on every
 * Communication page (the module has no sidebar entry; it opens from the
 * top-bar Messages icon). Sections follow the person's permissions, which the
 * server sends as `communication.nav` on these pages only.
 */
export default function CommunicationNav(): JSX.Element | null {
    const { t } = useLocale();
    const nav = ((usePage<PageProps>().props as { communication?: Shared }).communication ?? null)?.nav ?? null;
    const [more, setMore] = useState(false);
    const strip = useRef<HTMLUListElement>(null);
    // On a phone the strip is wider than the screen: bring the current section into view.
    useEffect(() => {
        const list = strip.current;
        const active = list?.querySelector<HTMLElement>('[aria-current="page"]');
        if (list && active) list.scrollLeft = Math.max(0, active.offsetLeft - (list.clientWidth - active.offsetWidth) / 2);
    }, []);
    if (!nav) return null;

    const current = String(route().current() ?? '');
    const tabs: { key: string; href: string; label: string; icon: ReactNode; active: boolean }[] = [
        { key: 'messages', href: route('communication.messages.index'), label: t('communication.workspace.messages'), icon: <ChatIcon className="h-4 w-4" />, active: current.startsWith('communication.messages') },
        ...(nav.channels ? [{ key: 'channels', href: route('communication.channels.index'), label: t('communication.workspace.channels'), icon: <HashIcon className="h-4 w-4" />, active: current.startsWith('communication.channels') }] : []),
        ...(nav.announcements ? [{ key: 'announcements', href: route('communication.announcements.index'), label: t('communication.workspace.announcements'), icon: <MegaphoneIcon className="h-4 w-4" />, active: current.startsWith('communication.announcements') }] : []),
        ...(nav.tasks ? [{ key: 'tasks', href: route('communication.tasks.index'), label: t('communication.workspace.tasks'), icon: <ChecklistIcon className="h-4 w-4" />, active: current.startsWith('communication.tasks') }] : []),
        { key: 'overview', href: route('communication.index'), label: t('communication.workspace.overview'), icon: <WidgetsIcon className="h-4 w-4" />, active: current === 'communication.index' },
    ];
    const moreLinks = [
        { href: route('communication.preferences.index'), label: t('communication.links.preferences'), active: current.startsWith('communication.preferences') },
        ...(nav.moderate ? [{ href: route('communication.moderation.index'), label: t('communication.navMore.moderation'), active: current.startsWith('communication.moderation') }] : []),
        ...(nav.investigations ? [{ href: route('communication.investigations.index'), label: t('communication.navMore.investigations'), active: current.startsWith('communication.investigations') }] : []),
        ...(nav.settings ? [{ href: route('communication.settings.index'), label: t('communication.links.settings'), active: current.startsWith('communication.settings') }] : []),
    ];
    const moreActive = moreLinks.some((link) => link.active);

    return (
        <nav aria-label={t('communication.workspace.label')} className="shrink-0 border-b border-(--app-border) bg-(--app-surface)">
            <div className="flex items-center gap-1 px-2 sm:px-4 lg:px-5">
                <ul ref={strip} className="comm-tabs -mb-px flex min-w-0 flex-1 items-center gap-1 overflow-x-auto">
                    {tabs.map((tab) => (
                        <li key={tab.key} className="shrink-0">
                            <Link
                                href={tab.href}
                                aria-current={tab.active ? 'page' : undefined}
                                className={[
                                    'inline-flex h-11 items-center gap-2 border-b-2 px-3 text-sm font-medium transition-colors',
                                    'focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary',
                                    tab.active
                                        ? 'border-primary text-(--app-foreground)'
                                        : 'border-transparent text-(--app-muted-foreground) hover:border-(--app-border-strong) hover:text-(--app-foreground)',
                                ].join(' ')}
                            >
                                <span aria-hidden="true" className={tab.active ? 'text-primary' : ''}>{tab.icon}</span>
                                {tab.label}
                            </Link>
                        </li>
                    ))}
                </ul>
                {/* The assistant's trigger lives here in this workspace, not in the bottom-right corner, which the composer needs. */}
                <AssistantDockButton />
                <div className="relative shrink-0">
                    <button
                        type="button"
                        onClick={() => setMore((open) => !open)}
                        aria-expanded={more}
                        aria-haspopup="menu"
                        aria-label={t('communication.workspace.more')}
                        title={t('communication.workspace.more')}
                        className={`flex h-9 w-9 items-center justify-center rounded-control transition-colors hover:bg-(--app-surface-muted) hover:text-(--app-foreground) focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary pointer-coarse:h-11 pointer-coarse:w-11 ${moreActive ? 'text-primary' : 'text-(--app-muted-foreground)'}`}
                    >
                        <MenuDotsIcon className="h-5 w-5" aria-hidden="true" />
                    </button>
                    {more && (
                        <ActionMenu onClose={() => setMore(false)} align="end">
                            {moreLinks.map((link) => (
                                <Link
                                    key={link.href}
                                    role="menuitem"
                                    href={link.href}
                                    aria-current={link.active ? 'page' : undefined}
                                    className={`flex w-full items-center px-3 py-1.5 text-start text-sm hover:bg-(--app-surface-muted) focus:bg-(--app-surface-muted) focus:outline-hidden ${link.active ? 'font-semibold text-primary' : 'text-(--app-foreground)'}`}
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </ActionMenu>
                    )}
                </div>
            </div>
        </nav>
    );
}
