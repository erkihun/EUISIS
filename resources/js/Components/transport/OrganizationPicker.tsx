import { SearchableSelect, type SearchableOption } from '@euisis/ui';
import { useCallback } from 'react';
import { useTransportLabels } from './labels';
import type { OrganizationOption } from '@/types/transport';

/**
 * Organization search for the provider and route forms (transport.organizations.lookup):
 * scoped to the signed-in admin, matched as you type, never a full list.
 */
export default function OrganizationPicker({ id, value, selected, onChange, disabled }: {
    id?: string;
    value: string;
    selected?: OrganizationOption | null;
    onChange: (id: string) => void;
    disabled?: boolean;
}) {
    const { t, name } = useTransportLabels();

    const loadOptions = useCallback(async (query: string, signal: AbortSignal): Promise<SearchableOption[]> => {
        if (query.trim().length < 2) return [];
        const response = await fetch(route('transport.organizations.lookup', { q: query }), { signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) return [];
        const body = (await response.json()) as { data: OrganizationOption[] };
        return body.data.map((organization) => ({ value: organization.id, label: name(organization), description: organization.code ?? undefined }));
    }, [name]);

    return (
        <SearchableSelect
            id={id}
            label={t('transport.organization')}
            value={value}
            options={selected ? [{ value: selected.id, label: name(selected), description: selected.code ?? undefined }] : []}
            loadOptions={loadOptions}
            onChange={(next) => onChange(next)}
            disabled={disabled}
            placeholder={t('transport.searchOrganization')}
            searchPlaceholder={t('transport.searchOrganization')}
            emptyText={t('transport.noMatches')}
            loadingText={t('transport.searching')}
        />
    );
}
