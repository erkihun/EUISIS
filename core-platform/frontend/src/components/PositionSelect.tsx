import { api, type Page } from '../api';
import { useLocale } from '../i18n';
import type { Position } from '../types';
import { SearchableSelect } from '../ui';

/** A position without a holder, searched on the server inside the viewer's scope (UI kit SearchableSelect). */
export default function PositionSelect({ id, value, onChange }: { id?: string; value: string; onChange: (positionId: string) => void }) {
    const { t } = useLocale();

    return (
        <SearchableSelect
            id={id}
            label={t('platform.register.position')}
            immediate
            value={value}
            onChange={(next) => onChange(next)}
            placeholder={t('platform.register.pickPosition')}
            searchPlaceholder={t('platform.register.pickPosition')}
            emptyText={t('platform.register.noPositions')}
            loadingText={t('common.loading')}
            loadOptions={async (query, signal) => {
                const page = await api<Page<Position>>('/v1/positions', { query: { search: query.trim(), occupancy: 'vacant' }, signal });
                return page.data.filter((position) => position.is_active).map((position) => ({
                    value: position.id,
                    label: `${position.job_position_code} · ${position.title_en}`,
                    description: position.organization?.name_en,
                }));
            }}
            className="w-full"
        />
    );
}
