import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { compactInputCls, named, primaryBtn, secondaryBtn } from './helpers';
import type { FilterOptions } from './types';

type Values = Record<string, string>;

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

    function apply(event: FormEvent) {
        event.preventDefault();
        router.get(route(routeName), Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '')), { preserveScroll: true, preserveState: true });
    }

    const field = `${compactInputCls} w-full sm:w-auto`;

    return (
        <form onSubmit={apply} className="flex flex-wrap items-end gap-2 rounded-panel border border-gray-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
            <input aria-label={t('fieldWork.filters.search')} placeholder={t('fieldWork.filters.search')} className={`${field} sm:w-64`} value={values.search} onChange={(e) => set('search', e.target.value)} maxLength={100} />
            {showStatus && (
                <select aria-label={t('fieldWork.columns.status')} className={field} value={values.status} onChange={(e) => set('status', e.target.value)}>
                    <option value="">{t('fieldWork.filters.allStatuses')}</option>
                    {options.statuses.map((s) => <option key={s} value={s}>{t(`fieldWork.status.${s}`)}</option>)}
                </select>
            )}
            <select aria-label={t('fieldWork.flags.overdue')} className={field} value={values.flag} onChange={(e) => set('flag', e.target.value)}>
                <option value="">{t('fieldWork.filters.allFlags')}</option>
                {(['overdue', 'check_in_missing', 'supervisor_not_resolved', 'gps_issues'] as const).map((f) => <option key={f} value={f}>{t(`fieldWork.flags.${f}`)}</option>)}
            </select>
            <select aria-label={t('fieldWork.columns.type')} className={field} value={values.field_work_type_id} onChange={(e) => set('field_work_type_id', e.target.value)}>
                <option value="">{t('fieldWork.filters.allTypes')}</option>
                {options.types.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
            </select>
            <select aria-label={t('fieldWork.fields.destinationType')} className={field} value={values.destination_type} onChange={(e) => set('destination_type', e.target.value)}>
                <option value="">{t('fieldWork.filters.allDestinations')}</option>
                {options.destination_types.map((d) => <option key={d} value={d}>{t(`fieldWork.destinationTypes.${d}`)}</option>)}
            </select>
            {options.organizations.length > 1 && (
                <select aria-label={t('fieldWork.filters.allOrganizations')} className={field} value={values.organization_id} onChange={(e) => setValues((c) => ({ ...c, organization_id: e.target.value, organization_unit_id: '' }))}>
                    <option value="">{t('fieldWork.filters.allOrganizations')}</option>
                    {options.organizations.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                </select>
            )}
            {options.units.length > 0 && (
                <select aria-label={t('fieldWork.filters.allUnits')} className={field} value={values.organization_unit_id} onChange={(e) => set('organization_unit_id', e.target.value)}>
                    <option value="">{t('fieldWork.filters.allUnits')}</option>
                    {options.units.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                </select>
            )}
            <label className="flex w-[calc(50%-0.25rem)] flex-col text-xs text-gray-500 sm:w-40 dark:text-slate-400">
                {t('fieldWork.filters.from')}
                <LocalizedDatePicker value={values.date_from} onChange={(v) => set('date_from', v)} />
            </label>
            <label className="flex w-[calc(50%-0.25rem)] flex-col text-xs text-gray-500 sm:w-40 dark:text-slate-400">
                {t('fieldWork.filters.to')}
                <LocalizedDatePicker value={values.date_to} onChange={(v) => set('date_to', v)} />
            </label>
            <div className="flex w-full gap-2 sm:w-auto">
                <button type="submit" className={`${primaryBtn} min-h-9 flex-1 py-1.5 sm:flex-none`}>{t('fieldWork.actions.apply')}</button>
                <button type="button" onClick={() => router.get(route(routeName))} className={`${secondaryBtn} min-h-9 flex-1 py-1.5 sm:flex-none`}>{t('fieldWork.actions.reset')}</button>
            </div>
        </form>
    );
}
