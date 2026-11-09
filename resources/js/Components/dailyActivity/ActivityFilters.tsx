import { router } from '@inertiajs/react';
import { useState } from 'react';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { FilterField as Field, FilterPanel, Input, Select, cx, hasFilterQuery, inputClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { named } from './helpers';
import type { FilterOptions } from './types';

export type FilterField = 'search' | 'dateRange' | 'date' | 'month' | 'organization' | 'unit' | 'position' | 'status' | 'late' | 'service';

type Props = {
    routeName: string;
    filters: Record<string, string | undefined>;
    options: FilterOptions;
    fields: FilterField[];
    /** Params that must survive a filter change (e.g. the report type). */
    keep?: Record<string, string>;
    statusNamespace?: string;
};

const dateCls = cx(inputClassName, 'w-full');

/**
 * Filter panel for the management pages. Filters are applied on submit, not
 * per keystroke, so a slow report is not recomputed on every character.
 * Options come from the server already limited to the user's scope.
 */
export default function ActivityFilters({ routeName, filters, options, fields, keep = {}, statusNamespace = 'dailyActivities.statuses' }: Props) {
    const { t, locale } = useLocale();
    const [values, setValues] = useState<Record<string, string>>(() =>
        Object.fromEntries(Object.entries(filters).filter(([, v]) => typeof v === 'string')) as Record<string, string>,
    );

    const set = (key: string, value: string) => setValues((current) => {
        const next = { ...current, [key]: value };
        // Units, positions and tasks belong to one organization.
        if (key === 'organization_id') {
            delete next.organization_unit_id;
            delete next.position_id;
            delete next.position_service_id;
        }
        return next;
    });

    function apply() {
        const params = Object.fromEntries(
            Object.entries({ ...values, ...keep }).filter(([, v]) => v !== undefined && v !== ''),
        );
        router.get(route(routeName), params, { preserveState: false, preserveScroll: true });
    }

    function reset() {
        setValues({});
        router.get(route(routeName), keep, { preserveScroll: true });
    }

    const has = (field: FilterField) => fields.includes(field);
    // The server echoes a default date window; only the URL says what the viewer chose.
    const hasActiveFilters = hasFilterQuery(['page', ...Object.keys(keep)]);

    return (
        <FilterPanel onSubmit={apply} onReset={reset} hasActiveFilters={hasActiveFilters}>
            {has('search') && (
                <Field label={t('common.search')} wide>
                    <Input type="search" placeholder={t('dailyActivities.filters.search')} value={values.search ?? ''} onChange={(e) => set('search', e.target.value)} />
                </Field>
            )}
            {has('dateRange') && (
                <>
                    <Field label={t('dailyActivities.filters.from')} htmlFor="activity-filter-from">
                        <LocalizedDatePicker id="activity-filter-from" className={dateCls} value={values.date_from ?? ''} onChange={(v) => set('date_from', v)} />
                    </Field>
                    <Field label={t('dailyActivities.filters.to')} htmlFor="activity-filter-to">
                        <LocalizedDatePicker id="activity-filter-to" className={dateCls} value={values.date_to ?? ''} onChange={(v) => set('date_to', v)} />
                    </Field>
                </>
            )}
            {has('date') && (
                <Field label={t('dailyActivities.filters.date')} htmlFor="activity-filter-date">
                    <LocalizedDatePicker id="activity-filter-date" className={dateCls} value={values.date ?? ''} onChange={(v) => set('date', v)} />
                </Field>
            )}
            {has('month') && (
                <Field label={t('dailyActivities.filters.month')}>
                    <Input type="month" value={values.month ?? ''} onChange={(e) => set('month', e.target.value)} />
                </Field>
            )}
            {has('organization') && options.organizations.length > 1 && (
                <Field label={t('common.organization')}>
                    <Select value={values.organization_id ?? ''} onChange={(e) => set('organization_id', e.target.value)}>
                        <option value="">{t('dailyActivities.filters.allOrganizations')}</option>
                        {options.organizations.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                    </Select>
                </Field>
            )}
            {has('unit') && options.units.length > 0 && (
                <Field label={t('dailyActivities.columns.unit')}>
                    <Select value={values.organization_unit_id ?? ''} onChange={(e) => set('organization_unit_id', e.target.value)}>
                        <option value="">{t('dailyActivities.filters.allUnits')}</option>
                        {options.units.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                    </Select>
                </Field>
            )}
            {has('position') && options.positions.length > 0 && (
                <Field label={t('dailyActivities.columns.position')}>
                    <Select value={values.position_id ?? ''} onChange={(e) => set('position_id', e.target.value)}>
                        <option value="">{t('dailyActivities.filters.allPositions')}</option>
                        {options.positions.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                    </Select>
                </Field>
            )}
            {has('service') && options.services.length > 0 && (
                <Field label={t('dailyActivities.fields.relatedTask')}>
                    <Select value={values.position_service_id ?? ''} onChange={(e) => set('position_service_id', e.target.value)}>
                        <option value="">{t('dailyActivities.filters.allTasks')}</option>
                        {options.services.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                    </Select>
                </Field>
            )}
            {has('status') && options.statuses && (
                <Field label={t('dailyActivities.columns.status')}>
                    <Select value={values.status ?? ''} onChange={(e) => set('status', e.target.value)}>
                        <option value="">{t('dailyActivities.filters.allStatuses')}</option>
                        {options.statuses.map((s) => <option key={s} value={s}>{t(`${statusNamespace}.${s}`)}</option>)}
                    </Select>
                </Field>
            )}
            {has('late') && (
                <Field label={t('dailyActivities.columns.late')}>
                    <Select value={values.late ?? ''} onChange={(e) => set('late', e.target.value)}>
                        <option value="">{t('dailyActivities.filters.lateAny')}</option>
                        <option value="1">{t('dailyActivities.filters.lateOnly')}</option>
                        <option value="0">{t('dailyActivities.filters.onTimeOnly')}</option>
                    </Select>
                </Field>
            )}
        </FilterPanel>
    );
}
