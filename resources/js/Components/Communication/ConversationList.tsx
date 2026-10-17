import { memo, type JSX } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { listTime, tr } from '@/lib/communication/format';
import Avatar from '@/Components/Communication/Avatar';
import { BellOffIcon, PaperclipIcon, PlusIcon, SearchIcon } from '@/Components/NavIcons';
import type { ConversationSummary, ConversationType } from '@/types/communication';

type Props = {
    items: ConversationSummary[];
    activeId: string | null;
    filter: ConversationType | null;
    search: string;
    loading: boolean;
    hasMore: boolean;
    canStart: boolean;
    onFilter: (filter: ConversationType | null) => void;
    onSearch: (value: string) => void;
    onSelect: (id: string) => void;
    onLoadMore: () => void;
    onNew: () => void;
};

const FILTERS: (ConversationType | null)[] = [null, 'DIRECT', 'GROUP', 'CHANNEL'];

export default function ConversationList(props: Props): JSX.Element {
    const { items, activeId, filter, search, loading, hasMore } = props;
    const { t } = useLocale();

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex items-center gap-2 px-3 pb-2 pt-3">
                <h2 className="flex-1 text-base font-semibold text-[color:var(--app-foreground)]">{t('communication.messagesTitle')}</h2>
                {props.canStart && (
                    <button
                        type="button"
                        onClick={props.onNew}
                        className="inline-flex h-8 items-center gap-1.5 rounded-control bg-[color:var(--color-primary)] px-2.5 text-xs font-medium text-white hover:opacity-90"
                    >
                        <PlusIcon className="h-4 w-4" aria-hidden="true" />
                        {t('communication.dashboard.newMessage')}
                    </button>
                )}
            </div>

            <div className="px-3">
                <label className="relative block">
                    <span className="sr-only">{t('communication.list.search')}</span>
                    <SearchIcon className="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => props.onSearch(event.target.value)}
                        placeholder={t('communication.list.search')}
                        className="h-9 w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] ps-8 pe-2 text-sm text-[color:var(--app-foreground)] placeholder:text-[color:var(--app-muted-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/15"
                    />
                </label>
                <div role="tablist" aria-label={t('communication.list.label')} className="mt-2 flex gap-1 overflow-x-auto pb-2">
                    {FILTERS.map((value) => (
                        <button
                            key={value ?? 'all'}
                            type="button"
                            role="tab"
                            aria-selected={filter === value}
                            onClick={() => props.onFilter(value)}
                            className={`h-7 shrink-0 rounded-full px-3 text-xs font-medium transition-colors ${filter === value ? 'bg-[color:var(--app-foreground)] text-[color:var(--app-background)]' : 'text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)]'}`}
                        >
                            {t(`communication.filters.${value ?? 'all'}`)}
                        </button>
                    ))}
                </div>
            </div>

            <nav aria-label={t('communication.list.label')} className="comm-scroll min-h-0 flex-1 overflow-y-auto border-t border-[color:var(--app-border)]">
                {loading && items.length === 0 ? (
                    <ListSkeleton />
                ) : items.length === 0 ? (
                    <p className="px-4 py-10 text-center text-sm text-[color:var(--app-muted-foreground)]">
                        {search || filter ? t('communication.list.emptyFilter') : t('communication.list.empty')}
                    </p>
                ) : (
                    <ul>
                        {items.map((item) => <Row key={item.id} item={item} active={item.id === activeId} onSelect={props.onSelect} />)}
                    </ul>
                )}
                {hasMore && (
                    <div className="p-3">
                        <button
                            type="button"
                            disabled={loading}
                            onClick={props.onLoadMore}
                            className="h-8 w-full rounded-control text-xs font-medium text-[color:var(--color-primary)] hover:bg-[color:var(--app-surface-muted)] disabled:opacity-50"
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

    let preview = '';
    if (last) {
        const text = last.deleted
            ? t('communication.list.deleted')
            : last.excerpt || (last.has_attachments ? (last.type === 'IMAGE' ? t('communication.list.image') : t('communication.list.attachment')) : '');
        preview = last.type === 'SYSTEM' || item.type === 'DIRECT' || !last.sender_name ? text : `${last.sender_name}: ${text}`;
    }

    return (
        <li>
            <button
                type="button"
                onClick={() => onSelect(item.id)}
                aria-current={active ? 'page' : undefined}
                className={`flex w-full items-center gap-3 px-3 py-2.5 text-start transition-colors focus-visible:outline focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-[color:var(--color-primary)] ${active ? 'bg-[color:color-mix(in_srgb,var(--color-primary)_12%,var(--app-surface))]' : 'hover:bg-[color:var(--app-surface-muted)]'}`}
            >
                <Avatar
                    name={item.title}
                    kind={item.type}
                    userId={item.other_user?.id}
                    hasPhoto={item.has_avatar}
                    online={item.type === 'DIRECT' ? (item.other_user?.online ?? null) : null}
                />
                <span className="min-w-0 flex-1">
                    <span className="flex items-baseline gap-2">
                        <span className={`min-w-0 flex-1 truncate text-sm ${unread ? 'font-semibold text-[color:var(--app-foreground)]' : 'font-medium text-[color:var(--app-foreground)]'}`}>
                            {item.title}
                        </span>
                        <span className={`shrink-0 text-[11px] tabular-nums ${unread ? 'font-semibold text-[color:var(--color-primary)]' : 'text-[color:var(--app-muted-foreground)]'}`}>
                            {listTime(item.last_message_at, locale, system)}
                        </span>
                    </span>
                    <span className="mt-0.5 flex items-center gap-2">
                        <span className={`flex min-w-0 flex-1 items-center gap-1 truncate text-xs ${unread ? 'text-[color:var(--app-foreground)]' : 'text-[color:var(--app-muted-foreground)]'}`}>
                            {last?.has_attachments && !last.deleted && <PaperclipIcon className="h-3 w-3 shrink-0" aria-hidden="true" />}
                            <span className={`truncate ${last?.deleted ? 'italic' : ''}`}>{preview}</span>
                        </span>
                        {item.muted && <BellOffIcon className="h-3.5 w-3.5 shrink-0 text-[color:var(--app-muted-foreground)]" aria-label={t('communication.list.muted')} />}
                        {unread && (
                            <span
                                className={`inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-[11px] font-semibold tabular-nums ${item.muted ? 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]' : 'bg-[color:var(--color-primary)] text-white'}`}
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
                <li key={index} className="flex items-center gap-3 px-3 py-3">
                    <span className="h-10 w-10 shrink-0 rounded-full bg-[color:var(--app-surface-muted)]" />
                    <span className="flex-1 space-y-2">
                        <span className="block h-3 w-2/3 rounded bg-[color:var(--app-surface-muted)]" />
                        <span className="block h-2.5 w-5/6 rounded bg-[color:var(--app-surface-muted)]" />
                    </span>
                </li>
            ))}
        </ul>
    );
}
