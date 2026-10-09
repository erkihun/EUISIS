import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';

type Notice = { id: string; title: string; message: string; url: string | null; read: boolean; created_at: string | null };
type Feed = { unread_count: number; items: Notice[]; page: number; last_page: number };

export default function NotificationBell() {
    const { t, locale } = useLocale();
    const { url, props } = usePage<PageProps & { has_employee_record?: boolean; notifications?: unknown }>();
    const [feed, setFeed] = useState<Feed | null>(null);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(false);
    const pending = useRef<AbortController | null>(null);
    const mutating = useRef(false);
    const label = (key: string) => t(`nav.notificationBell.${key}`);

    const refresh = useCallback(async () => {
        if (mutating.current) return;
        pending.current?.abort();
        const controller = new AbortController();
        pending.current = controller;
        setLoading(true);
        try {
            const response = await axios.get<Feed>(route('notifications.feed'), { params: { page, locale }, signal: controller.signal, headers: { 'X-Activity': 'passive' } });
            setFeed(response.data);
            setError(false);
        } catch (error) {
            if (!axios.isCancel(error)) setError(true);
        } finally {
            if (!controller.signal.aborted) setLoading(false);
        }
    }, [page, locale]);

    useEffect(() => {
        void refresh();
        const poll = () => { if (!document.hidden) void refresh(); };
        const timer = window.setInterval(poll, 60000);
        window.addEventListener('focus', poll);
        // Any completed page request (e.g. marking read on My Portal) may change the count.
        const stopListening = router.on('success', poll);
        return () => { clearInterval(timer); window.removeEventListener('focus', poll); stopListening(); pending.current?.abort(); };
    }, [refresh, url]);

    async function markRead(notice?: Notice, open = false) {
        if (mutating.current) return;
        mutating.current = true;
        pending.current?.abort();
        setBusy(true);
        setError(false);
        try {
            await axios.post(notice ? route('notifications.read', notice.id) : route('notifications.read-all'));
            mutating.current = false;
            await refresh();
            if (open && notice?.url) {
                router.visit(notice.url);
            } else if (props.notifications !== undefined) {
                router.reload({ only: ['notifications'] });
            }
        } catch {
            setError(true);
        } finally {
            mutating.current = false;
            setBusy(false);
            setLoading(false);
        }
    }

    const unread = feed?.unread_count ?? 0;
    const actionCls = 'rounded px-2 py-1 text-xs font-semibold text-[color:var(--color-primary)] hover:bg-[color:var(--app-surface-muted)] disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[color:var(--color-primary)]';

    return (
        <Popover key={url} className="relative">
            <PopoverButton
                onClick={() => void refresh()}
                aria-label={`${label('title')}${unread ? ` (${unread} ${label('unread')})` : ''}`}
                className="relative flex h-10 w-10 items-center justify-center rounded-lg text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[color:var(--color-primary)]"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M10 21h4" /></svg>
                {unread > 0 && <span aria-hidden="true" className="absolute -right-0.5 -top-0.5 min-w-4 rounded-full bg-red-600 px-1 text-[10px] font-bold leading-4 text-white">{unread > 99 ? '99+' : unread}</span>}
                {error && !feed && <span aria-hidden="true" className="absolute right-0 top-0 text-xs text-amber-600">!</span>}
            </PopoverButton>
            <PopoverPanel anchor="bottom end" className="z-50 w-96 max-w-[calc(100vw-1rem)] rounded-xl border border-[color:var(--app-border)] bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] shadow-xl outline-none [--anchor-gap:8px] [--anchor-padding:8px]">
                <div className="flex items-center justify-between gap-3 border-b border-[color:var(--app-border)] p-4">
                    <h2 className="text-sm font-semibold">{label('title')} {unread > 0 && <span className="text-xs text-[color:var(--app-muted-foreground)]">({unread})</span>}</h2>
                    <button type="button" className={actionCls} disabled={busy || !unread} onClick={() => void markRead()}>{label('readAll')}</button>
                </div>
                {error && <div role="alert" className="p-3 text-sm text-red-700 dark:text-red-400">{label('error')} <button type="button" className={actionCls} onClick={() => void refresh()}>{label('retry')}</button></div>}
                {!feed && loading && <p role="status" className="p-6 text-sm">{label('loading')}</p>}
                {feed?.items.length === 0 && <p className="p-8 text-center text-sm text-[color:var(--app-muted-foreground)]">{label('empty')}</p>}
                <ul className="max-h-[min(60vh,28rem)] overflow-y-auto overscroll-contain divide-y divide-[color:var(--app-border)]" aria-busy={loading}>
                    {feed?.items.map((notice) => <li key={notice.id} className={`p-4 ${notice.read ? '' : 'bg-[color:var(--app-surface-muted)]'}`}>
                        <div className="flex items-start gap-2">
                            {!notice.read && <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-[color:var(--color-primary)]" aria-label={label('unread')} />}
                            <div className="min-w-0 flex-1">
                                <p className="break-words text-sm font-semibold">{notice.title || label('title')}</p>
                                <p className="mt-1 whitespace-pre-line break-words text-sm text-[color:var(--app-muted-foreground)]">{notice.message}</p>
                                <LocalizedDateDisplay value={notice.created_at} withTime className="mt-2 block text-xs text-[color:var(--app-muted-foreground)]" />
                                <div className="mt-2 flex gap-2">
                                    {!notice.read && <button type="button" className={actionCls} disabled={busy} onClick={() => void markRead(notice)}>{label('read')}</button>}
                                    {notice.url && <button type="button" className={actionCls} disabled={busy} onClick={() => void markRead(notice, true)}>{label('open')}</button>}
                                </div>
                            </div>
                        </div>
                    </li>)}
                </ul>
                {props.has_employee_record && (
                    <div className="border-t border-[color:var(--app-border)] p-2 text-center">
                        <Link href={route('employee.notifications')} className={actionCls}>{label('viewAll')}</Link>
                    </div>
                )}
                {feed && feed.last_page > 1 && <div className="flex items-center justify-between border-t border-[color:var(--app-border)] p-3">
                    <button type="button" className={actionCls} disabled={loading || busy || page <= 1} onClick={() => setPage(page - 1)}>{label('previous')}</button>
                    <span className="text-xs">{feed.page} / {feed.last_page}</span>
                    <button type="button" className={actionCls} disabled={loading || busy || page >= feed.last_page} onClick={() => setPage(page + 1)}>{label('next')}</button>
                </div>}
            </PopoverPanel>
        </Popover>
    );
}
