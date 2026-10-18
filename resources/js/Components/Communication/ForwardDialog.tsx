import { useEffect, useState, type JSX } from 'react';
import Modal from '@/Components/Modal';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import { communicationApi, firstError } from '@/lib/communication/api';
import Avatar from '@/Components/Communication/Avatar';
import { SearchIcon } from '@/Components/NavIcons';
import type { ConversationSummary, MessageItem } from '@/types/communication';

const MAX_TARGETS = 5;

/** Forward one message to up to five conversations the person can post in. */
export default function ForwardDialog({ message, onClose }: { message: MessageItem | null; onClose: () => void }): JSX.Element {
    const { t } = useLocale();
    const [q, setQ] = useState('');
    const [items, setItems] = useState<ConversationSummary[] | null>(null);
    const [chosen, setChosen] = useState<string[]>([]);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!message) return;
        setChosen([]);
        setError(null);
        const timer = window.setTimeout(() => {
            communicationApi.conversations({ q: q.trim() || null }).then((page) => setItems(page.data)).catch(() => setItems([]));
        }, q ? 250 : 0);
        return () => window.clearTimeout(timer);
    }, [message, q]);

    const toggle = (id: string) => setChosen((current) => current.includes(id)
        ? current.filter((value) => value !== id)
        : current.length < MAX_TARGETS ? [...current, id] : current);

    const send = async () => {
        if (!message || chosen.length === 0) return;
        setSending(true);
        setError(null);
        try {
            await communicationApi.forward(message.id, chosen);
            toast.success(t('communication.forward.done'));
            onClose();
        } catch (reason) {
            setError(firstError(reason) ?? t('communication.errors.generic'));
        } finally {
            setSending(false);
        }
    };

    return (
        <Modal show={message !== null} onClose={onClose} maxWidth="md">
            <div className="flex max-h-[80vh] flex-col">
                <div className="border-b border-[color:var(--app-border)] px-5 py-4">
                    <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{t('communication.forward.title')}</h2>
                    <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{t('communication.forward.help')}</p>
                    <label className="relative mt-3 block">
                        <span className="sr-only">{t('communication.list.search')}</span>
                        <SearchIcon className="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                        <input
                            type="search"
                            value={q}
                            onChange={(event) => setQ(event.target.value)}
                            placeholder={t('communication.list.search')}
                            className="h-9 w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] ps-8 pe-2 text-sm text-[color:var(--app-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/15"
                        />
                    </label>
                </div>
                <ul className="comm-scroll min-h-[12rem] flex-1 overflow-y-auto px-2 py-2">
                    {items?.filter((item) => item.id !== message?.conversation_id).map((item) => {
                        const selected = chosen.includes(item.id);
                        return (
                            <li key={item.id}>
                                <label className={`flex cursor-pointer items-center gap-3 rounded-control px-2 py-2 ${selected ? 'bg-[color:color-mix(in_srgb,var(--color-primary)_12%,var(--app-surface))]' : 'hover:bg-[color:var(--app-surface-muted)]'}`}>
                                    <input
                                        type="checkbox"
                                        checked={selected}
                                        disabled={!selected && chosen.length >= MAX_TARGETS}
                                        onChange={() => toggle(item.id)}
                                        className="h-4 w-4 rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)]"
                                    />
                                    <Avatar name={item.title} kind={item.type} userId={item.other_user?.id} hasPhoto={item.has_avatar} size="sm" />
                                    <span className="min-w-0 flex-1 truncate text-sm text-[color:var(--app-foreground)]">{item.title}</span>
                                </label>
                            </li>
                        );
                    })}
                </ul>
                {error && <p role="alert" className="px-5 pb-2 text-xs text-[color:var(--color-danger,#b42318)]">{error}</p>}
                <div className="flex items-center justify-end gap-2 border-t border-[color:var(--app-border)] px-5 py-3">
                    <button type="button" onClick={onClose} className="h-9 rounded-control px-3 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]">
                        {t('common.cancel')}
                    </button>
                    <button
                        type="button"
                        disabled={chosen.length === 0 || sending}
                        onClick={() => void send()}
                        className="h-9 rounded-control bg-[color:var(--color-primary)] px-4 text-sm font-medium text-white hover:opacity-90 disabled:opacity-40"
                    >
                        {t('communication.forward.send')}{chosen.length > 0 ? ` (${chosen.length})` : ''}
                    </button>
                </div>
            </div>
        </Modal>
    );
}
