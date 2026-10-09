import { useEffect, useId, useRef, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { inputCls } from './helpers';

/**
 * Debounced server-side search (min. 2 characters, max. 20 results). Used
 * for destinations and team members so no page ever ships a full
 * organization or employee list to the browser.
 */
export default function SearchPicker<T extends { id: string }>({ url, label, placeholder, render, onPick, exclude = [] }: {
    url: string;
    label: string;
    placeholder: string;
    render: (row: T) => string;
    onPick: (row: T) => void;
    exclude?: string[];
}) {
    const { t } = useLocale();
    const id = useId();
    const [term, setTerm] = useState('');
    const [rows, setRows] = useState<T[]>([]);
    const [loading, setLoading] = useState(false);
    const latest = useRef(0);

    useEffect(() => {
        const query = term.trim();
        if (query.length < 2) {
            setRows([]);
            return;
        }
        const ticket = ++latest.current;
        const timer = window.setTimeout(() => {
            setLoading(true);
            window.axios.get<{ data: T[] }>(url, { params: { q: query } })
                .then((response) => { if (ticket === latest.current) setRows(response.data.data); })
                .catch(() => { if (ticket === latest.current) setRows([]); })
                .finally(() => { if (ticket === latest.current) setLoading(false); });
        }, 250);

        return () => window.clearTimeout(timer);
    }, [term, url]);

    const visible = rows.filter((row) => !exclude.includes(row.id));

    return (
        <div>
            <label htmlFor={id} className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">{label}</label>
            <input id={id} className={inputCls} value={term} placeholder={placeholder} autoComplete="off" onChange={(e) => setTerm(e.target.value)} />
            {term.trim().length > 0 && term.trim().length < 2 && <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{t('fieldWork.form.minChars')}</p>}
            {term.trim().length >= 2 && !loading && visible.length === 0 && <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{t('fieldWork.form.noResults')}</p>}
            {visible.length > 0 && (
                <ul className="mt-1 max-h-56 overflow-y-auto rounded-lg border border-gray-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-900" role="listbox" aria-label={label}>
                    {visible.map((row) => (
                        <li key={row.id}>
                            <button type="button" className="block w-full px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-slate-800" onClick={() => { onPick(row); setTerm(''); setRows([]); }}>
                                {render(row)}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
