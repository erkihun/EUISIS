import { Fragment, type JSX, type ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';
import type { MessageItem } from '@/types/communication';

/**
 * Renders a message body as text. Nothing is ever injected as HTML: the body is
 * split into React text nodes, mention tokens (<@id>, <!channel>, <!everyone>)
 * become chips, and only http(s) links become anchors (opened in a new tab
 * without referrer or opener).
 */
const TOKEN = /(<@\d{1,19}>|<!channel>|<!everyone>|https?:\/\/[^\s<>"']+)/g;

export default function MessageText({ body, mentions, currentUserId }: { body: string; mentions: MessageItem['mentions']; currentUserId: number }): JSX.Element {
    const { t } = useLocale();
    const names = new Map(mentions.map((m) => [m.user_id, m.name]));
    const parts = body.split(TOKEN);

    return (
        <span className="whitespace-pre-wrap break-words [overflow-wrap:anywhere]">
            {parts.map((part, index): ReactNode => {
                const key = `${index}`;
                const user = /^<@(\d{1,19})>$/.exec(part);
                if (user) {
                    const id = Number(user[1]);
                    const own = id === currentUserId;
                    return (
                        <span key={key} className={`rounded px-1 font-medium ${own ? 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100' : 'bg-[color:color-mix(in_srgb,var(--color-primary)_12%,var(--app-surface))] text-[color:var(--app-foreground)]'}`}>
                            @{names.get(id) ?? t('communication.list.you')}
                        </span>
                    );
                }
                if (part === '<!channel>' || part === '<!everyone>') {
                    return <span key={key} className="rounded bg-amber-100 px-1 font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">@{t('communication.composer.everyone')}</span>;
                }
                if (/^https?:\/\//.test(part)) {
                    return <a key={key} href={part} target="_blank" rel="noopener noreferrer nofollow" className="underline underline-offset-2">{part}</a>;
                }
                return <Fragment key={key}>{part}</Fragment>;
            })}
        </span>
    );
}

/** Body as editable text: tokens back to "@Name" for the composer. */
export function bodyForEditing(message: MessageItem): { text: string; mentions: { id: number; name: string }[] } {
    const mentions: { id: number; name: string }[] = [];
    const names = new Map(message.mentions.map((m) => [m.user_id, m.name ?? '']));
    const text = (message.body ?? '').replace(/<@(\d{1,19})>/g, (_, id: string) => {
        const name = names.get(Number(id)) ?? '';
        if (name) mentions.push({ id: Number(id), name });
        return name ? `@${name}` : '';
    }).replace(/<!everyone>|<!channel>/g, '@everyone');

    return { text, mentions };
}

/** "@Name" back to tokens for sending; longest names first so "Abebe Kebede" wins over "Abebe". */
export function bodyForSending(text: string, mentions: { id: number; name: string }[], allowEveryone: boolean): string {
    let body = text;
    for (const mention of [...mentions].sort((a, b) => b.name.length - a.name.length)) {
        body = body.split(`@${mention.name}`).join(`<@${mention.id}>`);
    }
    return allowEveryone ? body.replace(/(^|\s)@everyone\b/g, '$1<!everyone>') : body;
}
