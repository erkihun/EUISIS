import { useCallback, useEffect, useLayoutEffect, useRef, useSyncExternalStore } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react';
import { Alert, Button, badgeClassName, cx, type Tone } from '@/Components/ui';
import { ChevronDown, MaximizeIcon, MinimizeIcon, PlusIcon, XMarkIcon } from '@/Components/NavIcons';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import type { AssistantError, AssistantFeedback, AssistantStatus } from '@/types/assistant';
import AssistantComposer from './AssistantComposer';
import AssistantMessage from './AssistantMessage';
import { useAssistantPageRecord } from './AssistantPageContext';
import type { BlockContext } from './AssistantBlocks';
import { pagePayload, useAssistant, visibleText } from './useAssistant';

interface Props {
    open: boolean;
    onClose: () => void;
    onMinimize: () => void;
}

const STATUS_TONE: Record<AssistantStatus, Tone> = { ready: 'success', limited: 'warning', unavailable: 'neutral' };
const DEFAULT_MAX_CHARS = 2000;
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

function ErrorLine({ error, onDismiss }: { error: AssistantError; onDismiss?: () => void }) {
    const { t } = useLocale();
    const text = error.code === 'validation' && error.message ? error.message : t(`assistant.errors.${error.code}`);

    return (
        <Alert tone={error.code === 'session_expired' || error.code === 'network' ? 'warning' : 'danger'} className="p-3 text-xs">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span>{text}</span>
                {error.code === 'session_expired' ? (
                    <Button variant="outline" size="xs" onClick={() => window.location.reload()}>{t('assistant.errors.reload')}</Button>
                ) : onDismiss ? (
                    <Button variant="ghost" size="xs" onClick={onDismiss}>{t('assistant.errors.dismiss')}</Button>
                ) : null}
            </div>
        </Alert>
    );
}

