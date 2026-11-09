import Lookup from '@/Components/performance/Lookup';
import { useDesignText } from './ui';

export type LookupKind = 'tasks' | 'activities' | 'processes' | 'services' | 'positions' | 'units' | 'occupations' | 'competencies' | 'isco-groups';
export type LookupHit = { id: string; code: string; name_en: string; name_am: string | null; label: string; process_code?: string | null; status?: string; revision?: string; framework_code?: string | null; professions?: { id: string; code: string; name_en: string; name_am: string | null }[] };

/**
 * A design picker that searches the server inside the organization's scope
 * (organization-design.lookups): at most thirty matches on code or name,
 * never the whole catalog in the page.
 */
export default function DesignLookup({ kind, organizationId, value, display, onChange, id, disabled, minChars = 0 }: {
    kind: LookupKind; organizationId?: string | null; value: string; display: string; onChange: (id: string, hit: LookupHit | null) => void; id?: string; disabled?: boolean; minChars?: number;
}) {
    const { locale, tt } = useDesignText();
    const render = (hit: LookupHit) => `${hit.process_code ? `${hit.process_code} · ` : ''}${hit.revision ? `${hit.revision} · ` : ''}${hit.code} · ${(locale === 'am' && hit.name_am) || hit.name_en}`;

    return (
        <Lookup<LookupHit>
            id={id}
            url={route('organization-design.lookups', kind)}
            params={organizationId ? { organization_id: organizationId } : {}}
            minChars={minChars}
            value={value}
            display={display}
            render={render}
            keyOf={(hit) => hit.id}
            onChange={onChange}
            placeholder={tt('lookup.placeholder')}
            disabled={disabled}
        />
    );
}
