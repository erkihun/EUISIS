import { Link } from '@inertiajs/react';
import { ChatIcon } from '@/Components/NavIcons';
import { useCan } from '@/hooks/useCan';
import { useCommunicationUnreadCount } from '@/hooks/useCommunicationUnreadCount';
import { useLocale } from '@/hooks/useLocale';

/** 0 → no badge, 1–99 → the count, more → "99+". */
export function unreadBadge(count: number): string | null {
    if (count <= 0) return null;
    return count > 99 ? '99+' : String(count);
}

/**
 * The top-bar Messages entry: the only way into the Communication workspace
 * (it is deliberately not a sidebar item). Shows the unread total, follows it
 * in realtime through useCommunicationUnreadCount, and is absent when
 * Communication is off for this person or they lack communication.access —
 * the routes refuse them server-side as well. Unread logic lives in the hook.
 */
export default function CommunicationButton() {
    const { t } = useLocale();
    const { can } = useCan();
    const unread = useCommunicationUnreadCount();

    if (unread === null || !can('communication.access')) return null;

    const active = String(route().current() ?? '').startsWith('communication.');
    const badge = unreadBadge(unread);
    const label = badge ? `${t('nav.communication')} — ${t('nav.messagesUnread').replace('{count}', String(unread))}` : t('nav.communication');

    return (
        <span className="group relative inline-flex">
            <Link
                href={route('communication.messages.index')}
                aria-label={label}
                aria-current={active ? 'page' : undefined}
                className={[
                    'relative flex h-9 w-9 shrink-0 items-center justify-center rounded-md transition-colors duration-150',
                    'hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)]',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2 focus-visible:ring-offset-[color:var(--app-surface)]',
                    active ? 'bg-[color:var(--app-surface-muted)] text-[color:var(--color-primary)]' : 'text-[color:var(--app-muted-foreground)]',
                ].join(' ')}
            >
                <ChatIcon className="h-5 w-5" aria-hidden="true" />
                {badge && (
                    <span aria-hidden="true" className="absolute -right-0.5 -top-0.5 min-w-4 rounded-full bg-red-600 px-1 text-center text-[10px] font-bold leading-4 text-white ring-2 ring-[color:var(--app-surface)]">
                        {badge}
                    </span>
                )}
            </Link>
            {/* Tooltip: on hover and on keyboard focus, below the bar so the header never clips it. */}
            <span role="tooltip" className="pointer-events-none absolute right-1/2 top-full z-50 mt-2 hidden translate-x-1/2 whitespace-nowrap rounded bg-[color:var(--app-foreground)] px-2 py-1 text-xs text-[color:var(--app-background)] shadow-lg group-hover:block group-focus-within:block">
                {label}
            </span>
        </span>
    );
}
