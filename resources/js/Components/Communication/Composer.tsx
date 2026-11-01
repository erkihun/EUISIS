import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState, type JSX } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { communicationApi, firstError } from '@/lib/communication/api';
import { fileSize, tr } from '@/lib/communication/format';
import { bodyForEditing, bodyForSending } from '@/Components/Communication/MessageText';
import EmojiPicker, { COMPOSER_EMOJI } from '@/Components/Communication/EmojiPicker';
import { PaperclipIcon, PenIcon, SendIcon, SmileIcon, ReplyIcon, XMarkIcon } from '@/Components/NavIcons';
import type { Attachment, ConversationMemberRow, MessageItem, MessagesLimits } from '@/types/communication';

type Upload = { key: string; file: File; percent: number; attachment: Attachment | null; error: string | null; controller: AbortController };

export type ComposerSubmit = { body: string; attachments: Attachment[] };

type Props = {
    conversationId: string;
    placeholder: string;
    limits: MessagesLimits;
    canUpload: boolean;
    members: ConversationMemberRow[];
    currentUserId: number;
    allowEveryone: boolean;
    replyTo: MessageItem | null;
    editing: MessageItem | null;
    onCancelReply: () => void;
    onCancelEdit: () => void;
    onSend: (payload: ComposerSubmit) => void;
    onSaveEdit: (message: MessageItem, body: string) => Promise<boolean>;
    onTyping: () => void;
};

const draftKey = (id: string) => `communication:draft:${id}`;

function readDraft(id: string): string {
    try {
        return window.sessionStorage.getItem(draftKey(id)) ?? '';
    } catch {
        return '';
    }
}

function writeDraft(id: string, text: string): void {
    try {
        if (text) window.sessionStorage.setItem(draftKey(id), text);
        else window.sessionStorage.removeItem(draftKey(id));
    } catch {
        /* storage unavailable: drafts are a convenience only */
    }
}

