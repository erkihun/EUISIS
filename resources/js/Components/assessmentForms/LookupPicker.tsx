import { compactInputCls, dangerLinkBtn, labelCls } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { useState } from 'react';

export type Picked = { id: string; label: string } | null;

/** Searches master data within the viewer's scope (assessment-forms.lookup). */
export default function LookupPicker({ type, label, value, valueLabel, editable, organizationId = null, onPick }: { type: string; label: string; value: string | null; valueLabel: string | null; editable: boolean; organizationId?: string | null; onPick: (picked: Picked) => void }) {
    const { t, locale } = useLocale();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<{ id: string; label_en: string; label_am: string | null }[]>([]);

    async function search(q: string) {
        setQuery(q);
        if (q.trim().length < 2) { setResults([]); return; }
        const response = await fetch(route('assessment-forms.lookup', { type, q, organization_id: organizationId ?? undefined }), { headers: { Accept: 'application/json' } });
        if (response.ok) setResults((await response.json()).results);
    }

    return (
        <div className="min-w-0">
            <p className={labelCls}>{label}</p>
            {value ? (
                <p className="flex items-center gap-2 text-sm">
                    <span className="min-w-0 truncate">{valueLabel ?? value}</span>
                    {editable && <button type="button" className={dangerLinkBtn} onClick={() => onPick(null)}>{t('assessments.actions.clear')}</button>}
                </p>
            ) : editable ? (
                <div className="relative">
                    <input type="search" aria-label={label} className={`${compactInputCls} w-full`} value={query} placeholder={t('assessments.searchPlaceholder')} onChange={(e) => void search(e.target.value)} />
                    {results.length > 0 && (
                        <ul className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-gray-200 bg-white text-sm shadow-lg dark:border-slate-700 dark:bg-slate-900">
                            {results.map((r) => (
                                <li key={r.id}><button type="button" className="block w-full px-3 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-slate-800" onClick={() => { onPick({ id: r.id, label: (locale === 'am' && r.label_am) || r.label_en }); setQuery(''); setResults([]); }}>{(locale === 'am' && r.label_am) || r.label_en}</button></li>
                            ))}
                        </ul>
                    )}
                </div>
            ) : <p className="text-sm text-gray-500">—</p>}
        </div>
    );
}
