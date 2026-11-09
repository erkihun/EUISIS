import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { FilterField, FilterPanel, Input, Select, cx, hasFilterQuery, inputClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { named } from './helpers';
import type { FilterOptions } from './types';

type Values = Record<string, string>;

const dateCls = cx(inputClassName, 'w-full');

/**
 * Server-side filters. The server bounds the date window and intersects every
 * filter with the viewer's scope, so a filter can narrow but never widen.
 */
export default function Filters({ routeName, filters, options, showStatus = true }: {
    routeName: string;
    filters: Values;
    options: FilterOptions;
    showStatus?: boolean;
}) {
    const { t, locale } = useLocale();
    const [values, setValues] = useState<Values>({
        search: filters.search ?? '',
        status: filters.status ?? '',
        flag: filters.flag ?? '',
        field_work_type_id: filters.field_work_type_id ?? '',
        destination_type: filters.destination_type ?? '',
        organization_id: filters.organization_id ?? '',
        organization_unit_id: filters.organization_unit_id ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });
    const set = (key: string, value: string) => setValues((current) => ({ ...current, [key]: value }));

    function apply() {
        router.get(route(routeName), Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '')), { preserveScroll: true, preserveState: true });
    }

    // The server echoes its bounded date window; only the URL says what the viewer chose.
    const hasActiveFilters = hasFilterQuery();

    return (
        <FilterPanel onSubmit={apply} onReset={() => router.get(route(routeName))} hasActiveFilters={hasActiveFilters}>
            <FilterField label={t('common.search')} wide>
                <Input type="search" placeholder={t('fieldWork.filters.search')} value={values.search} onChange={(e) => set('search', e.target.value)} maxLength={100} />
            </FilterField>
            {showStatus && (
                <FilterField label={t('fieldWork.columns.status')}>
                    <Select value={values.status} onChange={(e) => set('status', e.target.value)}>
                        <option value="">{t('fieldWork.filters.allStatuses')}</option>
                        {options.statuses.map((s) => <option key={s} value={s}>{t(`fieldWork.status.${s}`)}</option>)}
                    </Select>
                </FilterField>
            )}
            <FilterField label={t('fieldWork.filters.flag')}>
                <Select value={values.flag} onChange={(e) => set('flag', e.target.value)}>
                    <option value="">{t('fieldWork.filters.allFlags')}</option>
                    {(['overdue', 'check_in_missing', 'supervisor_not_resolved', 'gps_issues'] as const).map((f) => <option key={f} value={f}>{t(`fieldWork.flags.${f}`)}</option>)}
                </Select>
            </FilterField>
            <FilterField label={t('fieldWork.columns.type')}>
                <Select value={values.field_work_type_id} onChange={(e) => set('field_work_type_id', e.target.value)}>
                    <option value="">{t('fieldWork.filters.allTypes')}</option>
                    {options.types.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                </Select>
            </FilterField>
            <FilterField label={t('fieldWork.fields.destinationType')}>
                <Select value={values.destination_type} onChange={(e) => set('destination_type', e.target.value)}>
                    <option value="">{t('fieldWork.filters.allDestinations')}</option>
                    {options.destination_types.map((d) => <option key={d} value={d}>{t(`fieldWork.destinationTypes.${d}`)}</option>)}
                </Select>
            </FilterField>
            {options.organizations.length > 1 && (
                <FilterField label={t('common.organization')}>
                    <Select value={values.organization_id} onChange={(e) => setValues((c) => ({ ...c, organization_id: e.target.value, organization_unit_id: '' }))}>
                        <option value="">{t('fieldWork.filters.allOrganizations')}</option>
                        {options.organizations.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                    </Select>
                </FilterField>
            )}
            {options.units.length > 0 && (
                <FilterField label={t('fieldWork.columns.unit')}>
                    <Select value={values.organization_unit_id} onChange={(e) => set('organization_unit_id', e.target.value)}>
                        <option value="">{t('fieldWork.filters.allUnits')}</option>
                        {options.units.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                    </Select>
                </FilterField>
            )}
            <FilterField label={t('fieldWork.filters.from')} htmlFor="field-work-from">
                <LocalizedDatePicker id="field-work-from" className={dateCls} value={values.date_from} onChange={(v) => set('date_from', v)} />
            </FilterField>
            <FilterField label={t('fieldWork.filters.to')} htmlFor="field-work-to">
                <LocalizedDatePicker id="field-work-to" className={dateCls} value={values.date_to} onChange={(v) => set('date_to', v)} />
            </FilterField>
        </FilterPanel>
    );
}