export default function Composer(props: Props): JSX.Element {
    const { conversationId, limits, canUpload, members, currentUserId, allowEveryone, replyTo, editing } = props;
    const { t } = useLocale();
    const inputId = useId();
    const hintId = useId();
    const textarea = useRef<HTMLTextAreaElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const [text, setText] = useState(() => readDraft(conversationId));
    const [mentions, setMentions] = useState<{ id: number; name: string }[]>([]);
    const [uploads, setUploads] = useState<Upload[]>([]);
    const [emoji, setEmoji] = useState(false);
    const [query, setQuery] = useState<{ start: number; text: string } | null>(null);
    const [highlight, setHighlight] = useState(0);
    const [dragging, setDragging] = useState(false);
    const [saving, setSaving] = useState(false);

    // A different conversation gets its own draft; uploads in flight are abandoned.
    useEffect(() => {
        setText(readDraft(conversationId));
        setMentions([]);
        setUploads((current) => {
            current.forEach((u) => u.controller.abort());
            return [];
        });
    }, [conversationId]);

    // Editing loads the message into the box; leaving edit mode restores the draft.
    useEffect(() => {
        if (editing) {
            const loaded = bodyForEditing(editing);
            setText(loaded.text);
            setMentions(loaded.mentions);
        } else {
            setText(readDraft(conversationId));
            setMentions([]);
        }
        textarea.current?.focus();
    }, [editing, conversationId]);

    useEffect(() => {
        if (replyTo) textarea.current?.focus();
    }, [replyTo]);

    useEffect(() => {
        if (!editing) writeDraft(conversationId, text);
    }, [text, conversationId, editing]);

    useLayoutEffect(() => {
        const el = textarea.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 180)}px`;
    }, [text]);

    const suggestions = query === null ? [] : [
        ...members
            .filter((m) => m.id !== currentUserId && m.name.toLowerCase().includes(query.text.toLowerCase()))
            .slice(0, 6)
            .map((m) => ({ id: m.id, name: m.name, hint: m.position ?? m.unit ?? '' })),
        ...(allowEveryone && 'everyone'.startsWith(query.text.toLowerCase()) ? [{ id: 0, name: 'everyone', hint: t('communication.composer.everyone') }] : []),
    ];

    const detectMention = (value: string, caret: number) => {
        const match = /(^|\s)@([^\s@]{0,30})$/.exec(value.slice(0, caret));
        setQuery(match ? { start: caret - match[2].length - 1, text: match[2] } : null);
        setHighlight(0);
    };

    const choose = (item: { id: number; name: string }) => {
        if (!query) return;
        const caret = query.start + 1 + query.text.length;
        const insert = `@${item.name} `;
        const next = text.slice(0, query.start) + insert + text.slice(caret);
        setText(next);
        if (item.id) setMentions((current) => current.some((m) => m.id === item.id) ? current : [...current, { id: item.id, name: item.name }]);
        setQuery(null);
        requestAnimationFrame(() => {
            const position = query.start + insert.length;
            textarea.current?.focus();
            textarea.current?.setSelectionRange(position, position);
        });
    };

    const insertEmoji = (value: string) => {
        const el = textarea.current;
        const start = el?.selectionStart ?? text.length;
        const end = el?.selectionEnd ?? text.length;
        setText(text.slice(0, start) + value + text.slice(end));
        setEmoji(false);
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(start + value.length, start + value.length);
        });
    };

    const addFiles = useCallback((files: FileList | File[]) => {
        const allowed = limits.allowedTypes.map((type) => type.toLowerCase());
        for (const file of Array.from(files)) {
            const extension = file.name.includes('.') ? file.name.split('.').pop()!.toLowerCase() : '';
            const key = `${file.name}-${file.size}-${Math.random().toString(36).slice(2)}`;
            const controller = new AbortController();
            let error: string | null = null;
            if (file.size > limits.maxAttachmentKb * 1024) error = tr(t('communication.composer.tooLarge'), { name: file.name, max: limits.maxAttachmentKb });
            else if (allowed.length > 0 && !allowed.includes(extension)) error = tr(t('communication.composer.typeNotAllowed'), { name: file.name });

            setUploads((current) => [...current, { key, file, percent: 0, attachment: null, error, controller }]);
            if (error) continue;

            communicationApi.upload(conversationId, file, (percent) => {
                setUploads((current) => current.map((u) => (u.key === key ? { ...u, percent } : u)));
            }, controller.signal).then((attachment) => {
                setUploads((current) => current.map((u) => (u.key === key ? { ...u, attachment, percent: 100 } : u)));
            }).catch((reason: unknown) => {
                if (controller.signal.aborted) return;
                setUploads((current) => current.map((u) => (u.key === key ? { ...u, error: firstError(reason) ?? t('communication.composer.uploadFailed') } : u)));
            });
        }
    }, [conversationId, limits, t]);

    const removeUpload = (upload: Upload) => {
        upload.controller.abort();
        if (upload.attachment) void communicationApi.discardUpload(upload.attachment.id).catch(() => undefined);
        setUploads((current) => current.filter((u) => u.key !== upload.key));
    };

    const busy = uploads.some((u) => !u.attachment && !u.error);
    const ready = uploads.filter((u) => u.attachment).map((u) => u.attachment!);
    const canSubmit = !busy && !saving && (text.trim().length > 0 || (!editing && ready.length > 0)) && text.length <= limits.maxBody;

    const submit = async () => {
        if (!canSubmit) return;
        const body = bodyForSending(text.trim(), mentions, allowEveryone);
        if (editing) {
            setSaving(true);
            const ok = await props.onSaveEdit(editing, body);
            setSaving(false);
            if (ok) props.onCancelEdit();
            return;
        }
        props.onSend({ body, attachments: ready });
        setText('');
        setMentions([]);
        setUploads((current) => current.filter((u) => !u.attachment && u.error === null));
        writeDraft(conversationId, '');
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (suggestions.length > 0) {
            if (event.key === 'ArrowDown') { event.preventDefault(); setHighlight((h) => (h + 1) % suggestions.length); return; }
            if (event.key === 'ArrowUp') { event.preventDefault(); setHighlight((h) => (h - 1 + suggestions.length) % suggestions.length); return; }
            if (event.key === 'Enter' || event.key === 'Tab') { event.preventDefault(); choose(suggestions[highlight]); return; }
            if (event.key === 'Escape') { event.preventDefault(); setQuery(null); return; }
        }
        if (event.key === 'Escape' && editing) { event.preventDefault(); props.onCancelEdit(); return; }
        if (event.key === 'Escape' && replyTo) { event.preventDefault(); props.onCancelReply(); return; }
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            void submit();
        }
    };

    const insertAt = () => {
        const el = textarea.current;
        const start = el?.selectionStart ?? text.length;
        const end = el?.selectionEnd ?? text.length;
        const before = text.slice(0, start);
        const insert = before && !/\s$/.test(before) ? ' @' : '@';
        const next = before + insert + text.slice(end);
        const caret = start + insert.length;
        setText(next);
        detectMention(next, caret);
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(caret, caret);
        });
    };

    const listboxId = `${inputId}-mentions`;
    const tool = 'flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-lg text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-(--app-foreground) focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary pointer-coarse:h-10 pointer-coarse:w-10';

    return (
        <div
            className="relative shrink-0 px-3 pb-[max(1rem,env(safe-area-inset-bottom))] pt-1 min-[640px]:px-4 min-[1100px]:px-7"
            onDragOver={(event) => { if (canUpload && !editing && event.dataTransfer.types.includes('Files')) { event.preventDefault(); setDragging(true); } }}
            onDragLeave={(event) => { if (!event.currentTarget.contains(event.relatedTarget as Node)) setDragging(false); }}
            onDrop={(event) => {
                if (!canUpload || editing) return;
                event.preventDefault();
                setDragging(false);
                addFiles(event.dataTransfer.files);
            }}
        >
            <div className="relative mx-auto max-w-[804px] rounded-[14px] border border-[#dfe3ea] bg-(--app-surface) shadow-[0_2px_8px_rgba(15,23,42,0.05)] focus-within:border-primary focus-within:ring-[3px] focus-within:ring-primary/12 dark:border-(--app-border)">
                {dragging && (
                    <div className="pointer-events-none absolute inset-0 z-20 flex items-center justify-center rounded-[14px] border-2 border-dashed border-primary bg-(--app-surface)/90 text-sm font-medium text-primary">
                        {t('communication.composer.dropHere')}
                    </div>
                )}

                {(replyTo || editing) && (
                    <div className="mx-2 mt-2 flex items-center gap-2.5 rounded-[10px] bg-(--app-background) px-2.5 py-2">
                        {editing ? <PenIcon className="h-4 w-4 shrink-0 text-primary" aria-hidden="true" /> : <ReplyIcon className="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />}
                        <div className="min-w-0 flex-1">
                            <p className="text-xs font-semibold text-(--app-foreground)">
                                {editing ? t('communication.chat.editing') : tr(t('communication.chat.replyingTo'), { name: replyTo?.sender?.name ?? '' })}
                            </p>
                            {replyTo && !editing && (
                                <p className="truncate text-[12.5px] text-(--app-muted-foreground)">
                                    {replyTo.body ? bodyForEditing(replyTo).text : t('communication.list.attachment')}
                                </p>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={editing ? props.onCancelEdit : props.onCancelReply}
                            aria-label={editing ? t('communication.chat.cancelEdit') : t('communication.chat.cancelReply')}
                            title={editing ? t('communication.chat.cancelEdit') : t('communication.chat.cancelReply')}
                            className="flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-md text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-(--app-foreground)"
                        >
                            <XMarkIcon className="h-4 w-4" />
                        </button>
                    </div>
                )}

                {uploads.length > 0 && (
                    <ul className="mx-2 mt-2 flex flex-wrap gap-2" aria-live="polite">
                        {uploads.map((upload) => (
                            <li
                                key={upload.key}
                                className={`relative flex max-w-[16rem] items-center gap-2 overflow-hidden rounded-lg border px-2.5 py-1.5 text-xs ${upload.error ? 'border-(--tone-danger-border,#f2b8b5) bg-(--tone-danger-bg,#fef3f2)' : 'border-(--app-border) bg-(--app-background)'}`}
                            >
                                <PaperclipIcon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <span className="min-w-0">
                                    <span className="block truncate font-medium text-(--app-foreground)">{upload.file.name}</span>
                                    <span className={`block ${upload.error ? 'text-(--color-danger,#b42318)' : 'text-(--app-muted-foreground)'}`}>
                                        {upload.error ?? (upload.attachment ? fileSize(upload.file.size) : `${upload.percent}%`)}
                                    </span>
                                </span>
                                <button
                                    type="button"
                                    onClick={() => removeUpload(upload)}
                                    aria-label={upload.attachment || upload.error ? tr(t('communication.composer.remove'), { name: upload.file.name }) : t('communication.composer.cancelUpload')}
                                    className="shrink-0 rounded-sm p-0.5 text-(--app-muted-foreground) hover:text-(--app-foreground)"
                                >
                                    <XMarkIcon className="h-3.5 w-3.5" />
                                </button>
                                {!upload.attachment && !upload.error && (
                                    <span
                                        role="progressbar"
                                        aria-label={tr(t('communication.composer.uploading'), { name: upload.file.name })}
                                        aria-valuenow={upload.percent}
                                        aria-valuemin={0}
                                        aria-valuemax={100}
                                        className="absolute inset-x-0 bottom-0 h-0.5 bg-primary transition-[width]"
                                        style={{ width: `${upload.percent}%` }}
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <label htmlFor={inputId} className="sr-only">{t('communication.composer.label')}</label>
                <textarea
                    ref={textarea}
                    id={inputId}
                    rows={2}
                    value={text}
                    maxLength={limits.maxBody}
                    placeholder={props.placeholder}
                    aria-describedby={hintId}
                    role="combobox"
                    aria-expanded={suggestions.length > 0}
                    aria-controls={listboxId}
                    aria-autocomplete="list"
                    aria-activedescendant={suggestions.length > 0 ? `${listboxId}-${highlight}` : undefined}
                    onChange={(event) => {
                        setText(event.target.value);
                        detectMention(event.target.value, event.target.selectionStart ?? event.target.value.length);
                        if (!editing && event.target.value) props.onTyping();
                    }}
                    onKeyDown={onKeyDown}
                    onPaste={(event) => {
                        if (canUpload && !editing && event.clipboardData.files.length > 0) {
                            event.preventDefault();
                            addFiles(event.clipboardData.files);
                        }
                    }}
                    className="block max-h-[180px] min-h-[52px] w-full resize-none border-0 bg-transparent px-4 pb-1 pt-3 text-base leading-normal text-(--app-foreground) placeholder:text-(--app-muted-foreground) focus:outline-hidden focus:ring-0 sm:text-sm"
                />

                <div className="flex items-center gap-0.5 px-2 pb-2 pt-1.5">
                    {canUpload && !editing && (
                        <>
                            <button type="button" onClick={() => fileInput.current?.click()} aria-label={t('communication.composer.attach')} title={t('communication.composer.attach')} className={tool}>
                                <PaperclipIcon className="h-5 w-5" />
                            </button>
                            <input
                                ref={fileInput}
                                type="file"
                                multiple
                                className="sr-only"
                                tabIndex={-1}
                                accept={limits.allowedTypes.map((type) => `.${type}`).join(',')}
                                onChange={(event) => {
                                    if (event.target.files) addFiles(event.target.files);
                                    event.target.value = '';
                                }}
                            />
                        </>
                    )}

                    <div className="relative shrink-0">
                        <button type="button" onClick={() => setEmoji((v) => !v)} aria-label={t('communication.composer.emoji')} aria-expanded={emoji} title={t('communication.composer.emoji')} className={tool}>
                            <SmileIcon className="h-5 w-5" />
                        </button>
                        {emoji && <EmojiPicker emoji={COMPOSER_EMOJI} label={t('communication.composer.emoji')} align="start" onPick={insertEmoji} onClose={() => setEmoji(false)} />}
                    </div>

                    <button type="button" onClick={insertAt} aria-label={t('communication.composer.mention')} title={t('communication.composer.mention')} className={`${tool} text-[17px] font-medium`}>
                        @
                    </button>

                    <p id={hintId} className="min-w-0 flex-1 truncate ps-2 text-[11.5px] text-(--app-muted-foreground)">
                        <span className="hidden sm:inline">{t('communication.composer.hint')}</span>
                        {text.length > limits.maxBody * 0.9 && <span className="ms-2 tabular-nums">{text.length}/{limits.maxBody}</span>}
                    </p>

                    <button
                        type="button"
                        onClick={() => void submit()}
                        disabled={!canSubmit}
                        aria-label={editing ? t('communication.chat.saveEdit') : t('communication.composer.send')}
                        className="flex h-[34px] shrink-0 items-center gap-1.5 rounded-lg bg-primary px-3.5 text-[13px] font-semibold text-white transition-opacity hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-45 disabled:hover:bg-primary pointer-coarse:h-10"
                    >
                        {editing ? t('communication.chat.saveEdit') : t('communication.composer.send')}
                        {!editing && <SendIcon className="h-4 w-4" aria-hidden="true" />}
                    </button>
                </div>

                {suggestions.length > 0 && (
                    <ul
                        id={listboxId}
                        role="listbox"
                        aria-label={t('communication.composer.mentionHint')}
                        className="absolute bottom-full inset-s-0 z-30 mb-2 w-72 max-w-full overflow-hidden rounded-control border border-(--app-border) bg-(--app-surface) py-1 shadow-lg"
                    >
                        {suggestions.map((item, index) => (
                            <li
                                key={`${item.id}-${item.name}`}
                                id={`${listboxId}-${index}`}
                                role="option"
                                aria-selected={index === highlight}
                                onMouseDown={(event) => { event.preventDefault(); choose(item); }}
                                onMouseEnter={() => setHighlight(index)}
                                className={`cursor-pointer px-3 py-1.5 text-sm ${index === highlight ? 'bg-(--app-surface-muted)' : ''}`}
                            >
                                <span className="block font-medium text-(--app-foreground)">@{item.name}</span>
                                {item.hint && <span className="block truncate text-xs text-(--app-muted-foreground)">{item.hint}</span>}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
