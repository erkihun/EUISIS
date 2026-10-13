import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, FieldError, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { useForm } from '@inertiajs/react';

export type StandardRow = {
    id: string; version_no: number; status: string; standard_measure: string | null; bpr_reference: string | null;
    planned_quantity: string | number | null; quantity_unit: string | null; planned_time_minutes: string | number | null;
    planned_quality: string | number | null; quality_unit: string | null; quality_measure: string | null; quality_source: string | null;
    effective_from: string | null; effective_to: string | null;
};

/**
 * A work-standard draft on a master task or activity: the same fields as the
 * position-task standard form on the Daily Work structure page, in the
 * organization-design modal style. Labels come from the workStandards
 * namespace so one standard reads the same everywhere.
 */
export default function WorkStandardForm({ record, qualitySources, url, method, onClose }: { record?: StandardRow | null; qualitySources: string[]; url: string; method: 'post' | 'put'; onClose: () => void }) {
    const { t } = useLocale();
    const form = useForm({
        standard_measure: record?.standard_measure ?? '', bpr_reference: record?.bpr_reference ?? '',
        planned_quantity: record?.planned_quantity == null ? '' : String(record.planned_quantity), quantity_unit: record?.quantity_unit ?? '',
        planned_time_minutes: record?.planned_time_minutes == null ? '' : String(record.planned_time_minutes),
        planned_quality: record?.planned_quality == null ? '' : String(record.planned_quality), quality_unit: record?.quality_unit ?? '',
        quality_measure: record?.quality_measure ?? '', quality_source: record?.quality_source ?? 'employee',
        effective_from: record?.effective_from ?? '', effective_to: record?.effective_to ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const measuresQuality = form.data.planned_quality !== '';

    function submit(event: React.FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (method === 'put') form.put(url, options);
        else form.post(url, options);
    }
    const field = (key: keyof typeof form.data, label: string, required = false) => ({ id: `std-${key}`, label, required, error: errors[key] });

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{record ? t('workStandards.editDraft') : t('workStandards.newVersion')}</h2>
                <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{t('workStandards.standardHelp')}</p>
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <FormField {...field('standard_measure', t('workStandards.standardMeasure'))}>{({ id }) => <Input id={id} value={form.data.standard_measure} maxLength={500} onChange={(e) => form.setData('standard_measure', e.target.value)} />}</FormField>
                    <FormField {...field('bpr_reference', t('workStandards.bprReference'))}>{({ id }) => <Input id={id} value={form.data.bpr_reference} maxLength={255} onChange={(e) => form.setData('bpr_reference', e.target.value)} />}</FormField>
                    <FormField {...field('planned_quantity', t('workStandards.plannedQuantity'))}>{({ id }) => <Input id={id} type="number" min={0} step="any" value={form.data.planned_quantity} onChange={(e) => form.setData('planned_quantity', e.target.value)} />}</FormField>
                    <FormField {...field('quantity_unit', t('workStandards.quantityUnit'))}>{({ id }) => <Input id={id} value={form.data.quantity_unit} maxLength={64} onChange={(e) => form.setData('quantity_unit', e.target.value)} />}</FormField>
                    <FormField {...field('planned_time_minutes', t('workStandards.plannedTime'))}>{({ id }) => <Input id={id} type="number" min={0} step="any" value={form.data.planned_time_minutes} onChange={(e) => form.setData('planned_time_minutes', e.target.value)} />}</FormField>
                    <FormField {...field('planned_quality', t('workStandards.plannedQuality'))}>{({ id }) => <Input id={id} type="number" min={0} step="any" value={form.data.planned_quality} onChange={(e) => form.setData('planned_quality', e.target.value)} />}</FormField>
                    {measuresQuality && (
                        <>
                            <FormField {...field('quality_unit', t('workStandards.qualityUnit'))}>{({ id }) => <Input id={id} value={form.data.quality_unit} maxLength={64} onChange={(e) => form.setData('quality_unit', e.target.value)} />}</FormField>
                            <FormField {...field('quality_source', t('workStandards.qualitySource'))}>
                                {({ id }) => <Select id={id} value={form.data.quality_source} onChange={(e) => form.setData('quality_source', e.target.value)}>{qualitySources.map((s) => <option key={s} value={s}>{t(`workStandards.qualitySources.${s}`)}</option>)}</Select>}
                            </FormField>
                            <FormField {...field('quality_measure', t('workStandards.qualityMeasure'))} description={t('workStandards.qualityMeasureHelp')} className="sm:col-span-2">
                                {({ id }) => <Textarea id={id} rows={2} value={form.data.quality_measure} onChange={(e) => form.setData('quality_measure', e.target.value)} />}
                            </FormField>
                        </>
                    )}
                    <FormField {...field('effective_from', t('workStandards.effectiveFrom'), true)}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_from} onChange={(iso) => form.setData('effective_from', iso)} />}</FormField>
                    <FormField {...field('effective_to', t('workStandards.effectiveTo'))}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_to} min={form.data.effective_from || undefined} onChange={(iso) => form.setData('effective_to', iso)} />}</FormField>
                </div>
                <FieldError>{errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{t('workStandards.saveDraft')}</Button>
                </div>
            </form>
        </Modal>
    );
}
