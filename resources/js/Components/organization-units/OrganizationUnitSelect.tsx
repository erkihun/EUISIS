import { useEffect, useState } from 'react';
import axios from 'axios';
import type { OrganizationUnitOption } from '@/types/organizationUnit';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    organizationId: string | null | undefined;
    value: string | null | undefined;
    onChange: (unitId: string | null) => void;
    label?: string;
    error?: string;
    nullable?: boolean;
    disabled?: boolean;
    /** Show a hint when no parent units exist (for root unit creation) */
    showRootHint?: boolean;
    /** Exclude this ID from the options (to prevent circular selection in edit) */
    excludeId?: string;
}

export default function OrganizationUnitSelect({
    organizationId,
    value,
    onChange,
    label,
    error,
    nullable = true,
    disabled = false,
    showRootHint = false,
    excludeId,
}: Props) {
    const { t } = useLocale();
    const [options, setOptions] = useState<OrganizationUnitOption[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!organizationId) {
            setOptions([]);
            return;
        }

        setLoading(true);
        axios
            .get(route('organizations.units.options', organizationId))
            .then((res: { data: OrganizationUnitOption[] }) => {
                const filtered = excludeId
                    ? res.data.filter((o) => o.id !== excludeId)
                    : res.data;
                setOptions(filtered);
            })
            .catch(() => setOptions([]))
            .finally(() => setLoading(false));
    }, [organizationId, excludeId]);

    const selectLabel = label ?? t('organizationUnits.selectOrganizationUnit');
    const hasOptions = options.length > 0;

    return (
        <div>
            {label && (
                <label className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    {selectLabel}
                </label>
            )}
            <select
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)}
                disabled={disabled || loading || !organizationId}
                className={[
                    'w-full rounded-md border px-3 py-2 text-sm',
                    'bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100',
                    'focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]',
                    error
                        ? 'border-red-400'
                        : 'border-slate-300 dark:border-slate-600',
                ].join(' ')}
            >
                {nullable && (
                    <option value="">{t('organizationUnits.noParentUnit')}</option>
                )}
                {!organizationId && (
                    <option disabled value="">
                        {t('organizationUnits.selectOrganization')}
                    </option>
                )}
                {organizationId && !loading && !hasOptions && nullable && (
                    <option disabled value="">
                        {t('organizationUnits.noParentUnitsYet')}
                    </option>
                )}
                {options.map((opt) => (
                    <option key={opt.id} value={opt.id}>
                        {'—'.repeat(opt.depth)} {opt.name_en} ({opt.code})
                    </option>
                ))}
            </select>

            {showRootHint && organizationId && !loading && !hasOptions && (
                <p className="mt-1 text-xs text-[color:var(--color-primary)] dark:text-[color:var(--color-primary)]">
                    {t('organizationUnits.thisWillBeRootUnit')}
                </p>
            )}

            {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
        </div>
    );
}
