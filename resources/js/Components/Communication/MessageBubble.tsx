import { memo, useEffect, useRef, useState, type JSX } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { communicationApi } from '@/lib/communication/api';
import { clock, fileSize, tr } from '@/lib/communication/format';
import Avatar, { toneClass } from '@/Components/Communication/Avatar';
import MessageText from '@/Components/Communication/MessageText';
import EmojiPicker from '@/Components/Communication/EmojiPicker';
import {
    CheckReadIcon, CopyIcon, DownloadIcon, FlagIcon, ForwardIcon, MenuDotsIcon, TaskIcon, PenIcon, PinIcon, ReplyIcon, SmileIcon, TrashIcon,
} from '@/Components/NavIcons';
import type { Attachment, MessageItem } from '@/types/communication';

export type BubbleAbilities = { reply: boolean; react: boolean; edit: boolean; delete: boolean; pin: boolean; forward: boolean; task: boolean; report: boolean };

type Props = {
    message: MessageItem;
    own: boolean;
    continued: boolean;
    showSender: boolean;
    /** Group and channel chats keep a column for the sender's avatar. */
    avatarColumn: boolean;
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
    onTask: (message: MessageItem) => void;
    onReport: (message: MessageItem) => void;
};

function MessageBubble(props: Props): JSX.Element {
    const { message, own, continued, showSender, currentUserId, pinned, can, status, highlighted } = props;
    const { t, locale } = useLocale();
    const [picker, setPicker] = useState(false);
    const [menu, setMenu] = useState(false);
    const [copied, setCopied] = useState(false);
    // Touch screens have no hover: a tap on the bubble shows its actions, a tap anywhere else hides them.
    const [revealed, setRevealed] = useState(false);
    const item = useRef<HTMLLIElement>(null);
    useEffect(() => {
        if (!revealed) return;
        const away = (event: PointerEvent) => {
            if (!item.current?.contains(event.target as Node)) setRevealed(false);
        };
        document.addEventListener('pointerdown', away);
        return () => document.removeEventListener('pointerdown', away);
    }, [revealed]);
    // Choosing a reaction or a menu entry is the end of the gesture: put the toolbar away again.
    const hadOverlay = useRef(false);
    useEffect(() => {
        if (picker || menu) hadOverlay.current = true;
        else if (hadOverlay.current) { hadOverlay.current = false; setRevealed(false); }
    }, [picker, menu]);

    if (message.type === 'SYSTEM') {
        return (
            <li id={`m-${message.id}`} className="flex justify-center px-4 pb-0.5 pt-3">
                <span className="rounded-full bg-[#e9ecf2] px-3 py-1 text-center text-xs text-(--app-muted-foreground) dark:bg-(--app-surface-muted)">
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

    // One's own messages sit on the brand colour (its strong shade, legible with white text in
    // either theme); colleagues' sit on the surface with a hairline and a light lift.
    const surface = own
        ? 'bg-(--color-primary-strong) text-white shadow-[0_1px_2px_rgba(18,33,112,0.25)]'
        : 'min-w-[120px] border border-[#e6e9ef] bg-(--app-surface) text-(--app-foreground) shadow-[0_1px_2px_rgba(15,23,42,0.05)] dark:border-(--app-border)';
    const corners = own
        ? (continued ? 'rounded-[14px_6px_6px_14px]' : 'rounded-[14px_4px_14px_14px]')
        : (continued ? 'rounded-[6px_14px_14px_6px]' : 'rounded-[4px_14px_14px_14px]');
    const read = status === t('communication.chat.read');
    const quick = can.react ? props.reactions.slice(0, 3) : [];
    const toggleReaction = (emoji: string) => {
        const mine = message.reactions.find((r) => r.emoji === emoji)?.user_ids.includes(currentUserId) ?? false;
        props.onReact(message, emoji, !mine);
    };
    // Toolbar shortcuts: forward and task on colleagues' messages, edit on one's own; the rest stays in the menu.
    const forwardOnBar = !own && can.forward;
    const taskOnBar = !own && can.task;
    const editOnBar = own && can.edit;

    return (
        <li
            ref={item}
            id={`m-${message.id}`}
            className={`group relative flex gap-2.5 ${own ? 'justify-end ps-[14%]' : 'items-start pe-[14%]'} ${continued ? 'pt-[3px]' : 'pt-3.5'} ${highlighted ? 'comm-flash' : ''}`}
        >
            {props.avatarColumn && !own && (
                <div className="w-8 shrink-0">
                    {!continued && <Avatar name={message.sender?.name ?? null} userId={message.sender?.id} size="sm" />}
                </div>
            )}

            <div className={`relative flex min-w-0 flex-col ${own ? 'items-end' : 'items-start'}`}>
                <div
                    className={`relative max-w-[580px] px-3 pb-1.5 pt-2 text-sm leading-normal ${surface} ${corners} ${revealed ? 'ring-2 ring-[color-mix(in_srgb,var(--color-primary)_35%,transparent)]' : ''}`}
                    onClick={(event) => {
                        if (!actionable || !window.matchMedia?.('(pointer: coarse)').matches) return;
                        if ((event.target as HTMLElement).closest('a, button')) return;
                        setRevealed((shown) => !shown);
                    }}
                >
                    {showSender && !own && (
                        <span className={`mb-0.5 block truncate text-[12.5px] font-semibold ${toneClass(message.sender?.id)} text-(--comm-tone-fg)`}>{message.sender?.name}</span>
                    )}

                    {message.forwarded && !message.deleted && (
                        <span className={`mb-1 flex items-center gap-1 text-[11px] italic ${own ? 'text-white/75' : 'text-(--app-muted-foreground)'}`}>
                            <ForwardIcon className="h-3 w-3" aria-hidden="true" /> {t('communication.chat.forwarded')}
                        </span>
                    )}

                    {message.parent && !message.deleted && (
                        <button
                            type="button"
                            onClick={() => message.parent && props.onJump(message.parent.id)}
                            className={`mb-1.5 mt-0.5 block w-full rounded-lg border-s-[3px] px-2.5 py-1.5 text-start ${own ? 'border-white/55 bg-white/12' : 'border-[color-mix(in_srgb,var(--color-primary)_60%,transparent)] bg-(--app-surface-muted)'}`}
                        >
                            <span className={`block text-xs font-semibold ${own ? 'text-white' : 'text-primary'}`}>{message.parent.sender_name}</span>
                            <span className={`block max-w-[420px] truncate text-[12.5px] ${own ? 'text-white/85' : 'text-(--app-muted-foreground)'}`}>
                                {message.parent.deleted ? t('communication.list.deleted') : (message.parent.excerpt || t('communication.list.attachment'))}
                            </span>
                        </button>
                    )}

                    {message.deleted ? (
                        <span className={`italic ${own ? 'text-white/75' : 'text-(--app-muted-foreground)'}`}>{t('communication.chat.deleted')}</span>
                    ) : (
                        <>
                            {message.body && <MessageText body={message.body} mentions={message.mentions} currentUserId={currentUserId} inverse={own} />}
                            {message.attachments.length > 0 && (
                                <div className={`flex flex-col gap-1.5 ${message.body ? 'mt-1.5' : ''}`}>
                                    {message.attachments.map((file) => <AttachmentView key={file.id} file={file} own={own} />)}
                                </div>
                            )}
                        </>
                    )}

                    <span className={`mt-0.5 flex items-center justify-end gap-1.5 text-[11px] tabular-nums ${own ? 'text-white/75' : 'text-(--app-muted-foreground)'}`}>
                        {pinned && <PinIcon className="h-3 w-3" aria-label={t('communication.actions.pin')} />}
                        {message.edited_at && !message.deleted && <span>{t('communication.chat.edited')}</span>}
                        <time dateTime={message.created_at ?? undefined}>{time}</time>
                        {status && <CheckReadIcon className={`h-[15px] w-[15px] ${read ? 'text-[#8fd3b4]' : 'text-white/60'}`} aria-hidden="true" />}
                    </span>
                </div>

                {status && <span className="mt-[3px] pe-1 text-[11px] text-(--app-muted-foreground)">{status}</span>}

                {message.reactions.length > 0 && !message.deleted && (
                    <div className={`relative -mt-1.5 flex flex-wrap gap-1 ${own ? 'justify-end pe-2' : 'ps-2'}`}>
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
                                    className={`inline-flex h-6 items-center gap-1 rounded-full border px-[7px] text-xs font-medium tabular-nums text-(--app-foreground) transition-colors ${mine ? 'border-primary-300 bg-[color-mix(in_srgb,var(--color-primary)_12%,var(--app-surface))]' : 'border-(--app-border) bg-(--app-surface) hover:border-(--app-border-strong)'}`}
                                >
                                    <span aria-hidden="true">{reaction.emoji}</span>
                                    {reaction.count}
                                </button>
                            );
                        })}
                    </div>
                )}

                {actionable && (
                    <div
                        className={`absolute -top-4 z-10 flex items-center gap-px rounded-[9px] border border-(--app-border) bg-(--app-surface) p-[3px] shadow-[0_6px_16px_rgba(15,23,42,0.10)] transition-opacity ${picker || menu || revealed ? 'opacity-100' : 'pointer-events-none opacity-0 focus-within:pointer-events-auto focus-within:opacity-100 group-hover:pointer-events-auto group-hover:opacity-100'} ${own ? '-inset-s-3' : '-inset-e-3'}`}
                    >
                        {quick.map((emoji) => (
                            <button
                                key={emoji}
                                type="button"
                                title={t('communication.actions.react')}
                                aria-label={`${t('communication.actions.react')} ${emoji}`}
                                onClick={() => { setRevealed(false); toggleReaction(emoji); }}
                                className="h-7 w-7 rounded-md text-[15px] hover:bg-(--app-surface-muted) focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary pointer-coarse:h-9 pointer-coarse:w-9"
                            >
                                {emoji}
                            </button>
                        ))}
                        {can.react && props.reactions.length > quick.length && (
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
                                        onPick={(emoji) => { toggleReaction(emoji); setPicker(false); }}
                                    />
                                )}
                            </div>
                        )}
                        {can.react && <span aria-hidden="true" className="mx-0.5 h-[18px] w-px bg-(--app-border)" />}
                        {can.reply && (
                            <ToolbarButton label={t('communication.actions.reply')} onClick={() => { setRevealed(false); props.onReply(message); }}>
                                <ReplyIcon className="h-4 w-4" />
                            </ToolbarButton>
                        )}
                        {forwardOnBar && (
                            <ToolbarButton label={t('communication.actions.forward')} onClick={() => { setRevealed(false); props.onForward(message); }}>
                                <ForwardIcon className="h-4 w-4" />
                            </ToolbarButton>
                        )}
                        {taskOnBar && (
                            <ToolbarButton label={t('communication.actions.task')} onClick={() => { setRevealed(false); props.onTask(message); }}>
                                <TaskIcon className="h-4 w-4" />
                            </ToolbarButton>
                        )}
                        {editOnBar && (
                            <ToolbarButton label={t('communication.actions.edit')} onClick={() => { setRevealed(false); props.onEdit(message); }}>
                                <PenIcon className="h-4 w-4" />
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
                                    {can.forward && !forwardOnBar && (
                                        <MenuItem onClick={() => { setMenu(false); props.onForward(message); }}><ForwardIcon className="h-4 w-4" />{t('communication.actions.forward')}</MenuItem>
                                    )}
                                    {can.pin && (
                                        <MenuItem onClick={() => { setMenu(false); props.onPin(message, !pinned); }}><PinIcon className="h-4 w-4" />{pinned ? t('communication.actions.unpin') : t('communication.actions.pin')}</MenuItem>
                                    )}
                                    {can.task && !taskOnBar && (
                                        <MenuItem onClick={() => { setMenu(false); props.onTask(message); }}><TaskIcon className="h-4 w-4" />{t('communication.actions.task')}</MenuItem>
                                    )}
                                    {can.report && (
                                        <MenuItem onClick={() => { setMenu(false); props.onReport(message); }}><FlagIcon className="h-4 w-4" />{t('communication.actions.report')}</MenuItem>
                                    )}
                                    {can.edit && !editOnBar && (
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
            className="flex h-7 w-7 items-center justify-center rounded-md text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-(--app-foreground) focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary pointer-coarse:h-9 pointer-coarse:w-9"
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
            className={`absolute top-full z-30 mt-1 min-w-44 rounded-control border border-(--app-border) bg-(--app-surface) py-1 shadow-lg ${align === 'end' ? 'inset-e-0' : 'inset-s-0'}`}
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
            className={`flex w-full items-center gap-2.5 px-3 py-1.5 text-start text-sm focus:bg-(--app-surface-muted) focus:outline-hidden hover:bg-(--app-surface-muted) ${danger ? 'text-(--color-danger,#b42318)' : 'text-(--app-foreground)'}`}
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

    const ext = file.extension.toUpperCase();
    const meta = `${ext} · ${fileSize(file.size)}`;
    const body = (
        <>
            <span className={`flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-lg text-[10px] font-bold tracking-[0.02em] ${fileTone(file.extension)} bg-(--comm-tone-bg) text-(--comm-tone-fg)`}>
                {ext.slice(0, 4)}
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate text-[13px] font-medium">{file.name}</span>
                <span className={`block text-[11.5px] ${own ? 'text-white/75' : 'text-(--app-muted-foreground)'}`}>
                    {file.downloadable ? meta : `${meta} · ${t('communication.chat.attachmentWaiting')}`}
                </span>
            </span>
            {file.downloadable && (
                <span className={`flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-lg ${own ? 'text-white/85 group-hover/file:bg-white/12' : 'text-(--app-muted-foreground) group-hover/file:bg-[#e9ecf2] group-hover/file:text-(--app-foreground) dark:group-hover/file:bg-(--app-surface)'}`}>
                    <DownloadIcon className="h-[18px] w-[18px]" aria-hidden="true" />
                </span>
            )}
        </>
    );
    const box = `group/file flex w-[300px] max-w-full items-center gap-2.5 rounded-[10px] border p-2 text-start ${own ? 'border-white/16 bg-white/10' : 'border-(--app-border) bg-[#f7f8fa] dark:bg-(--app-surface-muted)'}`;

    return file.downloadable
        ? <a href={communicationApi.downloadUrl(file.id)} className={box} aria-label={`${t('communication.actions.download')} ${file.name}`}>{body}</a>
        : <span className={box}>{body}</span>;
}

/** File tiles by kind: documents red, sheets green, everything else blue. */
export function fileTone(extension: string): string {
    const ext = extension.toLowerCase();
    if (ext === 'pdf') return 'comm-tone-3';
    if (['xls', 'xlsx', 'csv', 'ods'].includes(ext)) return 'comm-tone-1';
    return 'comm-tone-4';
}

/** A system note in the reader's language, from the stored event and names. */
function systemText(message: MessageItem, t: (key: string) => string): string {
    const event = message.metadata?.system_event;
    if (!event) return message.body ?? '';
    const key = `communication.system.${event}`;
    const text = t(key);
    return text === key ? '' : tr(text, { actor: String(message.metadata?.actor_name ?? ''), subject: String(message.metadata?.subject_name ?? '') });
}
