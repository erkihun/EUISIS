import { useEffect, useState } from 'react';
import { api } from '../api';
import { Notice } from '../components/ui';
import { useI18n } from '../i18n';
import type { MessageKey } from '../lang/en';

type Metrics = Record<'organizations' | 'organizations_active' | 'units' | 'positions_active' | 'positions_held' | 'employees_placed' | 'cards_active' | 'cards_suspended', number>;

const tiles: { key: keyof Metrics; href: string }[] = [
    { key: 'organizations', href: '#/organizations' },
    { key: 'organizations_active', href: '#/organizations' },
    { key: 'units', href: '#/units' },
    { key: 'positions_active', href: '#/positions' },
    { key: 'positions_held', href: '#/positions' },
    { key: 'employees_placed', href: '#/employees' },
    { key: 'cards_active', href: '#/cards' },
    { key: 'cards_suspended', href: '#/cards' },
];

export function Dashboard() {
    const { t } = useI18n();
    const [metrics, setMetrics] = useState<Metrics | null>(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        api<{ metrics: Metrics }>('/v1/dashboard')
            .then((response) => setMetrics(response.metrics))
            .catch(() => setError(true));
    }, []);

    return (
        <div className="space-y-4">
            <h1 className="text-xl font-semibold text-ink">{t('dash.title')}</h1>
            {error && <Notice kind="error">{t('common.error')}</Notice>}
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                {tiles.map((tile) => (
                    <a key={tile.key} href={tile.href} className="rounded-lg border border-line bg-surface p-4 hover:border-brand/50">
                        <div className="text-xs text-muted">{t(`dash.${tile.key}` as MessageKey)}</div>
                        <div className="mt-1 text-2xl font-semibold text-ink tabular-nums">{metrics ? metrics[tile.key].toLocaleString() : '—'}</div>
                    </a>
                ))}
            </div>
        </div>
    );
}
