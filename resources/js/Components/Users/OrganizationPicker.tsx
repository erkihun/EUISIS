import { useState } from 'react';
import { SearchInput, cx } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

export type PickableOrganization = { id: string; code?: string | null; name_en: string; name_am?: string | null };

/** The organization's name in the page's language. */
export const organizationName = (organization: PickableOrganization, locale: string): string =>
    (locale === 'am' && organization.name_am) ? organization.name_am : organization.name_en;

/**
 * The organizations a user is scoped to, as a searchable checklist (in place of
 * a multi-select that needed Ctrl-click). The search box shows from eight
 * organizations up.
 */
export default function OrganizationPicker({ organizations, selected, onChange, error, invalid }: {
    organizations: PickableOrganization[];
    selected: string[];
    onChange: (ids: string[]) => void;
    error?: string;
    invalid?: boolean;
}) {
    const { t, locale } = useLocale();
    const [query, setQuery] = useState('');
    const needle = query.trim().toLowerCase();
    const shown = organizations.filter((organization) => !needle
        || [organization.name_en, organization.name_am, organization.code].some((value) => value?.toLowerCase().includes(needle)));
    const toggle = (id: string) => onChange(selected.includes(id) ? selected.filter((value) => value !== id) : [...selected, id]);

    if (organizations.length === 0) {
        return <p className="rounded-control border border-dashed border-(--app-border) px-3 py-4 text-center text-sm text-(--app-muted-foreground)">{t('users.userOrganizationScopes.noOrganizations')}</p>;
    }

    return (
        <div className="space-y-2">
            {organizations.length >= 8 && (
                <SearchInput value={query} onChange={setQuery} onClear={() => setQuery('')} placeholder={t('users.userOrganizationScopes.searchOrganizations')} label={t('users.userOrganizationScopes.searchOrganizations')} />
            )}
            <div className={cx('max-h-64 overflow-y-auto rounded-control border', invalid ? 'border-red-500' : 'border-(--app-border)')}>
                {shown.length === 0 ? (
                    <p className="px-3 py-4 text-center text-sm text-(--app-muted-foreground)">{t('users.userOrganizationScopes.noOrganizationsMatch')}</p>
                ) : (
                    <ul className="divide-y divide-(--app-border)">
                        {shown.map((organization) => (
                            <li key={organization.id}>
                                <label className="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-(--app-surface-muted)">
                                    <input type="checkbox" className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary" checked={selected.includes(organization.id)} onChange={() => toggle(organization.id)} />
                                    <span className="min-w-0 flex-1 truncate text-(--app-foreground)">{organizationName(organization, locale)}</span>
                                    {organization.code && <span className="font-mono text-xs text-(--app-muted-foreground)">{organization.code}</span>}
                                </label>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <p className="text-xs text-(--app-muted-foreground)" aria-live="polite">{t('users.createForm.selectedCount').replace(':count', String(selected.length))}</p>
            {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}
