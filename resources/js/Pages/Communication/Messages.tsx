import { Head, usePage } from '@inertiajs/react';
import { Button } from '@euisis/ui';
import { useCallback, useEffect, useLayoutEffect, useRef, useState, type JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { communicationApi } from '@/lib/communication/api';
import { channels, connectRealtime, listen } from '@/lib/communication/realtime';
import ConversationList from '@/Components/Communication/ConversationList';
import NewConversationPanel from '@/Components/Communication/NewConversationPanel';
import NewGroupPanel from '@/Components/Communication/NewGroupPanel';
import ChatView from '@/Components/Communication/ChatView';
import { ChatIcon } from '@/Components/NavIcons';
import type { PageProps } from '@/types';
import type { ConversationPage, ConversationSummary, ConversationType, MessagesAbilities, MessagesLimits } from '@/types/communication';

type Props = PageProps<{
    initialConversations: ConversationPage;
    activeConversationId: string | null;
    filter: ConversationType | null;
    limits: MessagesLimits;
    reactions: string[];
    can: MessagesAbilities;
    realtime: { enabled: boolean };
}>;

const LIST_POLL_MS = 15000;

const urlFor = (id: string | null) => (id ? route('communication.messages.show', id) : route('communication.messages.index'));

/**
 * Fills the space below the app header, whatever the header and banners
 * measure, so the page never scrolls: only the conversation list and the
 * messages do. The bottom gap matches the focus-mode padding of the layout.
 */
function useFillHeight(): [React.RefObject<HTMLDivElement>, number | null] {
    const ref = useRef<HTMLDivElement>(null);
    const [height, setHeight] = useState<number | null>(null);
    useLayoutEffect(() => {
        const measure = () => {
            const top = ref.current?.getBoundingClientRect().top ?? 0;
            const gap = window.innerWidth >= 1024 ? 16 : window.innerWidth >= 640 ? 12 : 0;
            setHeight(Math.max(420, window.innerHeight - top - gap));
        };
        measure();
        window.addEventListener('resize', measure);
        return () => window.removeEventListener('resize', measure);
    }, []);
    return [ref, height];
}

export default function Messages(props: Props): JSX.Element {
    const { initialConversations, limits, can, reactions, realtime } = props;
    const { t } = useLocale();
    const userId = usePage<PageProps>().props.auth.user?.id as number;

    const [items, setItems] = useState<ConversationSummary[]>(initialConversations.data);
    const [cursor, setCursor] = useState<string | null>(initialConversations.next_cursor);
    const [filter, setFilter] = useState<ConversationType | null>(props.filter);
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(false);
    const [activeId, setActiveId] = useState<string | null>(props.activeConversationId);
    const [composing, setComposing] = useState<null | 'message' | 'group'>(() => {
        if (typeof window === 'undefined') return null;
        const value = new URLSearchParams(window.location.search).get('compose');
        return value === null ? null : value === 'group' ? 'group' : 'message';
    });
    const [detailsOpen, setDetailsOpen] = useState(() => typeof window !== 'undefined' && window.innerWidth >= 1280);
    const [box, height] = useFillHeight();
    const [jumpTo] = useState<string | null>(() => (typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('message')));
    const [socket, setSocket] = useState(false);

    // The socket is an addition: until it connects (or when it is off) everything polls.
    useEffect(() => {
        if (realtime.enabled) void connectRealtime().then(setSocket);
    }, [realtime.enabled]);

    const first = useRef(true);

    const fetchFirstPage = useCallback(async (opts: { filter: ConversationType | null; q: string }) => {
        const page = await communicationApi.conversations({ type: opts.filter, q: opts.q.trim().length >= 2 ? opts.q.trim() : null });
        return page;
    }, []);

    // Filter or search changed: reload the first page.
    useEffect(() => {
        if (first.current) { first.current = false; return; }
        let cancelled = false;
        setLoading(true);
        const timer = window.setTimeout(() => {
            fetchFirstPage({ filter, q: search })
                .then((page) => { if (!cancelled) { setItems(page.data); setCursor(page.next_cursor); } })
                .catch(() => undefined)
                .finally(() => { if (!cancelled) setLoading(false); });
        }, search ? 250 : 0);
        return () => { cancelled = true; window.clearTimeout(timer); };
    }, [filter, search, fetchFirstPage]);

    // Keep the list fresh: new conversations arrive on the person's own channel; otherwise poll.
    const refreshList = useCallback(() => {
        if (document.visibilityState !== 'visible' || search) return;
        fetchFirstPage({ filter, q: '' }).then((page) => {
            setItems((current) => {
                const fresh = new Map(page.data.map((c) => [c.id, c]));
                const rest = current.filter((c) => !fresh.has(c.id));
                return [...page.data, ...rest];
            });
        }).catch(() => undefined);
    }, [fetchFirstPage, filter, search]);

    useEffect(() => {
        const timer = window.setInterval(refreshList, LIST_POLL_MS);
        window.addEventListener('communication:totals-changed', refreshList);
        const stop = socket ? listen(channels.user(userId), { 'conversation.created': refreshList, 'member.added': refreshList }) : () => {};
        return () => { window.clearInterval(timer); window.removeEventListener('communication:totals-changed', refreshList); stop(); };
    }, [refreshList, socket, userId]);

    // Presence heartbeat while the workspace is open.
    useEffect(() => {
        // At most one beat a minute per tab, however often the workspace is opened (the route is throttled).
        const beat = () => {
            if (document.visibilityState !== 'visible') return;
            const now = Date.now();
            try {
                if (now - Number(sessionStorage.getItem('communication:heartbeat') ?? 0) < 55_000) return;
                sessionStorage.setItem('communication:heartbeat', String(now));
            } catch { /* storage unavailable: beat anyway */ }
            void communicationApi.heartbeat().catch(() => undefined);
        };
        beat();
        const timer = window.setInterval(beat, 60000);
        return () => window.clearInterval(timer);
    }, []);

    // Browser back/forward moves between conversations without a page load.
    useEffect(() => {
        const pop = () => {
            const match = /\/communication\/messages\/([0-9a-f-]{36})/i.exec(window.location.pathname);
            setActiveId(match ? match[1] : null);
        };
        window.addEventListener('popstate', pop);
        return () => window.removeEventListener('popstate', pop);
    }, []);

    const select = useCallback((id: string | null) => {
        setActiveId(id);
        setComposing(null);
        if (window.location.href !== urlFor(id)) window.history.pushState(window.history.state, '', urlFor(id));
    }, []);

    const loadMore = async () => {
        if (!cursor) return;
        setLoading(true);
        try {
            const page = await communicationApi.conversations({ type: filter, cursor, q: search.trim().length >= 2 ? search.trim() : null });
            setItems((current) => [...current, ...page.data.filter((c) => !current.some((x) => x.id === c.id))]);
            setCursor(page.next_cursor);
        } finally {
            setLoading(false);
        }
    };

    const onActivity = useCallback((id: string, patch: Partial<ConversationSummary>) => {
        setItems((current) => {
            const index = current.findIndex((c) => c.id === id);
            if (index < 0) {
                if (patch.last_message) refreshList();
                return current;
            }
            const updated = { ...current[index], ...patch };
            const rest = current.filter((c) => c.id !== id);
            return patch.last_message ? [updated, ...rest] : current.map((c) => (c.id === id ? updated : c));
        });
    }, [refreshList]);

    const started = (id: string) => {
        select(id);
        refreshList();
    };

    const activeTitle = items.find((c) => c.id === activeId)?.title;

    return (
        <AuthenticatedLayout mode="communication">
            <Head title={activeTitle ? `${activeTitle} · ${t('communication.messagesTitle')}` : t('communication.messagesTitle')} />
            <div
                ref={box}
                style={height ? { height } : undefined}
                className="flex min-h-[420px] overflow-hidden border-y border-(--app-border) bg-(--app-surface) sm:rounded-panel sm:border"
            >
                <div className={`w-full shrink-0 border-e border-(--app-border) bg-(--app-surface) lg:block lg:w-80 ${activeId ? 'hidden' : 'block'}`}>
                    {composing === 'group' && can.createGroup ? (
                        <NewGroupPanel onBack={() => setComposing(null)} onCreated={started} />
                    ) : composing ? (
                        <NewConversationPanel onBack={() => setComposing(null)} onStarted={started} />
                    ) : (
                        <ConversationList
                            items={items}
                            activeId={activeId}
                            filter={filter}
                            search={search}
                            loading={loading}
                            hasMore={cursor !== null}
                            canStart={can.message}
                            canGroup={can.createGroup}
                            canBrowseChannels={can.browseChannels}
                            onFilter={setFilter}
                            onSearch={setSearch}
                            onSelect={select}
                            onLoadMore={() => void loadMore()}
                            onNew={(kind) => setComposing(kind)}
                        />
                    )}
                </div>

                <div className={`min-w-0 flex-1 ${activeId ? 'block' : 'hidden lg:block'}`}>
                    {activeId ? (
                        <ChatView
                            key={activeId}
                            conversationId={activeId}
                            currentUserId={userId}
                            limits={limits}
                            can={can}
                            reactions={reactions}
                            realtimeEnabled={socket}
                            detailsOpen={detailsOpen}
                            onToggleDetails={setDetailsOpen}
                            onBack={() => select(null)}
                            onActivity={onActivity}
                            initialMessageId={activeId === props.activeConversationId ? jumpTo : null}
                            onGone={(id) => { setItems((current) => current.filter((c) => c.id !== id)); select(null); }}
                        />
                    ) : (
                        <div className="flex h-full flex-col items-center justify-center gap-3 bg-(--app-background) px-6 text-center">
                            <span className="flex h-14 w-14 items-center justify-center rounded-full bg-[color-mix(in_srgb,var(--color-primary)_12%,var(--app-surface))] text-(--app-foreground)">
                                <ChatIcon className="h-7 w-7" aria-hidden="true" />
                            </span>
                            <h1 className="text-base font-semibold text-(--app-foreground)">{t('communication.chat.selectTitle')}</h1>
                            <p className="max-w-xs text-sm text-(--app-muted-foreground)">{t('communication.chat.selectHelp')}</p>
                            {can.message && (
                                <Button type="button" variant="primary" className="mt-1" onClick={() => setComposing('message')}>
                                    {t('communication.dashboard.newMessage')}
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
