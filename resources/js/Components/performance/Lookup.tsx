import { inputCls } from '@/Components/dailyActivity/helpers';
import { useEffect, useId, useRef, useState } from 'react';

/**
 * Server-side search box for scoped lookups (units, positions, employees).
 * Results are always fetched from /performance/lookups/*, which enforces the
 * viewer's organization scope; nothing is filtered only in the browser.
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
    const listId = useId();
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<T[]>([]);
    const [loading, setLoading] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const paramKey = JSON.stringify(params);

    useEffect(() => {
        if (!open) return;
        if (query.trim().length < minChars) { setItems([]); return; }
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            setLoading(true);
            window.axios.get<T[]>(url, { params: { ...JSON.parse(paramKey), q: query.trim() } })
                .then((response) => setItems(Array.isArray(response.data) ? response.data : []))
                .catch(() => setItems([]))
                .finally(() => setLoading(false));
        }, 250);
        return () => { if (timer.current) clearTimeout(timer.current); };
    }, [open, query, url, paramKey, minChars]);

    return (
        <div className="relative">
            <input
                id={id}
                type="search"
                role="combobox"
                aria-expanded={open}
                aria-controls={listId}
                autoComplete="off"
                className={inputCls}
                disabled={disabled}
                placeholder={placeholder}
                value={open ? query : display}
                onFocus={() => { setQuery(''); setOpen(true); }}
                onBlur={() => setTimeout(() => setOpen(false), 150)}
                onChange={(e) => setQuery(e.target.value)}
            />
            {open && (
                <ul id={listId} role="listbox" className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg dark:border-slate-700 dark:bg-slate-900">
                    {value && (
                        <li><button type="button" className="w-full px-3 py-1.5 text-left text-gray-500 hover:bg-gray-50 dark:hover:bg-slate-800" onMouseDown={() => onChange('', null)}>—</button></li>
                    )}
                    {loading && <li className="px-3 py-1.5 text-gray-500">…</li>}
                    {!loading && items.map((item) => (
                        <li key={keyOf(item)} role="option" aria-selected={keyOf(item) === value}>
                            <button type="button" className="w-full px-3 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-slate-800" onMouseDown={() => { onChange(keyOf(item), item); setOpen(false); }}>
                                {render(item)}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
