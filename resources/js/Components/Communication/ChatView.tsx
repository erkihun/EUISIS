import { Fragment, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type JSX } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/Components/ConfirmProvider';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { toast } from '@/lib/toast';
import { formatRelative } from '@/lib/relativeTime';
import { announceTotalsChanged, communicationApi, firstError, newClientId, statusOf } from '@/lib/communication/api';
import { channels, listen, onRealtimeState, realtimeState, type RealtimeState } from '@/lib/communication/realtime';
import { dayKey, dayLabel, tr } from '@/lib/communication/format';
import Avatar from '@/Components/Communication/Avatar';
import MessageBubble, { type BubbleAbilities } from '@/Components/Communication/MessageBubble';
import Composer, { type ComposerSubmit } from '@/Components/Communication/Composer';
import DetailsPanel from '@/Components/Communication/DetailsPanel';
import ForwardDialog from '@/Components/Communication/ForwardDialog';
import TaskDialog from '@/Components/Communication/TaskDialog';
import ReportDialog, { type ReportTarget } from '@/Components/Communication/ReportDialog';
import MessageText from '@/Components/Communication/MessageText';
import { ArrowLeftIcon, ChatIcon, DangerCircleIcon, MenuDotsIcon, OfflineIcon, PaperclipIcon, RefreshIcon } from '@/Components/NavIcons';
import type {
    ConversationDetail, ConversationSummary, MessageItem, MessagesAbilities, MessagesLimits, PendingMessage, Reaction,
} from '@/types/communication';

type Props = {
    conversationId: string;
    currentUserId: number;
    limits: MessagesLimits;
    can: MessagesAbilities;
    reactions: readonly string[];
    realtimeEnabled: boolean;
    detailsOpen: boolean;
    onToggleDetails: (open: boolean) => void;
    onBack: () => void;
    onActivity: (conversationId: string, patch: Partial<ConversationSummary>) => void;
    onGone: (conversationId: string) => void;
    /** Open on this message (from a task or a link) instead of the latest. */
    initialMessageId?: string | null;
};

type Outgoing = PendingMessage & { parentId: string | null; attachmentIds: string[] };

const POLL_MS = 5000;
const SAFETY_POLL_MS = 30000;
const DETAIL_POLL_MS = 30000;
const GROUP_GAP_MS = 5 * 60 * 1000;

/** Newest-wins merge keyed by id, kept in ascending (UUIDv7 = time) order. */
function merge(current: MessageItem[], incoming: MessageItem[]): MessageItem[] {
    if (incoming.length === 0) return current;
    const byId = new Map(current.map((m) => [m.id, m]));
    for (const message of incoming) byId.set(message.id, message);
    return Array.from(byId.values()).sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
}

function excerpt(message: MessageItem): ConversationSummary['last_message'] {
    return {
        sender_name: message.sender?.name ?? null,
        deleted: message.deleted,
        excerpt: message.body ? message.body.replace(/<@\d+>/g, (token) => {
            const id = Number(token.slice(2, -1));
            return `@${message.mentions.find((m) => m.user_id === id)?.name ?? ''}`;
        }).replace(/<!everyone>|<!channel>/g, '@everyone').slice(0, 120) : null,
        has_attachments: message.attachments.length > 0,
        type: message.type,
    };
}

