import { memo, useEffect, useRef, useState, type JSX } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { communicationApi } from '@/lib/communication/api';
import { clock, fileSize, tr } from '@/lib/communication/format';
import MessageText from '@/Components/Communication/MessageText';
import EmojiPicker from '@/Components/Communication/EmojiPicker';
import {
    CopyIcon, DownloadIcon, ForwardIcon, MenuDotsIcon, PaperclipIcon, PenIcon, PinIcon, ReplyIcon, SmileIcon, TrashIcon,
} from '@/Components/NavIcons';
import type { Attachment, MessageItem } from '@/types/communication';

export type BubbleAbilities = { reply: boolean; react: boolean; edit: boolean; delete: boolean; pin: boolean; forward: boolean };

type Props = {
    message: MessageItem;
    own: boolean;
    continued: boolean;
    showSender: boolean;
    currentUserId: number;
    pinned: boolean;
    reactions: readonly string[];
    can: BubbleAbilities;
    status: string | null;
    highlighted: boolean;
    onReply: (message: MessageItem) => void;
    onEdit: (message: MessageItem) => void;
    onDelete: (message: MessageItem) => void;
    onReact: (message: MessageItem, emoji: string, add: boolean) => void;
    onForward: (message: MessageItem) => void;
    onPin: (message: MessageItem, add: boolean) => void;
    onJump: (messageId: string) => void;
};

