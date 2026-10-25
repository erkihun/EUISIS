import { useCallback, useEffect, useState } from 'react';
import { api } from '../api';
import type { OrgRef } from '../types';

export type TypeRef = { id: string; code: string; name_en: string; name_am: string | null; is_active: boolean };
export type UnitRef = OrgRef & { organization_id: string; parent_unit_id: string | null; status: string };

export type Lookups = {
    organization_types: TypeRef[];
    unit_types: TypeRef[];
    organizations: (OrgRef & { status: string })[];
    units: UnitRef[];
    organization_statuses: string[];
    unit_statuses: string[];
};

const EMPTY: Lookups = { organization_types: [], unit_types: [], organizations: [], units: [], organization_statuses: [], unit_statuses: [] };

/** What the structure forms choose from, inside the viewer's scope; units of one organization on request. */
export function useLookups(organizationId?: string | null) {
    const [lookups, setLookups] = useState<Lookups>(EMPTY);

    const load = useCallback(() => {
        api<Lookups>('/v1/lookups', { query: { organization_id: organizationId ?? undefined } }).then(setLookups).catch(() => setLookups(EMPTY));
    }, [organizationId]);

    useEffect(load, [load]);

    return { lookups, reload: load };
}

/** Select options from records with English and Amharic names. */
export function nameOptions(locale: string, rows: { id: string; code?: string; name_en: string; name_am?: string | null }[]) {
    return rows.map((row) => ({ value: row.id, label: `${(locale === 'am' && row.name_am) || row.name_en}${row.code ? ` (${row.code})` : ''}` }));
}
