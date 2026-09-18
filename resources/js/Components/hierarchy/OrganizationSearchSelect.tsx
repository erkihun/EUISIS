import axios from 'axios';
import { useEffect, useMemo, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import type { OrganizationOption } from '@/Components/hierarchy/types';

function optionLabel(option: OrganizationOption, locale: 'en' | 'am'): string {
    const name = locale === 'am' && option.name_am ? option.name_am : option.name_en;
    const typeName = option.type
        ? locale === 'am' && option.type.name_am
            ? option.type.name_am
            : option.type.name_en
        : null;

    return `${option.code} - ${name}${typeName ? ` (${typeName})` : ''}`;
}

export default function OrganizationSearchSelect({
    hierarchyVersionId,
    label,
    value,
    onChange,
    initialOptions,
    error,
    placeholder,
    disabled = false,
}: {
    hierarchyVersionId: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    initialOptions: OrganizationOption[];
    error?: string;
    placeholder: string;
    disabled?: boolean;
}) {
    const { locale, t } = useLocale();
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState<OrganizationOption[]>(initialOptions);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (disabled) {
            return;
        }

        const timeoutId = window.setTimeout(() => {
            setLoading(true);

            axios
                .get(route('hierarchy-versions.organization-options', { hierarchyVersion: hierarchyVersionId }), {
                    params: {
                        q: query || undefined,
                        selected_id: value || undefined,
                    },
                })
                .then((response) => {
                    setOptions(response.data.options ?? []);
                })
                .finally(() => {
                    setLoading(false);
                });
        }, 250);

        return () => window.clearTimeout(timeoutId);
    }, [disabled, hierarchyVersionId, query, value]);

    const selectedOption = useMemo(
        () => options.find((option) => option.id === value) ?? null,
        [options, value],
    );

    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">
                {label}
            </label>
            <div className="space-y-2 rounded-panel border border-gray-300 bg-gray-50 p-3 dark:border-slate-700 dark:bg-slate-950/50">
                <input
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    disabled={disabled}
                    placeholder={t('hierarchyVersions.searchOrganization')}
                    className="w-full rounded-card border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/20 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                />
                <select
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    disabled={disabled}
                    className="w-full rounded-card border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/20 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                >
                    <option value="">{placeholder}</option>
                    {options.map((option) => (
                        <option key={option.id} value={option.id}>
                            {optionLabel(option, locale)}
                        </option>
                    ))}
                </select>
                <div className="flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-slate-400">
                    <span>{loading ? t('common.loading') : `${options.length} ${t('common.results')}`}</span>
                    <span>{selectedOption ? optionLabel(selectedOption, locale) : t('hierarchyVersions.noEligibleParentOrganizationsFound')}</span>
                </div>
            </div>
            {error && <p className="mt-1.5 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}
