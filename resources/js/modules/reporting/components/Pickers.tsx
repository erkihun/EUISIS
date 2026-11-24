import { cx } from '@/Components/ui';
import { inputCls, labelCls, mutedCls, secondaryBtn, useReporting } from '@/modules/reporting/lib/helpers';
import type { OrganizationRef, PersonRef } from '@/modules/reporting/types';
import { useEffect, useId, useRef, useState, type JSX } from 'react';

interface SearchProps<T> {
    label: string;
    url: string;
    value: T | null;
    onChange: (value: T | null) => void;
    render: (item: T) => string;
    keyOf: (item: T) => string | number;
    minLength?: number;
    error?: string;
    help?: string;
}

/**
 * A search-as-you-type pick-list backed by a scoped, bounded server lookup:
 * the server returns only what the signed-in person may choose, and never the
 * whole list. Keyboard: type, arrow keys, Enter; Escape closes.
 */
function SearchPicker<T>({ label, url, value, onChange, render, keyOf, minLength = 0, error, help }: SearchProps<T>): JSX.Element {
    const { rt } = useReporting();
    const id = useId();
    const [term, setTerm] = useState('');
    const [items, setItems] = useState<T[]>([]);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const [loading, setLoading] = useState(false);
    const request = useRef(0);

    useEffect(() => {
        if (!open || term.trim().length < minLength) {
            setItems([]);

            return;
        }
        const current = ++request.current;
        setLoading(true);
        const timer = setTimeout(() => {
            window.axios.get(url, { params: { q: term.trim() } })
                .then((response: { data: { items: T[] } }) => {
                    if (current === request.current) {
                        setItems(response.data.items);
                        setActive(0);
                    }
                })
                .catch(() => current === request.current && setItems([]))
                .finally(() => current === request.current && setLoading(false));
        }, 250);

        return () => clearTimeout(timer);
    }, [term, open, url, minLength]);

    const choose = (item: T) => {
        onChange(item);
        setOpen(false);
        setTerm('');
    };

    if (value !== null) {
        return (
            <div>
                <span className={labelCls}>{label}</span>
                <div className="flex items-center justify-between gap-2 rounded-control border border-(--app-border) px-3 py-2 text-sm">
                    <span className="min-w-0 truncate">{render(value)}</span>
                    <button type="button" className={cx(secondaryBtn, 'shrink-0')} onClick={() => onChange(null)} aria-label={`${rt('common.change')}: ${label}`}>{rt('common.change')}</button>
                </div>
                {error && <p className="mt-1 text-xs text-red-700 dark:text-red-400" role="alert">{error}</p>}
            </div>
        );
    }

    return (
        <div className="relative">
            <label htmlFor={id} className={labelCls}>{label}</label>
            <input
                id={id} type="search" role="combobox" aria-expanded={open} aria-controls={`${id}-list`} aria-autocomplete="list" autoComplete="off"
                className={inputCls} value={term} placeholder={rt('common.typeToSearch')}
                onChange={(e) => { setTerm(e.target.value); setOpen(true); }}
                onFocus={() => setOpen(true)}
                onBlur={() => setTimeout(() => setOpen(false), 150)}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((i) => Math.min(i + 1, items.length - 1)); }
                    if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(i - 1, 0)); }
                    if (e.key === 'Enter' && open && items[active]) { e.preventDefault(); choose(items[active]); }
                    if (e.key === 'Escape') setOpen(false);
                }}
            />
            {help && <p className={cx(mutedCls, 'mt-1 text-xs')}>{help}</p>}
            {error && <p className="mt-1 text-xs text-red-700 dark:text-red-400" role="alert">{error}</p>}
            {open && (items.length > 0 || loading || term.trim().length >= Math.max(minLength, 1)) && (
                <ul id={`${id}-list`} role="listbox" className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-control border border-(--app-border) bg-(--app-surface) py-1 text-sm shadow-lg">
                    {items.map((item, index) => (
                        <li key={keyOf(item)} role="option" aria-selected={index === active}
                            className={cx('cursor-pointer px-3 py-2', index === active && 'bg-(--app-muted)')}
                            onMouseDown={(e) => { e.preventDefault(); choose(item); }} onMouseEnter={() => setActive(index)}>
                            {render(item)}
                        </li>
                    ))}
                    {items.length === 0 && <li className={cx(mutedCls, 'px-3 py-2')}>{loading ? rt('common.searching') : rt('common.noMatches')}</li>}
                </ul>
            )}
        </div>
    );
}

export function OrganizationPicker(props: { label: string; value: OrganizationRef | null; onChange: (value: OrganizationRef | null) => void; error?: string; help?: string }): JSX.Element {
    const { name } = useReporting();

    return <SearchPicker<OrganizationRef> {...props} url={route('reporting.lookups.organizations')} keyOf={(o) => o.id} render={(o) => `${o.code ?? ''} · ${name(o)}`} />;
}

export function PersonPicker(props: { label: string; value: PersonRef | null; onChange: (value: PersonRef | null) => void; error?: string; help?: string }): JSX.Element {
    return <SearchPicker<PersonRef> {...props} url={route('reporting.lookups.users')} minLength={2} keyOf={(p) => p.id} render={(p) => (p.email ? `${p.name} · ${p.email}` : p.name)} />;
}