export default function AssistantPanel({ open, onClose, onMinimize }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const { url: pageUrl } = usePage();
    const mobile = useIsMobile();
    const record = useAssistantPageRecord();
    const { state, loadBootstrap, send, feedback, createTicket, newConversation, setDraft, setExpanded, toggleActions, dismissError } = useAssistant();

    const composerRef = useRef<HTMLTextAreaElement>(null);
    const listRef = useRef<HTMLDivElement>(null);
    const nearBottomRef = useRef(true);
    const panelRef = useRef<HTMLElement>(null);

    // Page context: on open, and again whenever the page changes while open.
    useEffect(() => {
        if (!open) return;
        void loadBootstrap(record, state.bootstrap ? 'navigate' : 'open');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, pageUrl, record?.type, record?.id]);

    useEffect(() => {
        if (!open) return;
        return router.on('navigate', () => {
            void loadBootstrap(record, 'navigate');
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, record?.type, record?.id]);

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
    }, [state.items.length, state.pending, open]);

    const bootstrap = state.bootstrap;
    const maxChars = bootstrap?.limits?.max_message_chars && bootstrap.limits.max_message_chars > 0 ? bootstrap.limits.max_message_chars : DEFAULT_MAX_CHARS;
    const status: AssistantStatus = bootstrap?.status ?? 'ready';
    const quickActions = bootstrap?.quick_actions ?? [];
    const hasMessages = state.items.some((item) => item.kind === 'message');
    const sessionExpired = state.bootstrapError?.code === 'session_expired' || state.error?.code === 'session_expired';
    const composerDisabled = state.pending || state.loading || sessionExpired || !bootstrap;

    const onSend = useCallback(() => {
        void send(state.draft, record);
    }, [send, state.draft, record]);

    const onQuickAction = useCallback((key: string, label: string) => {
        void send(label, record, key);
    }, [send, record]);

    const onFeedback = useCallback((messageId: string, value: AssistantFeedback) => {
        void feedback(messageId, value);
    }, [feedback]);

    const onNewConversation = useCallback(async () => {
        if (state.pending) return;
        if (state.draft.trim() !== '') {
            const result = await confirm({
                title: t('assistant.confirmDiscard'),
                confirmLabel: t('assistant.newConversation'),
                cancelLabel: t('assistant.cancel'),
            });
            if (!result.confirmed) return;
        }
        await newConversation();
        composerRef.current?.focus();
    }, [state.pending, state.draft, confirm, t, newConversation]);

    const page = pagePayload(record);
    const blockContext: Omit<BlockContext, 'messageId'> = {
        pending: state.pending,
        usedOffers: state.usedOffers,
        page: { route: page.route, path: page.path },
        onCreateTicket: createTicket,
    };

    const lastAssistant = [...state.items].reverse().find((item) => item.kind === 'message' && item.message.role === 'assistant');
    const liveText = lastAssistant && lastAssistant.kind === 'message' ? visibleText(lastAssistant.message) : '';

    const header = (
        <div className="flex shrink-0 items-center gap-2 border-b border-(--app-border) bg-(--app-surface) px-3 py-2">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <span className="truncate text-sm font-semibold text-(--app-foreground)">{t('assistant.title')}</span>
                {bootstrap && (
                    <span className={badgeClassName(STATUS_TONE[status], 'shrink-0 gap-1.5')}>
                        {status === 'ready' && <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-emerald-500" />}
                        {t(`assistant.status.${status}`)}
                    </span>
                )}
            </div>
            <Button variant="ghost" size="icon" className="h-9 w-9" onClick={() => { void onNewConversation(); }} aria-label={t('assistant.newConversation')} title={t('assistant.newConversation')} disabled={state.pending} icon={<PlusIcon className="h-4 w-4" />} />
            {!mobile && (
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-9 w-9"
                    onClick={() => setExpanded(!state.expanded)}
                    aria-label={state.expanded ? t('assistant.compact') : t('assistant.expand')}
                    title={state.expanded ? t('assistant.compact') : t('assistant.expand')}
                    aria-pressed={state.expanded}
                    icon={state.expanded ? <MinimizeIcon className="h-4 w-4" /> : <MaximizeIcon className="h-4 w-4" />}
                />
            )}
            <Button variant="ghost" size="icon" className="h-9 w-9" onClick={onMinimize} aria-label={t('assistant.minimize')} title={t('assistant.minimize')} icon={<ChevronDown className="h-4 w-4" />} />
            <Button variant="ghost" size="icon" className="h-9 w-9" onClick={onClose} aria-label={t('assistant.close')} title={t('assistant.close')} icon={<XMarkIcon className="h-4 w-4" />} />
        </div>
    );

    const body = (
        <>
            {header}
            <div ref={listRef} onScroll={onListScroll} role="log" aria-label={t('assistant.conversation')} className="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain px-3 py-3">
                {status === 'limited' && (
                    <Alert tone="warning" className="p-2.5 text-xs">{t('assistant.status.limitedNotice')}</Alert>
                )}
                {state.bootstrapError && <ErrorLine error={state.bootstrapError} />}
                {state.loading && !bootstrap && (
                    <p className="text-xs text-(--app-muted-foreground)">{t('assistant.loading')}</p>
                )}
                {bootstrap && !hasMessages && (
                    <div className="flex justify-start">
                        <div className="max-w-[92%] rounded-card border border-(--app-border) bg-(--app-surface-muted) px-3 py-2 text-sm text-(--app-foreground)">
                            <p>{bootstrap.welcome || t('assistant.welcomeFallback')}</p>
                            {bootstrap.page?.module_label && (
                                <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('assistant.nowOn')}: {bootstrap.page.module_label}</p>
                            )}
                        </div>
                    </div>
                )}
                {state.items.map((item) =>
                    item.kind === 'system' ? (
                        <p key={item.id} className="text-center text-[11px] text-(--app-muted-foreground)">
                            {item.text ?? <>{t('assistant.nowOn')}: {item.moduleLabel}</>}
                        </p>
                    ) : (
                        <AssistantMessage key={item.message.id} message={item.message} context={{ ...blockContext, messageId: item.message.id }} onFeedback={onFeedback} />
                    ),
                )}
                {state.pending && state.thinking && (
                    <p className="flex items-center gap-2 text-xs text-(--app-muted-foreground)" role="status">
                        <span aria-hidden="true" className="h-3 w-3 animate-spin rounded-full border-2 border-current border-r-transparent" />
                        {t(`assistant.thinking.${state.thinking}`)}
                    </p>
                )}
                {state.error && <ErrorLine error={state.error} onDismiss={dismissError} />}
                {bootstrap?.disclaimer && !hasMessages && (
                    <p className="text-[11px] text-(--app-muted-foreground)">{bootstrap.disclaimer}</p>
                )}
            </div>
            <div className="sr-only" aria-live="polite" aria-atomic="true">{liveText}</div>
            {quickActions.length > 0 && (
                <div className="shrink-0 border-t border-(--app-border) bg-(--app-surface) px-3 py-2">
                    {hasMessages && (
                        <Button variant="ghost" size="xs" onClick={toggleActions} aria-expanded={state.showActions} className="mb-1">
                            {t('assistant.actions.toggle')}
                        </Button>
                    )}
                    {state.showActions && (
                        <div className="flex flex-wrap gap-1.5" role="group" aria-label={t('assistant.actions.label')}>
                            {quickActions.map((action) => (
                                <Button key={action.key} variant="outline" size="sm" disabled={composerDisabled} onClick={() => onQuickAction(action.key, action.label)}>
                                    {action.label}
                                </Button>
                            ))}
                        </div>
                    )}
                </div>
            )}
            <AssistantComposer
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
                        <DialogTitle className="sr-only">{t('assistant.title')}</DialogTitle>
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
            aria-label={t('assistant.title')}
            className="fixed z-40 flex flex-col overflow-hidden rounded-panel border border-(--app-border) bg-(--app-surface) text-(--app-foreground) shadow-2xl"
            style={{
                right: 'calc(1rem + env(safe-area-inset-right))',
                bottom: 'calc(4.75rem + env(safe-area-inset-bottom))',
                width: state.expanded ? 'min(720px, calc(100vw - 2rem))' : 'min(400px, calc(100vw - 2rem))',
                height: state.expanded ? '90vh' : '600px',
                maxHeight: state.expanded ? 'calc(100vh - 6rem)' : '80vh',
            }}
        >
            {body}
        </section>
    );
}
