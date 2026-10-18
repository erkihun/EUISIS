import { Link } from '@inertiajs/react';
import { ChatIcon } from '@/Components/NavIcons';
import { useCan } from '@/hooks/useCan';
import { useCommunicationUnread } from '@/hooks/useCommunicationUnread';
import { useLocale } from '@/hooks/useLocale';

/**
 * Messages in the top bar, next to the notification bell: opens Communication
 * and shows the unread total. Hidden when the module is off for this person
 * (the unread prop is null) or they lack communication.access.
 */
export default function MessagesButton() {
    const { t } = useLocale();
    const { can } = useCan();
    const unread = useCommunicationUnread();

    if (unread === null || !can('communication.access')) return null;

    const active = String(route().current() ?? '').startsWith('communication.');
    const label = unread > 0 ? `${t('nav.communication')} — ${t('nav.messagesUnread').replace('{count}', String(unread))}` : t('nav.communication');

    return (
        <Link
            href={route('communication.index')}
            aria-label={label}
            title={label}
            aria-current={active ? 'page' : undefined}
            className={`relative flex h-9 w-9 shrink-0 items-center justify-center rounded-md transition-colors duration-150 hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] ${active ? 'bg-[color:var(--app-surface-muted)] text-[color:var(--color-primary)]' : 'text-[color:var(--app-muted-foreground)]'}`}
        >
            <ChatIcon className="h-5 w-5" aria-hidden="true" />
            {unread > 0 && <span aria-hidden="true" className="absolute -right-0.5 -top-0.5 min-w-4 rounded-full bg-red-600 px-1 text-[10px] font-bold leading-4 text-white">{unread > 99 ? '99+' : unread}</span>}
        </Link>
    );
}
