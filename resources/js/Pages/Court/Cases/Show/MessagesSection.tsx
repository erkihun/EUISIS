import { useForm } from '@inertiajs/react';
import { useEffect, useRef, type FormEvent, type KeyboardEvent } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { FieldError, cx } from '@/Components/ui';
import type { CaseMessage } from '@/court/types/caseDetail';
import SectionCard from './SectionCard';
import { useCaseText } from './useCaseText';
import { ChatIcon, SendIcon, WarningIcon } from './icons';

interface Props {
    caseId: number;
    caseNumber: string;
    messages: CaseMessage[];
    canCreate: boolean;
    caseLocked: boolean;
    currentUserInitial: string;
}

const URL_PATTERN = /(https?:\/\/[^\s<>"]+)/g;

/**
 * A message's text with its links clickable (the approved-letter notices carry
 * a signed preview link). Review notices sent before the fix kept the
 * placeholder `:case` unfilled; it reads as the case number.
 */
function MessageBody({ body, caseNumber, light }: { body: string; caseNumber: string; light: boolean }) {
    const filled = body.replace(/:case\b/g, caseNumber);

    return (
        <>
            {filled.split(URL_PATTERN).map((part, i) => (i % 2 === 1
                ? <a key={i} href={part} target="_blank" rel="noopener noreferrer" className={cx('break-all underline underline-offset-2', light ? 'text-white' : 'text-primary')}>{part}</a>
                : part))}
        </>
    );
}

/** The message thread between the court and the applicant, with the composer (Enter sends). */
export default function MessagesSection({ caseId, caseNumber, messages, canCreate, caseLocked, currentUserInitial }: Props) {
    const { t, text } = useCaseText();
    const thread = useRef<HTMLDivElement>(null);
    const form = useForm<{ body: string }>({ body: '' });

    useEffect(() => {
        if (thread.current) thread.current.scrollTop = thread.current.scrollHeight;
    }, [messages.length]);

    const send = () => {
        if (!form.data.body.trim() || form.processing) return;
        form.post(route('court.cases.messages.post', caseId), { preserveScroll: true, onSuccess: () => form.reset() });
    };
    const submit = (event: FormEvent) => { event.preventDefault(); send(); };
    const onKey = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); send(); }
    };

    return (
        <SectionCard id="messages" flush icon={<ChatIcon />} count={`${messages.length} ${t('cm.cases.messages.total')}`}
            title={<span><span className="block">{t('cm.cases.messages_section.title')}</span><span className="block text-[11px] font-normal text-(--app-muted-foreground)">{t('cm.cases.case_number')}: {caseNumber}</span></span>}>
            <div ref={thread} className="space-y-4 overflow-y-auto px-5 py-4" style={{ minHeight: 220, maxHeight: 480 }}>
                {messages.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-16 text-center">
                        <div className="mb-4 grid h-14 w-14 place-items-center rounded-card border border-(--app-border) bg-(--app-surface-muted)"><ChatIcon className="h-7 w-7 text-(--app-muted-foreground)" /></div>
                        <p className="text-sm font-medium text-(--app-muted-foreground)">{t('cm.cases.messages_section.no_messages')}</p>
                        <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.cases.show.quick_access_hint')}</p>
                    </div>
                ) : messages.map((message) => (
                    <div key={message.id} className={cx('flex items-end gap-2.5', message.from_admin && 'flex-row-reverse')}>
                        <div className={cx('grid h-8 w-8 shrink-0 place-items-center rounded-full text-xs font-bold shadow-sm', message.from_admin ? 'bg-primary text-white' : 'border border-(--app-border) bg-(--app-surface-muted) text-(--app-muted-foreground)')}>
                            {message.sender_name.charAt(0).toUpperCase()}
                        </div>
                        <div className={cx('flex max-w-[72%] flex-col space-y-1', message.from_admin ? 'items-end' : 'items-start')}>
                            <div className={cx('flex items-center gap-2', message.from_admin && 'flex-row-reverse')}>
                                <span className="text-[11.5px] font-semibold text-(--app-foreground)">{message.sender_name}</span>
                                <span className="text-[10.5px] text-(--app-muted-foreground)"><LocalizedDateDisplay value={message.created_at} withTime /></span>
                            </div>
                            <div className={cx('whitespace-pre-wrap break-words rounded-2xl px-4 py-2.5 text-sm leading-relaxed shadow-sm', message.from_admin ? 'rounded-br-sm bg-primary text-white' : 'rounded-bl-sm border border-(--app-border) bg-(--app-surface-muted) text-(--app-foreground)')}>
                                <MessageBody body={message.body} caseNumber={caseNumber} light={message.from_admin} />
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            {canCreate && !caseLocked ? (
                <div className="border-t border-(--app-border) bg-(--app-surface-muted) px-5 py-4">
                    <form onSubmit={submit}>
                        <div className="flex items-end gap-3">
                            <div className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary text-xs font-bold text-white shadow-sm">{currentUserInitial}</div>
                            <div className="relative flex-1">
                                <textarea
                                    name="body"
                                    rows={1}
                                    value={form.data.body}
                                    onChange={(event) => form.setData('body', event.target.value)}
                                    onKeyDown={onKey}
                                    placeholder={t('cm.cases.messages_section.write_message_placeholder')}
                                    aria-label={t('cm.cases.messages_section.write_message_placeholder')}
                                    className="field-sizing-content w-full resize-none rounded-2xl border border-(--app-border) bg-(--app-surface) px-4 py-2.5 pr-12 text-sm text-(--app-foreground) placeholder:text-(--app-muted-foreground) focus:border-primary/50 focus:outline-none focus:ring-2 focus:ring-primary/40"
                                    style={{ minHeight: 42, maxHeight: 160 }}
                                />
                                <button type="submit" disabled={!form.data.body.trim() || form.processing} aria-label={t('cm.cases.messages_section.send_message')}
                                    className={cx('absolute bottom-2 right-2 grid h-7 w-7 place-items-center rounded-xl transition-all', form.data.body.trim() && !form.processing ? 'bg-primary text-white hover:opacity-90' : 'cursor-not-allowed bg-(--app-border) text-(--app-muted-foreground)')}>
                                    {form.processing
                                        ? <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-r-transparent" aria-hidden="true" />
                                        : <SendIcon className="h-3.5 w-3.5" />}
                                </button>
                            </div>
                        </div>
                        <FieldError className="mt-2">{form.errors.body}</FieldError>
                        <p className="mt-2 pl-11 text-[10.5px] text-(--app-muted-foreground)">{t('cm.cases.messages_section.reply_to_applicant')} · {text('send_hint')}</p>
                    </form>
                </div>
            ) : caseLocked ? (
                <div className="flex items-center gap-2.5 border-t border-(--app-border) bg-amber-50/60 px-5 py-3 dark:bg-amber-950/20">
                    <WarningIcon className="h-4 w-4 shrink-0 text-amber-500" />
                    <span className="text-xs text-amber-700 dark:text-amber-300">{t('cm.cases.show.actions_locked')}</span>
                </div>
            ) : null}
        </SectionCard>
    );
}
