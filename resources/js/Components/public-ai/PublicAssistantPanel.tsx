import { useCallback, useEffect, useLayoutEffect, useRef, useSyncExternalStore } from 'react';
import { usePage } from '@inertiajs/react';
import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react';
import { Button, badgeClassName, cx, type Tone } from '@/Components/ui';
import { RestartIcon, XMarkIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import type { PublicAssistantStatus } from '@/types/publicAssistant';
import PublicAssistantComposer from './PublicAssistantComposer';
import PublicAssistantFallback, { PublicAssistantErrorLine } from './PublicAssistantFallback';
import PublicAssistantMessage from './PublicAssistantMessage';
import PublicAssistantQuickActions from './PublicAssistantQuickActions';
import { PANEL_BOTTOM, TRIGGER_RIGHT } from './shared';
import { usePublicAssistant, visibleText } from './usePublicAssistant';

interface Props {
    open: boolean;
    onClose: () => void;
}

const STATUS_TONE: Record<PublicAssistantStatus, Tone> = { ready: 'success', limited: 'warning' };
const DEFAULT_MAX_CHARS = 1000;
const NEAR_BOTTOM_PX = 80;

const mobileQuery = '(max-width: 639px)';
function subscribeMobile(listener: () => void): () => void {
    if (typeof window === 'undefined' || !window.matchMedia) return () => undefined;
    const media = window.matchMedia(mobileQuery);
    media.addEventListener('change', listener);
    return () => media.removeEventListener('change', listener);
}
function isMobileNow(): boolean {
    return typeof window !== 'undefined' && Boolean(window.matchMedia?.(mobileQuery).matches);
}
function useIsMobile(): boolean {
    return useSyncExternalStore(subscribeMobile, isMobileNow, () => false);
}

/**
 * Addis One Help: a compact non-modal panel above the trigger on wider
 * screens, a full-height sheet (modal, scroll locked, focus trapped) on a
 * phone. Loaded on the first open only (see PublicAssistant).
 */
export default function PublicAssistantPanel({ open, onClose }: Props) {
    const { t } = useLocale();
    const { url: pageUrl } = usePage();
    const mobile = useIsMobile();
    const { state, loadBootstrap, send, retry, reset, setDraft, toggleActions, dismissError } = usePublicAssistant();

    const composerRef = useRef<HTMLTextAreaElement>(null);
    const listRef = useRef<HTMLDivElement>(null);
    const nearBottomRef = useRef(true);
    const panelRef = useRef<HTMLElement>(null);

    // Page context: on open, and again when the visitor navigates while the panel is open.
    useEffect(() => {
        if (!open) return;
        void loadBootstrap();
    }, [open, pageUrl, loadBootstrap]);

    // Focus the composer when the panel opens (the mobile dialog does this through initialFocus).
    useEffect(() => {
        if (!open || mobile) return;
        const timer = window.setTimeout(() => composerRef.current?.focus(), 30);
        return () => window.clearTimeout(timer);
    }, [open, mobile]);

    // Escape closes the desktop panel (Headless UI handles it on mobile).
    useEffect(() => {
        if (!open || mobile) return;
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && panelRef.current?.contains(document.activeElement)) {
                event.preventDefault();
                onClose();
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, mobile, onClose]);

    // Auto-scroll only when the reader was already at (or near) the bottom.
    const onListScroll = useCallback(() => {
        const node = listRef.current;
        if (!node) return;
        nearBottomRef.current = node.scrollHeight - node.scrollTop - node.clientHeight <= NEAR_BOTTOM_PX;
    }, []);

    useLayoutEffect(() => {
        const node = listRef.current;
        if (!node || !nearBottomRef.current) return;
        node.scrollTop = node.scrollHeight;
    }, [state.messages.length, state.pending, state.error, open]);

    const bootstrap = state.bootstrap;
    const maxChars = bootstrap?.limits?.max_message_chars && bootstrap.limits.max_message_chars > 0 ? bootstrap.limits.max_message_chars : DEFAULT_MAX_CHARS;
    const status: PublicAssistantStatus = bootstrap?.status === 'limited' ? 'limited' : 'ready';
    const quickActions = bootstrap?.quick_actions ?? [];
    const hasMessages = state.messages.length > 0;
    const sessionExpired = state.bootstrapError?.code === 'session_expired' || state.error?.code === 'session_expired';
    const awaitingRetry = state.pendingRequest !== null && !state.pending;
    const composerDisabled = state.pending || state.loading || sessionExpired || awaitingRetry || !bootstrap;

    const onSend = useCallback(() => {
        void send(state.draft);
    }, [send, state.draft]);

    const onQuickAction = useCallback((key: string, label: string) => {
        void send(label, key);
    }, [send]);

    const onStartOver = useCallback(async () => {
        if (state.pending) return;
        await reset();
        composerRef.current?.focus();
    }, [state.pending, reset]);

    const lastReply = [...state.messages].reverse().find((message) => message.role === 'assistant');
    // Screen readers get the plain words: no bold markers, no list prefixes.
    const liveText = lastReply ? visibleText(lastReply).replace(/\*\*/g, '') : '';

    const header = (
        <div className="flex shrink-0 items-center gap-2 border-b border-(--app-border) bg-(--app-surface) px-3 py-2">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <span className="truncate text-sm font-semibold text-(--app-foreground)">{t('publicAssistant.title')}</span>
                {bootstrap && (
                    <span className={badgeClassName(STATUS_TONE[status], 'shrink-0 gap-1.5')}>
                        {status === 'ready' && <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-emerald-500" />}
                        {t(`publicAssistant.status.${status}`)}
                    </span>
                )}
            </div>
            <Button
                variant="ghost"
                size="icon"
                className="h-10 w-10"
                onClick={() => { void onStartOver(); }}
                aria-label={t('publicAssistant.startOver')}
                title={t('publicAssistant.startOver')}
                disabled={state.pending || !bootstrap}
                icon={<RestartIcon className="h-4 w-4" />}
            />
            <Button variant="ghost" size="icon" className="h-10 w-10" onClick={onClose} aria-label={t('publicAssistant.close')} title={t('publicAssistant.close')} icon={<XMarkIcon className="h-4 w-4" />} />
        </div>
    );

    const body = (
        <>
            {header}
            <div ref={listRef} onScroll={onListScroll} role="log" aria-label={t('publicAssistant.conversation')} className="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain px-3 py-3">
                {bootstrap && status === 'limited' && <PublicAssistantFallback mode="limited" />}
                {state.bootstrapError && (
                    <PublicAssistantFallback mode="failed" error={state.bootstrapError} onRetry={() => { void loadBootstrap(); }} />
                )}
                {state.loading && !bootstrap && (
                    <p className="text-xs text-(--app-muted-foreground)" role="status">{t('publicAssistant.loading')}</p>
                )}
                {bootstrap && !hasMessages && (
                    <div className="flex justify-start">
                        <div className="max-w-[92%] rounded-card border border-(--app-border) bg-(--app-surface-muted) px-3 py-2 text-sm text-(--app-foreground)">
                            <p>{bootstrap.welcome || t('publicAssistant.welcomeFallback')}</p>
                            {bootstrap.page?.label && (
                                <p className="mt-1 text-xs text-(--app-muted-foreground)">
                                    {t('publicAssistant.youAreOn')}: {bootstrap.page.label}
                                    {bootstrap.page.target?.name ? ` · ${bootstrap.page.target.name}` : ''}
                                </p>
                            )}
                        </div>
                    </div>
                )}
                {state.messages.map((message) => <PublicAssistantMessage key={message.id} message={message} />)}
                {state.pending && (
                    <p className="flex items-center gap-2 text-xs text-(--app-muted-foreground)" role="status">
                        <span aria-hidden="true" className="h-3 w-3 rounded-full border-2 border-current border-r-transparent motion-safe:animate-spin" />
                        {t('publicAssistant.sending')}
                    </p>
                )}
                {state.error && (
                    <PublicAssistantErrorLine
                        error={state.error}
                        onRetry={awaitingRetry ? () => { void retry(); } : undefined}
                        onDismiss={dismissError}
                    />
                )}
                {bootstrap?.disclaimer && !hasMessages && (
                    <p className="text-[11px] text-(--app-muted-foreground)">{bootstrap.disclaimer}</p>
                )}
            </div>
            <div className="sr-only" aria-live="polite" aria-atomic="true">{liveText}</div>
            <PublicAssistantQuickActions
                actions={quickActions}
                show={state.showActions}
                hasMessages={hasMessages}
                disabled={composerDisabled}
                onToggle={toggleActions}
                onSelect={onQuickAction}
            />
            <PublicAssistantComposer
                ref={composerRef}
                value={state.draft}
                onChange={setDraft}
                onSend={onSend}
                disabled={composerDisabled}
                maxChars={maxChars}
                className={cx('shrink-0', mobile && 'pb-[env(safe-area-inset-bottom)]')}
            />
        </>
    );

    if (!open) return null;

    if (mobile) {
        return (
            <Dialog open={open} onClose={onClose} initialFocus={composerRef} className="relative z-50">
                <DialogBackdrop className="fixed inset-0 bg-slate-950/40" />
                <div className="fixed inset-0 flex items-end">
                    <DialogPanel
                        className="flex w-full flex-col overflow-hidden bg-(--app-surface) text-(--app-foreground)"
                        style={{ height: '100dvh', paddingTop: 'env(safe-area-inset-top)' }}
                    >
                        <DialogTitle className="sr-only">{t('publicAssistant.title')}</DialogTitle>
                        {body}
                    </DialogPanel>
                </div>
            </Dialog>
        );
    }

    return (
        <section
            ref={panelRef}
            role="dialog"
            aria-label={t('publicAssistant.title')}
            className="fixed z-40 flex flex-col overflow-hidden rounded-panel border border-(--app-border) bg-(--app-surface) text-(--app-foreground) shadow-2xl"
            style={{
                right: TRIGGER_RIGHT,
                bottom: PANEL_BOTTOM,
                width: 'min(380px, calc(100vw - 2rem))',
                height: '560px',
                maxHeight: '75vh',
            }}
        >
            {body}
        </section>
    );
}