function MessageBubble(props: Props): JSX.Element {
    const { message, own, continued, showSender, currentUserId, pinned, can, status, highlighted } = props;
    const { t, locale } = useLocale();
    const [picker, setPicker] = useState(false);
    const [menu, setMenu] = useState(false);
    const [copied, setCopied] = useState(false);

    if (message.type === 'SYSTEM') {
        return (
            <li id={`m-${message.id}`} className="my-3 flex justify-center px-4">
                <span className="rounded-full bg-[color:var(--app-surface-muted)] px-3 py-1 text-center text-xs text-[color:var(--app-muted-foreground)]">
                    {systemText(message, t)}
                </span>
            </li>
        );
    }

    const time = clock(message.created_at, locale);
    const actionable = !message.deleted;
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(message.body ?? '');
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            /* clipboard refused: nothing to do */
        }
        setMenu(false);
    };

    // A wash of the brand colour, not the colour itself: the primary is set at
    // runtime and white text on it is not legible for every choice.
    const surface = own
        ? 'bg-[color:color-mix(in_srgb,var(--color-primary)_14%,var(--app-surface))] dark:bg-[color:color-mix(in_srgb,var(--color-primary)_30%,var(--app-surface))] text-[color:var(--app-foreground)] border border-[color:color-mix(in_srgb,var(--color-primary)_22%,var(--app-surface))] dark:border-[color:color-mix(in_srgb,var(--color-primary)_40%,var(--app-surface))]'
        : 'bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] border border-[color:var(--app-border)]';

    return (
        <li
            id={`m-${message.id}`}
            className={`group relative flex px-3 sm:px-5 ${own ? 'justify-end' : 'justify-start'} ${continued ? 'mt-0.5' : 'mt-3'} ${highlighted ? 'comm-flash' : ''}`}
        >
            <div className={`relative flex max-w-[min(36rem,85%)] flex-col ${own ? 'items-end' : 'items-start'}`}>
                {showSender && !own && (
                    <span className="mb-0.5 px-1 text-xs font-semibold text-[color:var(--app-muted-foreground)]">{message.sender?.name}</span>
                )}

                <div className={`relative rounded-2xl px-3.5 py-2 text-sm leading-relaxed shadow-[0_1px_0_rgba(0,0,0,0.02)] ${surface} ${own ? (continued ? 'rounded-se-md' : 'rounded-se-sm') : (continued ? 'rounded-ss-md' : 'rounded-ss-sm')}`}>
                    {message.forwarded && !message.deleted && (
                        <span className={`mb-1 flex items-center gap-1 text-[11px] italic ${own ? 'text-[color:var(--app-muted-foreground)]' : 'text-[color:var(--app-muted-foreground)]'}`}>
                            <ForwardIcon className="h-3 w-3" aria-hidden="true" /> {t('communication.chat.forwarded')}
                        </span>
                    )}

                    {message.parent && !message.deleted && (
                        <button
                            type="button"
                            onClick={() => message.parent && props.onJump(message.parent.id)}
                            className={`mb-1.5 block w-full rounded-lg border px-2 py-1 text-start text-xs ${own ? 'border-[color:var(--app-border)] bg-[color:var(--app-surface)]/70 text-[color:var(--app-foreground)]' : 'border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]'}`}
                        >
                            <span className="block font-semibold">{message.parent.sender_name}</span>
                            <span className="line-clamp-2">
                                {message.parent.deleted ? t('communication.list.deleted') : (message.parent.excerpt || t('communication.list.attachment'))}
                            </span>
                        </button>
                    )}

                    {message.deleted ? (
                        <span className={`italic ${own ? 'text-[color:var(--app-muted-foreground)]' : 'text-[color:var(--app-muted-foreground)]'}`}>{t('communication.chat.deleted')}</span>
                    ) : (
                        <>
                            {message.body && <MessageText body={message.body} mentions={message.mentions} currentUserId={currentUserId} />}
                            {message.attachments.length > 0 && (
                                <div className={`flex flex-col gap-1.5 ${message.body ? 'mt-2' : ''}`}>
                                    {message.attachments.map((file) => <AttachmentView key={file.id} file={file} own={own} />)}
                                </div>
                            )}
                        </>
                    )}

                    <span className={`mt-0.5 flex items-center justify-end gap-1.5 text-[11px] tabular-nums ${own ? 'text-[color:var(--app-muted-foreground)]' : 'text-[color:var(--app-muted-foreground)]'}`}>
                        {pinned && <PinIcon className="h-3 w-3" aria-label={t('communication.actions.pin')} />}
                        {message.edited_at && !message.deleted && <span>{t('communication.chat.edited')}</span>}
                        <time dateTime={message.created_at ?? undefined}>{time}</time>
                    </span>
                </div>

                {message.reactions.length > 0 && !message.deleted && (
                    <div className={`-mt-1 flex flex-wrap gap-1 px-1 ${own ? 'justify-end' : ''}`}>
                        {message.reactions.map((reaction) => {
                            const mine = reaction.user_ids.includes(currentUserId);
                            return (
                                <button
                                    key={reaction.emoji}
                                    type="button"
                                    disabled={!can.react}
                                    aria-pressed={mine}
                                    aria-label={tr(t('communication.actions.reactedBy'), { emoji: reaction.emoji, n: reaction.count })}
                                    onClick={() => props.onReact(message, reaction.emoji, !mine)}
                                    className={`inline-flex h-6 items-center gap-1 rounded-full border px-1.5 text-xs tabular-nums transition-colors ${mine ? 'border-[color:var(--color-primary)] bg-[color:color-mix(in_srgb,var(--color-primary)_12%,var(--app-surface))] text-[color:var(--app-foreground)]' : 'border-[color:var(--app-border)] bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] hover:border-[color:var(--app-border-strong)]'}`}
                                >
                                    <span aria-hidden="true">{reaction.emoji}</span>
                                    {reaction.count}
                                </button>
                            );
                        })}
                    </div>
                )}

                {status && <span className="mt-0.5 px-1 text-[11px] text-[color:var(--app-muted-foreground)]">{status}</span>}

                {actionable && (
                    <div
                        className={`absolute top-0 z-10 flex -translate-y-1/2 items-center rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-0.5 opacity-0 shadow-sm transition-opacity focus-within:opacity-100 group-hover:opacity-100 ${picker || menu ? 'opacity-100' : ''} ${own ? 'start-0' : 'end-0'}`}
                    >
                        {can.react && (
                            <div className="relative">
                                <ToolbarButton label={t('communication.actions.react')} onClick={() => setPicker((v) => !v)} expanded={picker}>
                                    <SmileIcon className="h-4 w-4" />
                                </ToolbarButton>
                                {picker && (
                                    <EmojiPicker
                                        emoji={props.reactions}
                                        columns={props.reactions.length}
                                        label={t('communication.actions.react')}
                                        placement="below"
                                        align={own ? 'start' : 'end'}
                                        onClose={() => setPicker(false)}
                                        onPick={(emoji) => {
                                            const mine = message.reactions.find((r) => r.emoji === emoji)?.user_ids.includes(currentUserId) ?? false;
                                            props.onReact(message, emoji, !mine);
                                            setPicker(false);
                                        }}
                                    />
                                )}
                            </div>
                        )}
                        {can.reply && (
                            <ToolbarButton label={t('communication.actions.reply')} onClick={() => props.onReply(message)}>
                                <ReplyIcon className="h-4 w-4" />
                            </ToolbarButton>
                        )}
                        <div className="relative">
                            <ToolbarButton label={t('communication.actions.more')} onClick={() => setMenu((v) => !v)} expanded={menu}>
                                <MenuDotsIcon className="h-4 w-4" />
                            </ToolbarButton>
                            {menu && (
                                <ActionMenu onClose={() => setMenu(false)} align={own ? 'start' : 'end'}>
                                    {message.body && (
                                        <MenuItem onClick={copy}><CopyIcon className="h-4 w-4" />{copied ? t('communication.actions.copied') : t('communication.actions.copy')}</MenuItem>
                                    )}
                                    {can.forward && (
                                        <MenuItem onClick={() => { setMenu(false); props.onForward(message); }}><ForwardIcon className="h-4 w-4" />{t('communication.actions.forward')}</MenuItem>
                                    )}
                                    {can.pin && (
                                        <MenuItem onClick={() => { setMenu(false); props.onPin(message, !pinned); }}><PinIcon className="h-4 w-4" />{pinned ? t('communication.actions.unpin') : t('communication.actions.pin')}</MenuItem>
                                    )}
                                    {can.edit && (
                                        <MenuItem onClick={() => { setMenu(false); props.onEdit(message); }}><PenIcon className="h-4 w-4" />{t('communication.actions.edit')}</MenuItem>
                                    )}
                                    {can.delete && (
                                        <MenuItem danger onClick={() => { setMenu(false); props.onDelete(message); }}><TrashIcon className="h-4 w-4" />{t('communication.actions.delete')}</MenuItem>
                                    )}
                                </ActionMenu>
                            )}
                        </div>
                    </div>
                )}
            </div>

        </li>
    );
}

