import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, FieldError, FormField, Select, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { nameOf, useDesignText } from './ui';

export type PositionOption = { id: string; code: string; name_en: string; name_am: string | null };

export type ProfileFormValues = {
    purpose_en: string; purpose_am: string; reports_to_position_id: string; working_conditions_en: string; working_conditions_am: string;
    authorities_en: string; authorities_am: string; relationships_en: string; relationships_am: string; effective_from: string; effective_to: string;
};

export const emptyProfile = (): ProfileFormValues => ({ purpose_en: '', purpose_am: '', reports_to_position_id: '', working_conditions_en: '', working_conditions_am: '', authorities_en: '', authorities_am: '', relationships_en: '', relationships_am: '', effective_from: '', effective_to: '' });

/** A position profile: first draft (POST), draft edit (PUT) or a new version of the published one (POST versions). */
export default function ProfileForm({ show, onClose, title, initial, url, method, positions }: {
    show: boolean; onClose: () => void; title: string; initial: ProfileFormValues; url: string; method: 'post' | 'put'; positions: PositionOption[];
}) {
    const { locale, tt } = useDesignText();
    const form = useForm<ProfileFormValues>(initial);
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } };
        if (method === 'put') form.put(url, options);
        else form.post(url, options);
    }
    const field = (key: keyof ProfileFormValues, label: string, required = false) => ({ id: `profile-${key}`, label, required, error: errors[key] });
    const text = (key: keyof ProfileFormValues, label: string, rows = 3) => (
        <FormField {...field(key, label)}>{({ id }) => <Textarea id={id} rows={rows} value={form.data[key]} maxLength={5000} onChange={(e) => form.setData(key, e.target.value)} />}</FormField>
    );

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{title}</h2>
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    {text('purpose_en', tt('positionDesign.purposeEn'))}
                    {text('purpose_am', tt('positionDesign.purposeAm'))}
                    <FormField {...field('reports_to_position_id', tt('positionDesign.reportsTo'))} className="sm:col-span-2">
                        {({ id }) => <Select id={id} value={form.data.reports_to_position_id} onChange={(e) => form.setData('reports_to_position_id', e.target.value)}><option value="">—</option>{positions.map((p) => <option key={p.id} value={p.id}>{p.code} · {nameOf(p, locale)}</option>)}</Select>}
                    </FormField>
                    {text('working_conditions_en', tt('positionDesign.workingConditionsEn'), 2)}
                    {text('working_conditions_am', tt('positionDesign.workingConditionsAm'), 2)}
                    {text('authorities_en', tt('positionDesign.authoritiesEn'), 2)}
                    {text('authorities_am', tt('positionDesign.authoritiesAm'), 2)}
                    {text('relationships_en', tt('positionDesign.relationshipsEn'), 2)}
                    {text('relationships_am', tt('positionDesign.relationshipsAm'), 2)}
                    <FormField {...field('effective_from', tt('fields.effectiveFrom'), true)}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_from} onChange={(iso) => form.setData('effective_from', iso)} />}</FormField>
                    <FormField {...field('effective_to', tt('fields.effectiveTo'))}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_to} min={form.data.effective_from || undefined} onChange={(iso) => form.setData('effective_to', iso)} />}</FormField>
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
