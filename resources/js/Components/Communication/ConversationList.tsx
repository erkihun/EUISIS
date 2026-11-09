import { memo, type JSX } from 'react';
import { usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { listTime, tr } from '@/lib/communication/format';
import Avatar from '@/Components/Communication/Avatar';
import { BellOffIcon, PaperclipIcon, PenNewIcon, SearchIcon, Users } from '@/Components/NavIcons';
import type { PageProps } from '@/types';
import type { ConversationSummary, ConversationType } from '@/types/communication';

/** A conversation type from the server, or "unread" (narrowed on the loaded list). */
export type ListFilter = ConversationType | 'unread' | null;

type Props = {
    items: ConversationSummary[];
    activeId: string | null;
    filter: ListFilter;
    unreadCount: number;
    search: string;
    loading: boolean;
    hasMore: boolean;
    canStart: boolean;
    canGroup: boolean;
    onFilter: (filter: ListFilter) => void;
    onSearch: (value: string) => void;
    onSelect: (id: string) => void;
    onLoadMore: () => void;
    onNew: (kind: 'message' | 'group') => void;
};

const FILTERS: ListFilter[] = [null, 'unread', 'DIRECT', 'GROUP', 'CHANNEL'];

export default function ConversationList(props: Props): JSX.Element {
    const { items, activeId, filter, search, loading, hasMore } = props;
    const { t } = useLocale();

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex flex-col gap-3 px-4 pb-3 pt-4">
                <div className="flex items-center gap-2">
                    <h2 className="flex-1 text-lg font-bold tracking-[-0.01em] text-(--app-foreground)">{t('communication.messagesTitle')}</h2>
                    {props.canStart && props.canGroup && (
                        <button
                            type="button"
                            onClick={() => props.onNew('group')}
                            title={t('communication.start.group')}
                            aria-label={t('communication.start.group')}
                            className="flex h-[34px] w-[34px] items-center justify-center rounded-lg border border-(--app-border) bg-(--app-surface) text-(--app-foreground) hover:bg-(--app-surface-muted) focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary"
                        >
                            <Users className="h-[18px] w-[18px]" aria-hidden="true" />
                        </button>
                    )}
                    {props.canStart && (
                        <button
                            type="button"
                            onClick={() => props.onNew('message')}
                            title={t('communication.start.message')}
                            className="flex h-[34px] items-center gap-1.5 rounded-lg bg-primary px-3 text-[13px] font-semibold text-white hover:bg-primary-hover focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                        >
                            <PenNewIcon className="h-4 w-4" aria-hidden="true" />
                            {t('communication.list.new')}
                        </button>
                    )}
                </div>

                <label className="relative block">
                    <span className="sr-only">{t('communication.list.search')}</span>
                    <SearchIcon className="pointer-events-none absolute inset-s-[11px] top-1/2 h-4 w-4 -translate-y-1/2 text-(--app-muted-foreground)" aria-hidden="true" />
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => props.onSearch(event.target.value)}
                        placeholder={t('communication.list.search')}
                        className="h-[38px] w-full rounded-lg border border-transparent bg-(--app-surface-muted) ps-[34px] pe-3 text-sm text-(--app-foreground) placeholder:text-(--app-muted-foreground) focus:border-primary focus:bg-(--app-surface) focus:outline-hidden focus:ring-[3px] focus:ring-primary/12"
                    />
                </label>

                <div role="tablist" aria-label={t('communication.list.label')} className="flex gap-0.5 rounded-[9px] bg-(--app-surface-muted) p-[3px]">
                    {FILTERS.map((value) => {
                        const on = filter === value;
                        return (
                            <button
                                key={value ?? 'all'}
                                type="button"
                                role="tab"
                                aria-selected={on}
                                onClick={() => props.onFilter(value)}
                                className={`flex h-7 min-w-0 flex-1 items-center justify-center gap-1 whitespace-nowrap rounded-[7px] px-1 text-xs font-semibold transition-colors ${on ? 'bg-(--app-surface) text-(--app-foreground) shadow-[0_1px_2px_rgba(15,23,42,0.10)]' : 'text-(--app-muted-foreground) hover:text-(--app-foreground)'}`}
                            >
                                {t(`communication.filters.${value ?? 'all'}`)}
                                {value === 'unread' && props.unreadCount > 0 && (
                                    <span className="text-[11px] font-semibold tabular-nums text-primary">{props.unreadCount}</span>
                                )}
                            </button>
                        );
                    })}
                </div>
            </div>

            <nav aria-label={t('communication.list.label')} className="comm-scroll min-h-0 flex-1 overflow-y-auto overflow-x-hidden px-2 pb-3">
                {loading && items.length === 0 ? (
                    <ListSkeleton />
                ) : items.length === 0 ? (
                    <p className="px-4 py-10 text-center text-sm text-(--app-muted-foreground)">
                        {search || filter ? t('communication.list.emptyFilter') : t('communication.list.empty')}
                    </p>
                ) : (
                    <>
                        <p className="px-2.5 pb-1.5 pt-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-(--app-muted-foreground)">{t('communication.list.recent')}</p>
                        <ul className="flex flex-col gap-px">
                            {items.map((item) => <Row key={item.id} item={item} active={item.id === activeId} onSelect={props.onSelect} />)}
                        </ul>
                    </>
                )}
                {hasMore && (
                    <div className="p-3">
                        <button
                            type="button"
                            disabled={loading}
                            onClick={props.onLoadMore}
                            className="h-8 w-full rounded-lg text-xs font-medium text-primary hover:bg-(--app-surface-muted) disabled:opacity-50"
                        >
                            {t('communication.list.loadMore')}
                        </button>
                    </div>
                )}
            </nav>
        </div>
    );
}