export default memo(MessageBubble);

function ToolbarButton({ label, onClick, expanded, children }: { label: string; onClick: () => void; expanded?: boolean; children: React.ReactNode }): JSX.Element {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            aria-expanded={expanded}
            onClick={onClick}
            className="flex h-7 w-7 items-center justify-center rounded-md text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[color:var(--color-primary)]"
        >
            {children}
        </button>
    );
}

export function ActionMenu({ onClose, align = 'end', children }: { onClose: () => void; align?: 'start' | 'end'; children: React.ReactNode }): JSX.Element {
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => {
        ref.current?.querySelector<HTMLButtonElement>('button')?.focus();
        const outside = (event: MouseEvent) => {
            if (ref.current && !ref.current.contains(event.target as Node)) onClose();
        };
        document.addEventListener('mousedown', outside);
        return () => document.removeEventListener('mousedown', outside);
    }, [onClose]);

    const keys = (event: React.KeyboardEvent) => {
        const items = Array.from(ref.current?.querySelectorAll<HTMLButtonElement>('button') ?? []);
        const index = items.indexOf(document.activeElement as HTMLButtonElement);
        if (event.key === 'Escape') { event.stopPropagation(); onClose(); }
        if (event.key === 'ArrowDown') { event.preventDefault(); items[(index + 1) % items.length]?.focus(); }
        if (event.key === 'ArrowUp') { event.preventDefault(); items[(index - 1 + items.length) % items.length]?.focus(); }
    };

    return (
        <div
            ref={ref}
            role="menu"
            onKeyDown={keys}
            className={`absolute top-full z-30 mt-1 min-w-[11rem] rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-surface)] py-1 shadow-lg ${align === 'end' ? 'end-0' : 'start-0'}`}
        >
            {children}
        </div>
    );
}

export function MenuItem({ onClick, danger = false, children }: { onClick: () => void; danger?: boolean; children: React.ReactNode }): JSX.Element {
    return (
        <button
            type="button"
            role="menuitem"
            onClick={onClick}
            className={`flex w-full items-center gap-2.5 px-3 py-1.5 text-start text-sm focus:bg-[color:var(--app-surface-muted)] focus:outline-none hover:bg-[color:var(--app-surface-muted)] ${danger ? 'text-[color:var(--color-danger,#b42318)]' : 'text-[color:var(--app-foreground)]'}`}
        >
            {children}
        </button>
    );
}

function AttachmentView({ file, own }: { file: Attachment; own: boolean }): JSX.Element {
    const { t } = useLocale();

    if (file.is_image && file.downloadable) {
        return (
            <a href={communicationApi.downloadUrl(file.id, true)} target="_blank" rel="noopener" className="block overflow-hidden rounded-lg">
                <img
                    src={communicationApi.downloadUrl(file.id, true)}
                    alt={file.name}
                    loading="lazy"
                    width={file.width ?? undefined}
                    height={file.height ?? undefined}
                    className="max-h-72 w-auto max-w-full rounded-lg object-contain"
                />
            </a>
        );
    }

    const meta = `${file.extension.toUpperCase()} · ${fileSize(file.size)}`;
    const body = (
        <>
            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-md ${own ? 'bg-[color:var(--app-surface)]' : 'bg-[color:var(--app-surface-muted)]'}`}>
                <PaperclipIcon className="h-4 w-4" aria-hidden="true" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">{file.name}</span>
                <span className={`block text-xs ${own ? 'text-[color:var(--app-muted-foreground)]' : 'text-[color:var(--app-muted-foreground)]'}`}>
                    {file.downloadable ? meta : `${meta} · ${t('communication.chat.attachmentWaiting')}`}
                </span>
            </span>
            {file.downloadable && <DownloadIcon className="h-4 w-4 shrink-0 opacity-70" aria-hidden="true" />}
        </>
    );
    const box = `flex w-64 max-w-full items-center gap-2.5 rounded-lg px-2 py-1.5 text-start ${own ? 'bg-[color:var(--app-surface)]/70' : 'bg-[color:var(--app-surface-muted)]/60'}`;

    return file.downloadable
        ? <a href={communicationApi.downloadUrl(file.id)} className={`${box} hover:opacity-90`} aria-label={`${t('communication.actions.download')} ${file.name}`}>{body}</a>
        : <span className={box}>{body}</span>;
}

/** A system note in the reader's language, from the stored event and names. */
function systemText(message: MessageItem, t: (key: string) => string): string {
    const event = message.metadata?.system_event;
    if (!event) return message.body ?? '';
    const key = `communication.system.${event}`;
    const text = t(key);
    return text === key ? '' : tr(text, { actor: String(message.metadata?.actor_name ?? ''), subject: String(message.metadata?.subject_name ?? '') });
}
