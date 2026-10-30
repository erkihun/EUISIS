import { Button, Card, FormField, Input, Select } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';

export interface Period {
    id: string;
    fiscal_year: number;
    period_type: PeriodType;
    name_am: string;
    name_en: string | null;
    due_date: string | null;
    status: 'open' | 'closed';
}

export type PeriodType = 'first_quarter' | 'half_year' | 'nine_months' | 'annual';

const TYPES: PeriodType[] = ['first_quarter', 'half_year', 'nine_months', 'annual'];

/** Create and edit form of a reporting period. */
export default function PeriodForm({ period }: { period?: Period }): JSX.Element {
    const { t } = useLocale();
    const { data, setData, post, put, processing, errors } = useForm({
        fiscal_year: period ? String(period.fiscal_year) : '',
        period_type: period?.period_type ?? 'first_quarter',
        name_am: period?.name_am ?? '',
        name_en: period?.name_en ?? '',
        due_date: period?.due_date ?? '',
        status: period?.status ?? 'open',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        if (period) put(route('service-monitoring.periods.update', period.id));
        else post(route('service-monitoring.periods.store'));
    }

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card className="grid gap-4 p-4 md:grid-cols-2">
                <FormField label={t('serviceMonitoring.periods.fiscalYear')} required error={errors.fiscal_year}>
                    {({ id }) => <Input id={id} type="number" min={1990} max={2100} className="w-full" value={data.fiscal_year} onChange={(e) => setData('fiscal_year', e.target.value)} />}
                </FormField>
                <FormField label={t('serviceMonitoring.periods.periodType')} required error={errors.period_type}>
                    {({ id }) => (
                        <Select id={id} className="w-full" value={data.period_type} onChange={(e) => setData('period_type', e.target.value as PeriodType)}>
                            {TYPES.map((type) => <option key={type} value={type}>{t(`serviceMonitoring.periodTypes.${type}`)}</option>)}
                        </Select>
                    )}
                </FormField>
                <FormField label={t('serviceMonitoring.periods.nameAm')} description={t('serviceMonitoring.periods.nameHelp')} error={errors.name_am}>
                    {({ id }) => <Input id={id} className="w-full" maxLength={160} value={data.name_am} onChange={(e) => setData('name_am', e.target.value)} />}
                </FormField>
                <FormField label={t('serviceMonitoring.periods.nameEn')} error={errors.name_en}>
                    {({ id }) => <Input id={id} className="w-full" maxLength={160} value={data.name_en} onChange={(e) => setData('name_en', e.target.value)} />}
                </FormField>
                <FormField label={t('serviceMonitoring.periods.dueDate')} error={errors.due_date}>
                    {({ id }) => <Input id={id} type="date" className="w-full" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} />}
                </FormField>
                <FormField label={t('serviceMonitoring.periods.status')} required error={errors.status}>
                    {({ id }) => (
                        <Select id={id} className="w-full" value={data.status} onChange={(e) => setData('status', e.target.value as 'open' | 'closed')}>
                            <option value="open">{t('serviceMonitoring.periodStatus.open')}</option>
                            <option value="closed">{t('serviceMonitoring.periodStatus.closed')}</option>
                        </Select>
                    )}
                </FormField>
            </Card>
            <div className="flex justify-end gap-2">
                <Button as={Link} href={route('service-monitoring.periods.index')} variant="outline">{t('serviceMonitoring.actions.cancel')}</Button>
                <Button type="submit" variant="primary" loading={processing}>{t('serviceMonitoring.actions.save')}</Button>
            </div>
        </form>
    );
}