const Row = memo(function Row({ item, active, onSelect }: { item: ConversationSummary; active: boolean; onSelect: (id: string) => void }): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const last = item.last_message;
    const unread = item.unread_count > 0;
    const loud = unread && !item.muted;

    // The summary carries the sender's name only: one's own last message is told by name.
    const me = usePage<PageProps>().props.auth.user?.name ?? null;
    const own = !!last?.sender_name && last.sender_name === me && last.type !== 'SYSTEM';

    let preview = '';
    if (last) {
        const text = last.deleted
            ? t('communication.list.deleted')
            : last.excerpt || (last.has_attachments ? (last.type === 'IMAGE' ? t('communication.list.image') : t('communication.list.attachment')) : '');
        preview = own ? `${t('communication.list.you')}: ${text}`
            : last.type === 'SYSTEM' || item.type === 'DIRECT' || !last.sender_name ? text : `${last.sender_name.split(' ')[0]}: ${text}`;
    }

    return (
        <li>
            <button
                type="button"
                onClick={() => onSelect(item.id)}
                aria-current={active ? 'page' : undefined}
                className={`flex w-full items-center gap-3 rounded-[10px] p-2.5 text-start transition-colors focus-visible:outline-solid focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-primary ${active ? 'bg-[color-mix(in_srgb,var(--color-primary)_12%,var(--app-surface))]' : 'hover:bg-(--app-background)'}`}
            >
                <Avatar
                    name={item.title}
                    kind={item.type}
                    userId={item.other_user?.id}
                    hasPhoto={item.has_avatar}
                    online={item.type === 'DIRECT' ? (item.other_user?.online ?? null) : null}
                    size="lg"
                />
                <span className="flex min-w-0 flex-1 flex-col gap-[3px]">
                    <span className="flex items-center gap-1.5">
                        <span className={`min-w-0 flex-1 truncate text-sm text-(--app-foreground) ${unread ? 'font-bold' : 'font-medium'}`}>
                            {item.title}
                        </span>
                        {item.muted && <BellOffIcon className="h-3.5 w-3.5 shrink-0 text-(--app-muted-foreground)" aria-label={t('communication.list.muted')} />}
                        <span className={`shrink-0 text-xs tabular-nums ${loud ? 'font-semibold text-primary' : 'text-(--app-muted-foreground)'}`}>
                            {listTime(item.last_message_at, locale, system)}
                        </span>
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className={`flex min-w-0 flex-1 items-center gap-1 text-[13px] ${unread ? 'text-(--app-foreground)' : 'text-(--app-muted-foreground)'}`}>
                            {last?.has_attachments && !last.deleted && !last.excerpt && <PaperclipIcon className="h-[13px] w-[13px] shrink-0" aria-hidden="true" />}
                            <span className={`truncate ${last?.deleted ? 'italic' : ''}`}>{preview}</span>
                        </span>
                        {unread && (
                            <span
                                className={`inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-[11px] font-semibold tabular-nums ${item.muted ? 'bg-(--app-border) text-(--app-muted-foreground)' : 'bg-primary text-white'}`}
                                aria-label={tr(t('communication.list.unread'), { n: item.unread_count })}
                            >
                                {item.unread_count > 99 ? '99+' : item.unread_count}
                            </span>
                        )}
                    </span>
                </span>
            </button>
        </li>
    );
});

export function ListSkeleton({ rows = 7 }: { rows?: number }): JSX.Element {
    return (
        <ul aria-hidden="true" className="animate-pulse">
            {Array.from({ length: rows }, (_, index) => (
                <li key={index} className="flex items-center gap-3 px-2.5 py-3">
                    <span className="h-11 w-11 shrink-0 rounded-full bg-(--app-surface-muted)" />
                    <span className="flex-1 space-y-2">
                        <span className="block h-3 w-2/3 rounded-sm bg-(--app-surface-muted)" />
                        <span className="block h-2.5 w-5/6 rounded-sm bg-(--app-surface-muted)" />
                    </span>
                </li>
            ))}
        </ul>
    );
}
