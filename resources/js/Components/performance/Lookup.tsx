import { fill, inputCls } from '@/Components/dailyActivity/helpers';
import { useLocale } from '@/hooks/useLocale';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';

type Option<T> = { key: string; item: T | null; text: string };

/**
 * Server-side search box for scoped lookups (units, positions, employees).
 * Results are always fetched from /performance/lookups/*, which enforces the
 * viewer's organization scope; nothing is filtered only in the browser.
 *
 * Keyboard: ↓/↑ move through the options, Enter picks one, Escape closes the
 * list. Focus stays in the input (aria-activedescendant points at the
 * highlighted option), so a click on an option never closes the list first.
 */
export default function Lookup<T extends Record<string, unknown>>({ url, params = {}, minChars = 0, value, display, render, keyOf, onChange, placeholder, disabled, id }: {
    url: string;
    params?: Record<string, string | undefined>;
    minChars?: number;
    value: string;
    display: string;
    render: (item: T) => string;
    keyOf: (item: T) => string;
    onChange: (key: string, item: T | null) => void;
    placeholder?: string;
    disabled?: boolean;
    id?: string;
}) {
    const { t } = useLocale();
    const listId = useId();
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<T[]>([]);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(-1);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const paramKey = JSON.stringify(params);
    const tooShort = query.trim().length < minChars;

    useEffect(() => {
        if (!open) return;
        if (tooShort) { setItems([]); setLoading(false); return; }
        if (timer.current) clearTimeout(timer.current);
        // "Searching…" from the first keystroke, never a premature "No matches".
        setLoading(true);
        timer.current = setTimeout(() => {
            window.axios.get<T[]>(url, { params: { ...JSON.parse(paramKey), q: query.trim() } })
                .then((response) => setItems(Array.isArray(response.data) ? response.data : []))
                .catch(() => setItems([]))
                .finally(() => setLoading(false));
        }, 250);
        return () => { if (timer.current) clearTimeout(timer.current); };
    }, [open, query, url, paramKey, tooShort]);

    const options: Option<T>[] = [
        ...(value ? [{ key: '', item: null, text: t('performance.lookup.clear') }] : []),
        ...(loading ? [] : items.map((item) => ({ key: keyOf(item), item, text: render(item) }))),
    ];

    // Highlight the first real result (not "clear") whenever the list changes.
    useEffect(() => {
        const count = (value ? 1 : 0) + (loading ? 0 : items.length);
        setActive(count === 0 ? -1 : value && count > 1 ? 1 : 0);
    }, [items, loading, open, value]);

    function pick(option: Option<T>) {
        onChange(option.key, option.item);
        setOpen(false);
        setQuery('');
    }

    function onKeyDown(e: KeyboardEvent<HTMLInputElement>) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!open) setOpen(true);
            else setActive((index) => Math.min(options.length - 1, index + 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((index) => Math.max(0, index - 1));
        } else if (e.key === 'Enter') {
            if (open && active >= 0 && options[active]) {
                e.preventDefault();
                pick(options[active]);
            }
        } else if (e.key === 'Escape' && open) {
            e.preventDefault();
            setOpen(false);
        }
    }

    const optionId = (index: number) => `${listId}-option-${index}`;
    const hint = loading ? t('performance.lookup.searching')
        : tooShort && minChars > 0 ? fill(t('performance.lookup.typeMore'), { count: minChars })
        : items.length === 0 ? t('performance.lookup.noResults') : null;

    return (
        <div className="relative">
            <input
                id={id}
                type="text"
                role="combobox"
                aria-expanded={open}
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={open && active >= 0 ? optionId(active) : undefined}
                autoComplete="off"
                className={inputCls}
                disabled={disabled}
                placeholder={placeholder}
                value={open ? query : display}
                onFocus={() => { setQuery(''); setOpen(true); }}
                onBlur={() => setOpen(false)}
                onKeyDown={onKeyDown}
                onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
            />
            {open && (
                <ul id={listId} role="listbox" className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg dark:border-slate-700 dark:bg-slate-900">
                    {options.map((option, index) => (
                        <li key={option.key || '__clear'} id={optionId(index)} role="option" aria-selected={index === active}
                            className={`cursor-pointer px-3 py-1.5 ${index === active ? 'bg-gray-100 dark:bg-slate-800' : ''} ${option.item === null ? 'text-gray-500 dark:text-slate-400' : ''} ${option.key !== '' && option.key === value ? 'font-medium' : ''}`}
                            onMouseDown={(e) => e.preventDefault()}
                            onMouseEnter={() => setActive(index)}
                            onClick={() => pick(option)}>
                            {option.text}
                        </li>
                    ))}
                    {hint && <li className="px-3 py-1.5 text-gray-500 dark:text-slate-400" aria-live="polite">{hint}</li>}
                </ul>
            )}
        </div>
    );
}
