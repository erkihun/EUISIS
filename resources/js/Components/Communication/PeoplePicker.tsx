import { useEffect, useId, useState, type JSX } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { communicationApi } from '@/lib/communication/api';
import { tr } from '@/lib/communication/format';
import Avatar from '@/Components/Communication/Avatar';
import { SearchIcon, XMarkIcon } from '@/Components/NavIcons';
import type { Person } from '@/types/communication';

type Props = {
    selected: Person[];
    onChange: (people: Person[]) => void;
    excludeIds?: number[];
    max?: number;
    autoFocus?: boolean;
};

/**
 * Choose colleagues by searching (two letters minimum; the server limits the
 * results to the person's communication reach). Never a list of everyone.
 */
export default function PeoplePicker({ selected, onChange, excludeIds = [], max, autoFocus = false }: Props): JSX.Element {
    const { t } = useLocale();
    const inputId = useId();
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Person[]>([]);
    const [state, setState] = useState<'idle' | 'searching' | 'done' | 'error'>('idle');

    useEffect(() => {
        if (q.trim().length < 2) { setResults([]); setState('idle'); return; }
        const controller = new AbortController();
        setState('searching');
        const timer = window.setTimeout(() => {
            communicationApi.directory(q.trim(), controller.signal)
                .then((rows) => { setResults(rows); setState('done'); })
                .catch(() => { if (!controller.signal.aborted) setState('error'); });
        }, 250);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [q]);

    const chosen = new Set(selected.map((p) => p.id));
    const hidden = new Set(excludeIds);
    const full = max !== undefined && selected.length >= max;
    const visible = results.filter((p) => !hidden.has(p.id));

    return (
        <div className="space-y-2">
            {selected.length > 0 && (
                <ul aria-label={t('communication.people.selected')} className="flex flex-wrap gap-1.5">
                    {selected.map((person) => (
                        <li key={person.id} className="inline-flex h-7 items-center gap-1.5 rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] ps-1 pe-1.5 text-xs text-[color:var(--app-foreground)]">
                            <Avatar name={person.name} userId={person.id} hasPhoto={person.has_photo} size="sm" />
                            <span className="max-w-[10rem] truncate">{person.name}</span>
                            <button
                                type="button"
                                onClick={() => onChange(selected.filter((p) => p.id !== person.id))}
                                aria-label={tr(t('communication.people.remove'), { name: person.name })}
                                className="rounded-full p-0.5 text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]"
                            >
                                <XMarkIcon className="h-3.5 w-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <label htmlFor={inputId} className="relative block">
                <span className="sr-only">{t('communication.people.search')}</span>
                <SearchIcon className="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                <input
                    id={inputId}
                    type="search"
                    value={q}
                    autoFocus={autoFocus}
                    disabled={full}
                    onChange={(event) => setQ(event.target.value)}
                    placeholder={t('communication.people.search')}
                    className="h-9 w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] ps-8 pe-2 text-sm text-[color:var(--app-foreground)] placeholder:text-[color:var(--app-muted-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/15 disabled:opacity-60"
                />
            </label>

            <div aria-live="polite" aria-busy={state === 'searching'}>
                {state === 'searching' && <p className="px-1 py-2 text-xs text-[color:var(--app-muted-foreground)]">{t('communication.newConversation.searching')}</p>}
                {state === 'error' && <p className="px-1 py-2 text-xs text-[color:var(--color-danger,#b42318)]">{t('communication.errors.generic')}</p>}
                {state === 'done' && visible.length === 0 && <p className="px-1 py-2 text-xs text-[color:var(--app-muted-foreground)]">{t('communication.newConversation.noResults')}</p>}
                {state === 'done' && visible.length > 0 && (
                    <ul className="max-h-64 overflow-y-auto rounded-control border border-[color:var(--app-border)] comm-scroll">
                        {visible.map((person) => {
                            const isChosen = chosen.has(person.id);
                            return (
                                <li key={person.id}>
                                    <button
                                        type="button"
                                        disabled={isChosen || full}
                                        aria-pressed={isChosen}
                                        onClick={() => onChange([...selected, person])}
                                        className="flex w-full items-center gap-2.5 px-2.5 py-2 text-start hover:bg-[color:var(--app-surface-muted)] disabled:cursor-default disabled:opacity-60"
                                    >
                                        <Avatar name={person.name} userId={person.id} hasPhoto={person.has_photo} size="sm" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm text-[color:var(--app-foreground)]">{person.name}</span>
                                            <span className="block truncate text-[11px] text-[color:var(--app-muted-foreground)]">
                                                {[person.position, person.unit ?? person.organization].filter(Boolean).join(' · ')}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-xs font-medium text-[color:var(--color-primary)]">
                                            {isChosen ? t('communication.people.added') : t('communication.people.add')}
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </div>
    );
}
