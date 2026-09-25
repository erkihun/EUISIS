import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { useLocale } from '@/hooks/useLocale';
import { compactInputCls, named, secondaryBtn, primaryBtn } from './helpers';
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

/**
 * Filter row for the management pages. Filters are applied on submit, not
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

    function apply(event?: FormEvent, override?: Record<string, string>) {
        event?.preventDefault();
        const params = Object.fromEntries(
            Object.entries({ ...values, ...override, ...keep }).filter(([, v]) => v !== undefined && v !== ''),
        );
        router.get(route(routeName), params, { preserveState: false, preserveScroll: true });
    }

    function reset() {
        setValues({});
        router.get(route(routeName), keep, { preserveScroll: true });
    }

    const has = (field: FilterField) => fields.includes(field);

    return (
        <form onSubmit={apply} className="flex flex-wrap items-end gap-2 rounded-panel border border-gray-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
            {has('search') && (
                <input
                    type="search"
                    className={`${compactInputCls} w-full sm:w-56`}
                    placeholder={t('dailyActivities.filters.search')}
                    value={values.search ?? ''}
                    onChange={(e) => set('search', e.target.value)}
                />
            )}
            {has('dateRange') && (
                <>
                    <label className="flex w-[calc(50%-0.25rem)] flex-col text-xs text-gray-500 sm:w-40 dark:text-slate-400">
                        {t('dailyActivities.filters.from')}
                        <LocalizedDatePicker value={values.date_from ?? ''} onChange={(v) => set('date_from', v)} />
                    </label>
                    <label className="flex w-[calc(50%-0.25rem)] flex-col text-xs text-gray-500 sm:w-40 dark:text-slate-400">
                        {t('dailyActivities.filters.to')}
                        <LocalizedDatePicker value={values.date_to ?? ''} onChange={(v) => set('date_to', v)} />
                    </label>
                </>
            )}
            {has('date') && (
                <label className="flex w-full flex-col text-xs text-gray-500 sm:w-40 dark:text-slate-400">
                    {t('dailyActivities.filters.date')}
                    <LocalizedDatePicker value={values.date ?? ''} onChange={(v) => set('date', v)} />
                </label>
            )}
            {has('month') && (
                <label className="flex w-full flex-col text-xs text-gray-500 sm:w-40 dark:text-slate-400">
                    {t('dailyActivities.filters.month')}
                    <input type="month" className={compactInputCls} value={values.month ?? ''} onChange={(e) => set('month', e.target.value)} />
                </label>
            )}
            {has('organization') && options.organizations.length > 1 && (
                <select className={`${compactInputCls} w-full sm:w-auto sm:max-w-56`} value={values.organization_id ?? ''} onChange={(e) => set('organization_id', e.target.value)}>
                    <option value="">{t('dailyActivities.filters.allOrganizations')}</option>
                    {options.organizations.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                </select>
            )}
            {has('unit') && options.units.length > 0 && (
                <select className={`${compactInputCls} w-full sm:w-auto sm:max-w-56`} value={values.organization_unit_id ?? ''} onChange={(e) => set('organization_unit_id', e.target.value)}>
                    <option value="">{t('dailyActivities.filters.allUnits')}</option>
                    {options.units.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                </select>
            )}
            {has('position') && options.positions.length > 0 && (
                <select className={`${compactInputCls} w-full sm:w-auto sm:max-w-56`} value={values.position_id ?? ''} onChange={(e) => set('position_id', e.target.value)}>
                    <option value="">{t('dailyActivities.filters.allPositions')}</option>
                    {options.positions.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                </select>
            )}
            {has('service') && options.services.length > 0 && (
                <select className={`${compactInputCls} w-full sm:w-auto sm:max-w-56`} value={values.position_service_id ?? ''} onChange={(e) => set('position_service_id', e.target.value)}>
                    <option value="">{t('dailyActivities.filters.allTasks')}</option>
                    {options.services.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                </select>
            )}
            {has('status') && options.statuses && (
                <select className={`${compactInputCls} w-full sm:w-auto`} value={values.status ?? ''} onChange={(e) => set('status', e.target.value)}>
                    <option value="">{t('dailyActivities.filters.allStatuses')}</option>
                    {options.statuses.map((s) => <option key={s} value={s}>{t(`${statusNamespace}.${s}`)}</option>)}
                </select>
            )}
            {has('late') && (
                <select className={`${compactInputCls} w-full sm:w-auto`} value={values.late ?? ''} onChange={(e) => set('late', e.target.value)}>
                    <option value="">{t('dailyActivities.filters.lateAny')}</option>
                    <option value="1">{t('dailyActivities.filters.lateOnly')}</option>
                    <option value="0">{t('dailyActivities.filters.onTimeOnly')}</option>
                </select>
            )}
            <div className="flex w-full gap-2 sm:w-auto">
                <button type="submit" className={`${primaryBtn} min-h-9 flex-1 py-1.5 sm:flex-none`}>{t('dailyActivities.actions.apply')}</button>
                <button type="button" onClick={reset} className={`${secondaryBtn} min-h-9 flex-1 py-1.5 sm:flex-none`}>{t('dailyActivities.actions.reset')}</button>
            </div>
        </form>
    );
}
