import { useEffect, useState } from 'react';
import { api, type Page } from '../api';
import { useI18n } from '../i18n';
import type { Position } from '../types';
import { inputClass } from './ui';

/** Search-as-you-type over vacant positions inside the user's scope. */
export function PositionPicker({ value, onChange }: { value: Position | null; onChange: (position: Position | null) => void }) {
    const { t } = useI18n();
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<Position[]>([]);

    useEffect(() => {
        if (value) return;
        let live = true;
        const timer = setTimeout(() => {
            api<Page<Position>>('/v1/positions', { query: { search: search.trim(), occupancy: 'vacant' } })
                .then((response) => live && setOptions(response.data.filter((position) => position.is_active)))
                .catch(() => live && setOptions([]));
        }, 250);
        return () => {
            live = false;
            clearTimeout(timer);
        };
    }, [search, value]);

    if (value) {
        return (
            <div className="flex items-center justify-between gap-2 rounded-md border border-brand/40 bg-brand/5 px-3 py-2 text-sm">
                <span>
                    <strong>{value.job_position_code}</strong> · {value.title_en} <span className="text-muted">({value.organization?.name_en})</span>
                </span>
                <button type="button" className="text-xs text-brand underline" onClick={() => onChange(null)}>
                    ✕
                </button>
            </div>
        );
    }

    return (
        <div className="space-y-1">
            <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('emp.pickPosition')} className={inputClass} />
            <ul className="max-h-48 overflow-y-auto rounded-md border border-line">
                {options.map((position) => (
                    <li key={position.id}>
                        <button type="button" onClick={() => onChange(position)} className="w-full px-3 py-2 text-left text-sm hover:bg-subtle">
                            <strong>{position.job_position_code}</strong> · {position.title_en} <span className="text-muted">({position.organization?.name_en})</span>
                        </button>
                    </li>
                ))}
                {options.length === 0 && <li className="px-3 py-2 text-sm text-muted">{t('common.empty')}</li>}
            </ul>
        </div>
    );
}
