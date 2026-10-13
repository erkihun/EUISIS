import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, FieldError, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { nameOf, useDesignText } from './ui';

export type Option = { id: string; code: string; name_en: string; name_am: string | null };
export type MethodologyOption = Option & { version_no: number; calculation_type: string; rounding_rule: string | null };
export type StudyOption = { id: string; reference_number: string; title_en: string; status: string };

export type PlanFormValues = { organization_id: string; title_en: string; title_am: string; notes: string; methodology_id: string; planning_period_start: string; planning_period_end: string; study_id: string };

export const emptyPlan = (organizationId = ''): PlanFormValues => ({ organization_id: organizationId, title_en: '', title_am: '', notes: '', methodology_id: '', planning_period_start: '', planning_period_end: '', study_id: '' });

/** A manpower plan: new (POST, with organization) or draft edit (PUT). */
export default function PlanForm({ show, onClose, title, initial, url, method, organizations, methodologies, studies }: {
    show: boolean; onClose: () => void; title: string; initial: PlanFormValues; url: string; method: 'post' | 'put';
    organizations?: Option[]; methodologies: MethodologyOption[]; studies?: StudyOption[];
}) {
    const { locale, tt } = useDesignText();
    const form = useForm<PlanFormValues>(initial);
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => {
            const out: Record<string, unknown> = Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v]));
            if (method === 'put') delete out.organization_id;
            return out;
        });
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } };
        if (method === 'put') form.put(url, options);
        else form.post(url, options);
    }
    const field = (key: keyof PlanFormValues, label: string, required = false) => ({ id: `plan-${key}`, label, required, error: errors[key] });

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{title}</h2>
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    {organizations && (
                        <FormField {...field('organization_id', tt('fields.organization'), true)} className="sm:col-span-2">
                            {({ id }) => <Select id={id} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}><option value="">—</option>{organizations.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}</Select>}
                        </FormField>
                    )}
                    <FormField {...field('title_en', tt('fields.titleEn'), true)}>{({ id }) => <Input id={id} value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} />}</FormField>
                    <FormField {...field('title_am', tt('fields.titleAm'))}>{({ id }) => <Input id={id} value={form.data.title_am} onChange={(e) => form.setData('title_am', e.target.value)} />}</FormField>
                    <FormField {...field('methodology_id', tt('fields.methodology'), true)} className="sm:col-span-2">
                        {({ id }) => <Select id={id} value={form.data.methodology_id} onChange={(e) => form.setData('methodology_id', e.target.value)}><option value="">—</option>{methodologies.map((m) => <option key={m.id} value={m.id}>{m.code} v{m.version_no} · {nameOf(m, locale)} · {tt(`methodologies.strategies.${m.calculation_type}`)}</option>)}</Select>}
                    </FormField>
                    <FormField {...field('planning_period_start', tt('fields.periodStart'), true)}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.planning_period_start} onChange={(iso) => form.setData('planning_period_start', iso)} />}</FormField>
                    <FormField {...field('planning_period_end', tt('fields.periodEnd'), true)}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.planning_period_end} min={form.data.planning_period_start || undefined} onChange={(iso) => form.setData('planning_period_end', iso)} />}</FormField>
                    {studies && (
                        <FormField {...field('study_id', tt('fields.study'))} className="sm:col-span-2">
                            {({ id }) => <Select id={id} value={form.data.study_id} onChange={(e) => form.setData('study_id', e.target.value)}><option value="">—</option>{studies.map((s) => <option key={s.id} value={s.id}>{s.reference_number} · {s.title_en}</option>)}</Select>}
                        </FormField>
                    )}
                    <FormField {...field('notes', tt('fields.notes'))} className="sm:col-span-2">{({ id }) => <Textarea id={id} rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />}</FormField>
                </div>
                <FieldError>{errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
