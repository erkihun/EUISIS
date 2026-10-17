import { useEffect, useRef, useState, type JSX } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { communicationApi, firstError, statusOf } from '@/lib/communication/api';
import Avatar from '@/Components/Communication/Avatar';
import { ArrowLeftIcon, SearchIcon } from '@/Components/NavIcons';
import type { Person } from '@/types/communication';

type Props = { onBack: () => void; onStarted: (conversationId: string) => void };

/**
 * Finds a colleague by name or employee number (two letters minimum, scoped by
 * the server to the person's communication reach). Never a dropdown of all
 * employees.
 */
export default function NewConversationPanel({ onBack, onStarted }: Props): JSX.Element {
    const { t } = useLocale();
    const input = useRef<HTMLInputElement>(null);
    const [q, setQ] = useState('');
    const [people, setPeople] = useState<Person[]>([]);
    const [state, setState] = useState<'idle' | 'searching' | 'done' | 'error'>('idle');
    const [starting, setStarting] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => input.current?.focus(), []);

    useEffect(() => {
        if (q.trim().length < 2) {
            setPeople([]);
            setState('idle');
            return;
        }
        const controller = new AbortController();
        setState('searching');
        const timer = window.setTimeout(() => {
            communicationApi.directory(q.trim(), controller.signal)
                .then((rows) => { setPeople(rows); setState('done'); })
                .catch(() => { if (!controller.signal.aborted) setState('error'); });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [q]);

    const start = async (person: Person) => {
        setStarting(person.id);
        setError(null);
        try {
            onStarted(await communicationApi.startDirect(person.id));
        } catch (reason) {
            setError(firstError(reason) ?? (statusOf(reason) === 403 ? t('communication.errors.forbidden') : t('communication.errors.generic')));
            setStarting(null);
        }
    };

    return (
        <div className="flex h-full min-h-0 flex-col" onKeyDown={(event) => { if (event.key === 'Escape') onBack(); }}>
            <div className="flex items-center gap-2 px-3 pb-2 pt-3">
                <button
                    type="button"
                    onClick={onBack}
                    aria-label={t('communication.chat.back')}
                    className="flex h-8 w-8 items-center justify-center rounded-control text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)]"
                >
                    <ArrowLeftIcon className="h-4 w-4" />
                </button>
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{t('communication.newConversation.title')}</h2>
            </div>
            <div className="px-3 pb-3">
                <label className="relative block">
                    <span className="sr-only">{t('communication.newConversation.search')}</span>
                    <SearchIcon className="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                    <input
                        ref={input}
                        type="search"
                        value={q}
                        onChange={(event) => setQ(event.target.value)}
                        placeholder={t('communication.newConversation.search')}
                        aria-describedby="new-conversation-help"
                        className="h-9 w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] ps-8 pe-2 text-sm text-[color:var(--app-foreground)] placeholder:text-[color:var(--app-muted-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/15"
                    />
                </label>
                <p id="new-conversation-help" className="mt-1.5 text-xs text-[color:var(--app-muted-foreground)]">{t('communication.newConversation.searchHelp')}</p>
                {error && <p role="alert" className="mt-2 text-xs text-[color:var(--color-danger,#b42318)]">{error}</p>}
            </div>

            <div className="comm-scroll min-h-0 flex-1 overflow-y-auto border-t border-[color:var(--app-border)]" aria-live="polite" aria-busy={state === 'searching'}>
                {state === 'searching' && <p className="px-4 py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{t('communication.newConversation.searching')}</p>}
                {state === 'error' && <p className="px-4 py-6 text-center text-sm text-[color:var(--color-danger,#b42318)]">{t('communication.errors.generic')}</p>}
                {state === 'done' && people.length === 0 && <p className="px-4 py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{t('communication.newConversation.noResults')}</p>}
                {state === 'done' && people.length > 0 && (
                    <ul>
                        {people.map((person) => (
                            <li key={person.id}>
                                <button
                                    type="button"
                                    disabled={starting !== null}
                                    onClick={() => void start(person)}
                                    className="flex w-full items-center gap-3 px-3 py-2.5 text-start hover:bg-[color:var(--app-surface-muted)] focus-visible:outline focus-visible:-outline-offset-2 focus-visible:outline-2 focus-visible:outline-[color:var(--color-primary)] disabled:opacity-60"
                                >
                                    <Avatar name={person.name} userId={person.id} hasPhoto={person.has_photo} online={person.online} />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium text-[color:var(--app-foreground)]">{person.name}</span>
                                        <span className="block truncate text-xs text-[color:var(--app-muted-foreground)]">
                                            {[person.position, person.unit ?? person.organization].filter(Boolean).join(' · ')}
                                        </span>
                                    </span>
                                    {person.employee_number && <span className="shrink-0 text-[11px] tabular-nums text-[color:var(--app-muted-foreground)]">{person.employee_number}</span>}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