export default function ChatView(props: Props): JSX.Element {
    const { conversationId, currentUserId, limits, can, realtimeEnabled, detailsOpen } = props;
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const { confirm } = useConfirm();

    const [detail, setDetail] = useState<ConversationDetail | null>(null);
    const [loadError, setLoadError] = useState<'notFound' | 'failed' | null>(null);
    const [messages, setMessages] = useState<MessageItem[]>([]);
    const [nextBefore, setNextBefore] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [outgoing, setOutgoing] = useState<Outgoing[]>([]);
    const [replyTo, setReplyTo] = useState<MessageItem | null>(null);
    const [editing, setEditing] = useState<MessageItem | null>(null);
    const [forwarding, setForwarding] = useState<MessageItem | null>(null);
    const [tasking, setTasking] = useState<MessageItem | null>(null);
    const [reporting, setReporting] = useState<ReportTarget | null>(null);
    const [pinned, setPinned] = useState<Set<string>>(new Set());
    const [pinsVersion, setPinsVersion] = useState(0);
    const [typing, setTyping] = useState<Map<number, { name: string; until: number }>>(new Map());
    const [highlight, setHighlight] = useState<string | null>(null);
    const [atBottom, setAtBottom] = useState(true);
    const [unseen, setUnseen] = useState(0);
    const [rt, setRt] = useState<RealtimeState>(() => realtimeState());
    const [online, setOnline] = useState(() => (typeof navigator === 'undefined' ? true : navigator.onLine));

    const scroller = useRef<HTMLDivElement>(null);
    const preserve = useRef<number | null>(null);
    const stickToBottom = useRef(true);
    const lastTypingSent = useRef(0);
    const lastMarked = useRef<string | null>(null);
    const unreadFrom = useRef<string | null>(null);
    const latestId = useRef<string | null>(null);

    latestId.current = messages.length > 0 ? messages[messages.length - 1].id : null;

    // ── Loading ──────────────────────────────────────────────────────────────
    useEffect(() => {
        let cancelled = false;
        setDetail(null);
        setMessages([]);
        setOutgoing([]);
        setNextBefore(null);
        setLoadError(null);
        setLoading(true);
        setReplyTo(null);
        setEditing(null);
        setTyping(new Map());
        setUnseen(0);
        lastMarked.current = null;
        stickToBottom.current = true;

        Promise.all([communicationApi.conversation(conversationId), communicationApi.messages(conversationId), communicationApi.pins(conversationId).catch(() => [])])
            .then(([conversation, page, pins]) => {
                if (cancelled) return;
                unreadFrom.current = conversation.my_last_read_message_id;
                setDetail(conversation);
                setMessages([...page.data].reverse());
                setNextBefore(page.next_before);
                setPinned(new Set(pins.map((pin) => pin.message.id)));
            })
            .catch((reason) => {
                if (!cancelled) setLoadError(statusOf(reason) === 404 || statusOf(reason) === 403 ? 'notFound' : 'failed');
            })
            .finally(() => { if (!cancelled) setLoading(false); });

        return () => { cancelled = true; };
    }, [conversationId]);

    const refreshLatest = useCallback(async () => {
        try {
            const page = await communicationApi.messages(conversationId);
            setMessages((current) => merge(current, page.data));
        } catch {
            /* the next poll or event will catch up */
        }
    }, [conversationId]);

    const refreshDetail = useCallback(async () => {
        try {
            setDetail(await communicationApi.conversation(conversationId));
        } catch (reason) {
            if (statusOf(reason) === 404 || statusOf(reason) === 403) setLoadError('notFound');
        }
    }, [conversationId]);

    // ── Realtime, with HTTP polling whenever the socket is not connected ────────
    // Reports a dropped socket only after it had been up: never connecting is not an outage, polling covers it.
    const [everConnected, setEverConnected] = useState(false);
    useEffect(() => {
        setRt(realtimeState());
        return onRealtimeState(setRt);
    }, [realtimeEnabled]);
    useEffect(() => { if (rt === 'connected') setEverConnected(true); }, [rt]);

    useEffect(() => {
        const up = () => { setOnline(true); void refreshLatest(); };
        const down = () => setOnline(false);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);
        return () => { window.removeEventListener('online', up); window.removeEventListener('offline', down); };
    }, [refreshLatest]);

    // Coming back from a dropped socket: fetch what was missed while it was down.
    const previousRt = useRef(rt);
    useEffect(() => {
        if (rt === 'connected' && previousRt.current !== 'connected' && !loading) {
            void refreshLatest();
            void refreshDetail();
        }
        previousRt.current = rt;
    }, [rt, loading, refreshLatest, refreshDetail]);

    useEffect(() => {
        if (!realtimeEnabled) return;

        return listen(channels.conversation(conversationId), {
            'message.sent': (payload) => {
                const message = payload.message as MessageItem;
                setMessages((current) => merge(current, [message]));
                if (message.sender?.id !== currentUserId) {
                    setTyping((current) => { const next = new Map(current); next.delete(message.sender?.id ?? -1); return next; });
                }
            },
            'message.edited': (payload) => setMessages((current) => merge(current, [payload.message as MessageItem])),
            'message.deleted': (payload) => setMessages((current) => current.map((m) => (m.id === payload.message_id
                ? { ...m, deleted: true, body: null, attachments: [], reactions: [], mentions: [] } : m))),
            'reaction.added': (payload) => setMessages((current) => current.map((m) => (m.id === payload.message_id ? { ...m, reactions: payload.reactions as Reaction[] } : m))),
            'reaction.removed': (payload) => setMessages((current) => current.map((m) => (m.id === payload.message_id ? { ...m, reactions: payload.reactions as Reaction[] } : m))),
            'message.read': (payload) => setDetail((current) => current && {
                ...current,
                members: current.members.map((member) => (member.id === payload.user_id
                    ? { ...member, last_read_message_id: payload.last_read_message_id as string, last_delivered_message_id: payload.last_read_message_id as string } : member)),
            }),
            'user.typing': (payload) => {
                const id = Number(payload.user_id);
                if (id === currentUserId) return;
                setTyping((current) => new Map(current).set(id, { name: String(payload.name ?? ''), until: Date.now() + 5000 }));
            },
            'member.added': () => void refreshDetail(),
            'member.removed': () => void refreshDetail(),
        });
    }, [conversationId, realtimeEnabled, currentUserId, refreshDetail]);

    // Polling is the source of truth's safety net: every 5 s without a socket, and a slow
    // 30 s check with one, in case broadcasts stop (for example no queue worker is running).
    const connected = realtimeEnabled && rt === 'connected';
    useEffect(() => {
        if (loading || loadError) return;
        const fast = window.setInterval(() => { if (document.visibilityState === 'visible') void refreshLatest(); }, connected ? SAFETY_POLL_MS : POLL_MS);
        const slow = window.setInterval(() => { if (document.visibilityState === 'visible') void refreshDetail(); }, DETAIL_POLL_MS);
        return () => { window.clearInterval(fast); window.clearInterval(slow); };
    }, [connected, loading, loadError, refreshLatest, refreshDetail]);

    useEffect(() => {
        if (typing.size === 0) return;
        const timer = window.setInterval(() => {
            setTyping((current) => {
                const now = Date.now();
                const next = new Map([...current].filter(([, entry]) => entry.until > now));
                return next.size === current.size ? current : next;
            });
        }, 1000);
        return () => window.clearInterval(timer);
    }, [typing.size]);

    // ── Scrolling ────────────────────────────────────────────────────────────
    const scrollToBottom = useCallback((smooth = false) => {
        const el = scroller.current;
        if (el) el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
        setUnseen(0);
    }, []);

    const lastCount = useRef(0);
    useLayoutEffect(() => {
        const el = scroller.current;
        if (!el) return;
        if (preserve.current !== null) {
            el.scrollTop = el.scrollHeight - preserve.current;
            preserve.current = null;
        } else if (stickToBottom.current) {
            el.scrollTop = el.scrollHeight;
        } else if (messages.length > lastCount.current) {
            setUnseen((n) => n + (messages.length - lastCount.current));
        }
        lastCount.current = messages.length;
    }, [messages, outgoing]);

    // First open: land on the first unread message if there is one.
    const landed = useRef<string | null>(null);
    useLayoutEffect(() => {
        if (loading || landed.current === conversationId) return;
        landed.current = conversationId;
        if (props.initialMessageId) {
            stickToBottom.current = false;
            void jump(props.initialMessageId);
            return;
        }
        const first = messages.find((m) => unreadFrom.current && m.id > unreadFrom.current && m.sender?.id !== currentUserId);
        if (first && unreadFrom.current) {
            document.getElementById(`m-${first.id}`)?.scrollIntoView({ block: 'center' });
            stickToBottom.current = false;
        } else {
            scrollToBottom();
        }
    }, [loading, conversationId, messages, currentUserId, scrollToBottom]);

    const loadOlder = useCallback(async () => {
        if (!nextBefore || loadingOlder) return null;
        setLoadingOlder(true);
        try {
            const page = await communicationApi.messages(conversationId, nextBefore);
            preserve.current = scroller.current ? scroller.current.scrollHeight - scroller.current.scrollTop : null;
            setMessages((current) => merge(current, page.data));
            setNextBefore(page.next_before);
            return page.next_before;
        } catch {
            toast.error(t('communication.errors.loadFailed'));
            return null;
        } finally {
            setLoadingOlder(false);
        }
    }, [conversationId, nextBefore, loadingOlder, t]);

    const onScroll = () => {
        const el = scroller.current;
        if (!el) return;
        const bottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
        stickToBottom.current = bottom;
        setAtBottom(bottom);
        if (bottom) setUnseen(0);
        if (el.scrollTop < 120 && nextBefore && !loadingOlder) void loadOlder();
    };

    // ── Read pointer ─────────────────────────────────────────────────────────
    useEffect(() => {
        if (loading || !atBottom || !latestId.current) return;
        const target = latestId.current;
        if (lastMarked.current && lastMarked.current >= target) return;
        const timer = window.setTimeout(() => {
            if (document.visibilityState !== 'visible') return;
            lastMarked.current = target;
            communicationApi.read(conversationId, target).then(() => {
                props.onActivity(conversationId, { unread_count: 0 });
                announceTotalsChanged();
            }).catch(() => { lastMarked.current = null; });
        }, 600);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [messages, atBottom, loading, conversationId]);

    // ── Actions ──────────────────────────────────────────────────────────────
    const deliver = useCallback(async (item: Outgoing) => {
        setOutgoing((current) => current.map((o) => (o.clientId === item.clientId ? { ...o, status: 'sending' } : o)));
        try {
            const message = await communicationApi.send(conversationId, {
                body: item.body || undefined,
                parent_message_id: item.parentId,
                attachment_ids: item.attachmentIds,
                client_message_id: item.clientId,
            });
            stickToBottom.current = true;
            setOutgoing((current) => current.filter((o) => o.clientId !== item.clientId));
            setMessages((current) => merge(current, [message]));
            props.onActivity(conversationId, { last_message: excerpt(message), last_message_at: message.created_at, unread_count: 0 });
        } catch (reason) {
            setOutgoing((current) => current.map((o) => (o.clientId === item.clientId ? { ...o, status: 'failed' } : o)));
            const status = statusOf(reason);
            if (status === 422 || status === 403 || status === 429) toast.error(firstError(reason) ?? t('communication.errors.sendFailed'));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [conversationId, t]);

    const send = ({ body, attachments }: ComposerSubmit) => {
        const item: Outgoing = {
            clientId: newClientId(),
            body,
            attachments,
            parent: replyTo,
            parentId: replyTo?.id ?? null,
            attachmentIds: attachments.map((a) => a.id),
            status: 'sending',
            createdAt: new Date().toISOString(),
        };
        stickToBottom.current = true;
        setReplyTo(null);
        setOutgoing((current) => [...current, item]);
        void deliver(item);
    };

    const saveEdit = async (message: MessageItem, body: string): Promise<boolean> => {
        try {
            const updated = await communicationApi.edit(message.id, body);
            setMessages((current) => merge(current, [updated]));
            return true;
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.errors.generic'));
            return false;
        }
    };

    const remove = useCallback(async (message: MessageItem) => {
        const answer = await confirm({
            title: t('communication.confirm.deleteTitle'),
            description: t('communication.confirm.deleteMessage'),
            confirmLabel: t('communication.confirm.deleteConfirm'),
            variant: 'danger',
        });
        if (!answer.confirmed) return;
        try {
            await communicationApi.remove(message.id);
            setMessages((current) => current.map((m) => (m.id === message.id ? { ...m, deleted: true, body: null, attachments: [], reactions: [], mentions: [] } : m)));
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.errors.generic'));
        }
    }, [confirm, t]);

    const react = useCallback(async (message: MessageItem, emoji: string, add: boolean) => {
        try {
            const reactions = await communicationApi.react(message.id, emoji, add);
            setMessages((current) => current.map((m) => (m.id === message.id ? { ...m, reactions } : m)));
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.errors.generic'));
        }
    }, [t]);

    const pin = useCallback(async (message: MessageItem, add: boolean) => {
        try {
            await communicationApi.pin(message.id, add);
            setPinned((current) => { const next = new Set(current); if (add) next.add(message.id); else next.delete(message.id); return next; });
            setPinsVersion((v) => v + 1);
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.errors.generic'));
        }
    }, [t]);

    const jump = useCallback(async (messageId: string) => {
        let guard = 0;
        let before = nextBefore;
        while (!document.getElementById(`m-${messageId}`) && before && guard < 10) {
            const page = await communicationApi.messages(conversationId, before).catch(() => null);
            if (!page) break;
            setMessages((current) => merge(current, page.data));
            setNextBefore(page.next_before);
            before = page.next_before;
            guard += 1;
            await new Promise((resolve) => requestAnimationFrame(resolve));
        }
        requestAnimationFrame(() => {
            const el = document.getElementById(`m-${messageId}`);
            if (!el) return;
            stickToBottom.current = false;
            el.scrollIntoView({ block: 'center', behavior: 'smooth' });
            setHighlight(messageId);
            window.setTimeout(() => setHighlight(null), 1600);
        });
    }, [conversationId, nextBefore]);

    const onTyping = () => {
        const now = Date.now();
        if (now - lastTypingSent.current < 3000 || !realtimeEnabled) return;
        lastTypingSent.current = now;
        void communicationApi.typing(conversationId).catch(() => undefined);
    };

    const mute = async (value: boolean) => {
        try {
            await communicationApi.mute(conversationId, value);
            setDetail((current) => current && { ...current, muted: value });
            props.onActivity(conversationId, { muted: value });
            announceTotalsChanged();
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.errors.generic'));
        }
    };

    // ── Derived ──────────────────────────────────────────────────────────────
    const now = Date.now();
    const abilitiesFor = useCallback((message: MessageItem): BubbleAbilities => {
        const own = message.sender?.id === currentUserId;
        const age = message.created_at ? (now - new Date(message.created_at).getTime()) / 60000 : Infinity;
        const post = detail?.can.post ?? false;
        return {
            reply: post && !message.deleted,
            react: post && !message.deleted,
            edit: own && !message.deleted && !!message.body && limits.editWindowMinutes > 0 && age <= limits.editWindowMinutes,
            delete: own && !message.deleted && limits.deleteWindowMinutes > 0 && age <= limits.deleteWindowMinutes,
            pin: (detail?.can.pin ?? false) && !message.deleted,
            forward: can.forward && !message.deleted,
            task: can.createTask && !message.deleted && message.type !== 'SYSTEM' && !(detail?.archived ?? false),
            report: can.report && !own && !message.deleted && message.type !== 'SYSTEM',
        };
    }, [currentUserId, detail, limits, can.forward, now]);

    const lastOwnId = useMemo(() => [...messages].reverse().find((m) => m.sender?.id === currentUserId && !m.deleted)?.id ?? null, [messages, currentUserId]);

    const receipt = (messageId: string): string | null => {
        if (!detail || messageId !== lastOwnId || outgoing.length > 0) return null;
        const others = detail.members.filter((m) => m.id !== currentUserId);
        if (others.length === 0 || (!detail.members_complete && detail.type !== 'DIRECT')) return null;
        if (others.every((m) => m.last_read_message_id && m.last_read_message_id >= messageId)) return t('communication.chat.read');
        if (others.every((m) => m.last_delivered_message_id && m.last_delivered_message_id >= messageId)) return t('communication.chat.delivered');
        return t('communication.chat.sent');
    };

    const typingNames = [...typing.values()].map((entry) => entry.name).filter(Boolean);
    const typingText = typingNames.length === 0 ? null : typingNames.length === 1 ? tr(t('communication.chat.typingOne'), { name: typingNames[0] }) : t('communication.chat.typingMany');

    // ── Render ───────────────────────────────────────────────────────────────
    if (loadError) {
        return (
            <section className="flex h-full flex-col items-center justify-center gap-3 px-6 text-center">
                <DangerCircleIcon className="h-8 w-8 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                <p className="text-sm text-[color:var(--app-foreground)]">{loadError === 'notFound' ? t('communication.errors.notFound') : t('communication.errors.loadFailed')}</p>
                <div className="flex gap-2">
                    <button type="button" onClick={props.onBack} className="h-8 rounded-control border border-[color:var(--app-border)] px-3 text-xs font-medium lg:hidden">{t('communication.chat.back')}</button>
                    {loadError === 'failed' && (
                        <button type="button" onClick={() => window.location.reload()} className="inline-flex h-8 items-center gap-1.5 rounded-control bg-[color:var(--color-primary)] px-3 text-xs font-medium text-white">
                            <RefreshIcon className="h-4 w-4" aria-hidden="true" />{t('communication.chat.retry')}
                        </button>
                    )}
                </div>
            </section>
        );
    }

    const subtitle = !detail ? '' : detail.type === 'DIRECT'
        ? (detail.other_user?.online ? t('communication.list.online') : detail.other_user?.last_seen_at ? tr(t('communication.list.lastSeen'), { time: formatRelative(detail.other_user.last_seen_at, locale) ?? '' }) : '')
        : tr(t('communication.details.memberCount'), { n: detail.member_count });

    let previous: MessageItem | null = null;
    const newDividerAt = unreadFrom.current ? messages.find((m) => m.id > unreadFrom.current! && m.sender?.id !== currentUserId)?.id ?? null : null;

    return (
        <div className="flex h-full min-h-0">
            <section aria-label={detail?.title ?? t('communication.chat.scrollRegion')} className="relative flex min-w-0 flex-1 flex-col bg-[color:var(--app-background)]">
                <header className="flex h-14 shrink-0 items-center gap-2 border-b border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-2 sm:px-4">
                    <button
                        type="button"
                        onClick={props.onBack}
                        aria-label={t('communication.chat.back')}
                        className="flex h-9 w-9 items-center justify-center rounded-control text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)] lg:hidden"
                    >
                        <ArrowLeftIcon className="h-5 w-5" />
                    </button>
                    {detail ? (
                        <>
                            <Avatar name={detail.title} kind={detail.type} userId={detail.other_user?.id} hasPhoto={detail.has_avatar} online={detail.other_user?.online ?? null} size="sm" />
                            <div className="min-w-0 flex-1">
                                <h2 className="truncate text-sm font-semibold text-[color:var(--app-foreground)]">{detail.title}</h2>
                                <p className="truncate text-xs text-[color:var(--app-muted-foreground)]" aria-live="polite">{typingText ?? subtitle}</p>
                            </div>
                        </>
                    ) : (
                        <div className="flex flex-1 animate-pulse items-center gap-2" aria-hidden="true">
                            <span className="h-8 w-8 rounded-full bg-[color:var(--app-surface-muted)]" />
                            <span className="h-3 w-40 rounded bg-[color:var(--app-surface-muted)]" />
                        </div>
                    )}
                    <button
                        type="button"
                        onClick={() => props.onToggleDetails(!detailsOpen)}
                        aria-label={detailsOpen ? t('communication.chat.hideDetails') : t('communication.chat.details')}
                        aria-expanded={detailsOpen}
                        title={t('communication.chat.details')}
                        className={`flex h-9 w-9 items-center justify-center rounded-control hover:bg-[color:var(--app-surface-muted)] ${detailsOpen ? 'text-[color:var(--color-primary)]' : 'text-[color:var(--app-muted-foreground)]'}`}
                    >
                        <MenuDotsIcon className="h-5 w-5" />
                    </button>
                </header>

                {(!online || (realtimeEnabled && everConnected && rt !== 'connected' && !loading)) && (
                    <div role="status" className="flex shrink-0 items-center gap-2 border-b border-[color:var(--tone-warning-border,#fedf89)] bg-[color:var(--tone-warning-bg,#fffaeb)] px-4 py-1.5 text-xs text-[color:var(--app-foreground)]">
                        <OfflineIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                        {!online ? t('communication.chat.offline') : t('communication.chat.reconnecting')}
                    </div>
                )}

                <div
                    ref={scroller}
                    onScroll={onScroll}
                    role="log"
                    aria-label={t('communication.chat.scrollRegion')}
                    aria-busy={loading}
                    tabIndex={0}
                    className="comm-scroll min-h-0 flex-1 overflow-y-auto overscroll-contain py-3 focus:outline-none"
                >
                    {loading ? <MessagesSkeleton /> : (
                        <>
                            <div className="flex justify-center py-2">
                                {nextBefore ? (
                                    <button type="button" onClick={() => void loadOlder()} disabled={loadingOlder} className="h-7 rounded-full px-3 text-xs font-medium text-[color:var(--color-primary)] hover:bg-[color:var(--app-surface-muted)] disabled:opacity-60">
                                        {loadingOlder ? t('communication.chat.loadingOlder') : t('communication.chat.loadOlder')}
                                    </button>
                                ) : messages.length > 0 ? (
                                    <span className="text-xs text-[color:var(--app-muted-foreground)]">{t('communication.chat.beginning')}</span>
                                ) : null}
                            </div>

                            {messages.length === 0 && outgoing.length === 0 && detail && (
                                <div className="mx-auto mt-10 flex max-w-xs flex-col items-center gap-3 px-6 text-center">
                                    <Avatar name={detail.title} kind={detail.type} userId={detail.other_user?.id} hasPhoto={detail.has_avatar} size="lg" />
                                    <p className="text-sm font-medium text-[color:var(--app-foreground)]">{detail.title}</p>
                                    <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('communication.chat.beginning')}</p>
                                </div>
                            )}

                            <ol className="list-none">
                                {messages.map((message) => {
                                    const prev = previous;
                                    previous = message;
                                    const newDay = !prev || dayKey(prev.created_at) !== dayKey(message.created_at);
                                    const continued = !newDay && !!prev && prev.type !== 'SYSTEM' && message.type !== 'SYSTEM'
                                        && prev.sender?.id === message.sender?.id
                                        && new Date(message.created_at ?? 0).getTime() - new Date(prev.created_at ?? 0).getTime() < GROUP_GAP_MS
                                        && message.id !== newDividerAt;
                                    const own = message.sender?.id === currentUserId;
                                    return (
                                        <Fragment key={message.id}>
                                            {newDay && (
                                                <li className="sticky top-0 z-[5] flex justify-center py-2" role="separator">
                                                    <span className="rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-3 py-0.5 text-[11px] font-medium text-[color:var(--app-muted-foreground)] shadow-sm">
                                                        {dayLabel(message.created_at, locale, system, t('communication.chat.today'), t('communication.chat.yesterday'))}
                                                    </span>
                                                </li>
                                            )}
                                            {message.id === newDividerAt && (
                                                <li className="my-2 flex items-center gap-3 px-5" role="separator" aria-label={t('communication.chat.newMessages')}>
                                                    <span className="h-px flex-1 bg-[color:var(--color-danger,#b42318)]/40" />
                                                    <span className="text-[11px] font-semibold uppercase tracking-wide text-[color:var(--color-danger,#b42318)]">{t('communication.chat.newMessages')}</span>
                                                    <span className="h-px flex-1 bg-[color:var(--color-danger,#b42318)]/40" />
                                                </li>
                                            )}
                                            <MessageBubble
                                                message={message}
                                                own={own}
                                                continued={continued}
                                                showSender={detail?.type !== 'DIRECT' && !continued}
                                                currentUserId={currentUserId}
                                                pinned={pinned.has(message.id)}
                                                reactions={props.reactions}
                                                can={abilitiesFor(message)}
                                                status={own ? receipt(message.id) : null}
                                                highlighted={highlight === message.id}
                                                onReply={setReplyTo}
                                                onEdit={setEditing}
                                                onDelete={remove}
                                                onReact={react}
                                                onForward={setForwarding}
                                                onPin={pin}
                                                onJump={jump}
                                                onTask={setTasking}
                                                onReport={(m) => setReporting({ kind: 'message', id: m.id })}
                                            />
                                        </Fragment>
                                    );
                                })}
                                {outgoing.map((item) => (
                                    <li key={item.clientId} className="mt-2 flex justify-end px-3 sm:px-5">
                                        <div className="flex max-w-[min(36rem,85%)] flex-col items-end">
                                            <div className={`rounded-panel rounded-se-sm border px-3.5 py-2 text-sm leading-relaxed text-[color:var(--app-foreground)] ${item.status === 'failed' ? 'border-[color:var(--tone-danger-border,#f2b8b5)] bg-[color:var(--tone-danger-bg,#fef3f2)]' : 'border-dashed border-[color:color-mix(in_srgb,var(--color-primary)_30%,var(--app-surface))] bg-[color:color-mix(in_srgb,var(--color-primary)_7%,var(--app-surface))]'}`}>
                                                {item.body && <MessageText body={item.body} mentions={[]} currentUserId={currentUserId} />}
                                                {item.attachments.map((file) => (
                                                    <span key={file.id} className="mt-1 flex items-center gap-1.5 text-xs"><PaperclipIcon className="h-3.5 w-3.5" aria-hidden="true" />{file.name}</span>
                                                ))}
                                            </div>
                                            <span className="mt-0.5 flex items-center gap-2 px-1 text-[11px] text-[color:var(--app-muted-foreground)]" aria-live="polite">
                                                {item.status === 'sending' ? t('communication.chat.sending') : (
                                                    <>
                                                        <span className="font-medium text-[color:var(--color-danger,#b42318)]">{t('communication.chat.failed')}</span>
                                                        <button type="button" onClick={() => void deliver(item)} className="font-medium text-[color:var(--color-primary)] underline-offset-2 hover:underline">{t('communication.chat.retry')}</button>
                                                        <button type="button" onClick={() => setOutgoing((current) => current.filter((o) => o.clientId !== item.clientId))} className="hover:underline">{t('communication.chat.discard')}</button>
                                                    </>
                                                )}
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </>
                    )}
                </div>

                {!atBottom && !loading && (
                    <button
                        type="button"
                        onClick={() => { stickToBottom.current = true; scrollToBottom(true); }}
                        className="absolute bottom-28 end-5 z-10 inline-flex h-9 items-center gap-1.5 rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-3 text-xs font-medium text-[color:var(--app-foreground)] shadow-md hover:bg-[color:var(--app-surface-muted)]"
                    >
                        {t('communication.chat.jumpToLatest')}
                        {unseen > 0 && <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-[color:var(--color-primary)] px-1.5 text-[11px] font-semibold tabular-nums text-white">{unseen}</span>}
                    </button>
                )}

                {detail && (detail.archived ? (
                    <p className="flex shrink-0 items-center justify-center gap-2 border-t border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-4 py-4 text-xs text-[color:var(--app-muted-foreground)]">
                        {t('communication.chat.archived')}
                    </p>
                ) : detail.can.post && can.message ? (
                    <Composer
                        conversationId={conversationId}
                        placeholder={detail.type === 'DIRECT' ? tr(t('communication.composer.placeholderTo'), { name: detail.title }) : t('communication.composer.placeholder')}
                        limits={limits}
                        canUpload={can.upload}
                        members={detail.members}
                        currentUserId={currentUserId}
                        allowEveryone={detail.can.mentionEveryone}
                        replyTo={replyTo}
                        editing={editing}
                        onCancelReply={() => setReplyTo(null)}
                        onCancelEdit={() => setEditing(null)}
                        onSend={send}
                        onSaveEdit={saveEdit}
                        onTyping={onTyping}
                    />
                ) : (
                    <p className="flex shrink-0 items-center justify-center gap-2 border-t border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-4 py-4 text-xs text-[color:var(--app-muted-foreground)]">
                        <ChatIcon className="h-4 w-4" aria-hidden="true" />{t('communication.chat.cannotPost')}
                    </p>
                ))}
            </section>

            {detailsOpen && detail && (
                <>
                    <button type="button" aria-hidden="true" tabIndex={-1} onClick={() => props.onToggleDetails(false)} className="fixed inset-0 z-30 bg-slate-950/40 xl:hidden" />
                    <div className="fixed inset-y-0 end-0 z-40 w-[min(22rem,90vw)] border-s border-[color:var(--app-border)] shadow-2xl xl:static xl:z-auto xl:w-80 xl:shrink-0 xl:shadow-none">
                        <DetailsPanel
                            detail={detail}
                            currentUserId={currentUserId}
                            pinsVersion={pinsVersion}
                            onClose={() => props.onToggleDetails(false)}
                            onMute={(value) => void mute(value)}
                            onJump={(id) => { if (window.innerWidth < 1280) props.onToggleDetails(false); void jump(id); }}
                            onChanged={() => { void refreshDetail(); void refreshLatest(); announceTotalsChanged(); }}
                            onGone={() => props.onGone(conversationId)}
                            onReport={() => setReporting({ kind: 'conversation', id: conversationId })}
                        />
                    </div>
                </>
            )}

            <ForwardDialog message={forwarding} onClose={() => setForwarding(null)} />
            <TaskDialog message={tasking} detail={detail} onClose={() => setTasking(null)} />
            <ReportDialog target={reporting} onClose={() => setReporting(null)} />
        </div>
    );
}

function MessagesSkeleton(): JSX.Element {
    const rows = [['w-48', false], ['w-64', false], ['w-40', true], ['w-72', false], ['w-56', true], ['w-32', true]] as const;
    return (
        <ul aria-hidden="true" className="animate-pulse space-y-3 px-5 py-4">
            {rows.map(([width, own], index) => (
                <li key={index} className={`flex ${own ? 'justify-end' : 'justify-start'}`}>
                    <span className={`block h-9 ${width} rounded-panel ${own ? 'bg-[color:var(--color-primary)]/15' : 'bg-[color:var(--app-surface-muted)]'}`} />
                </li>
            ))}
        </ul>
    );
}
