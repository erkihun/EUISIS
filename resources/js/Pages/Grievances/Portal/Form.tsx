import { useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { Workspace } from '@/Components/grievances/Workspace';
import { FormRow, Section, inputCls, nameOf, primaryBtn, secondaryBtn, useEnumLabel } from '@/Components/grievances/ui';
import DatePicker from '@/Components/Calendar/LocalizedDatePicker';
import type { PortalFormProps } from '@/types/grievances';

export default function Form({ grievance: g, categories, evidenceTypes, allowedExtensions, maxFileKb }: PortalFormProps) {
    const { t, locale } = useLocale(); const label = useEnumLabel();
    const form = useForm({ subject: g?.subject ?? '', description: g?.description ?? '', category_id: g?.category_id ?? '', incident_date: g?.incident_date ?? '', respondent_description: g?.respondent_description ?? '', amendment_reason: '', files: [] as File[], file_types: [] as string[], submit: false });
    const save = (submit: boolean) => {
        form.transform(({ amendment_reason, ...data }) => ({ ...data, ...(g && g.status !== 'draft' ? { amendment_reason } : {}), submit }));
        form.post(g ? route('employee.grievances.update', g.id) : route('employee.grievances.store'));
    };
    return <Workspace portal title={t(g ? 'grievancePortal.edit' : 'grievancePortal.create')} backHref={route('employee.grievances.index')}>
        {g?.intake_notes && <div role="status" className="rounded-lg bg-amber-50 p-4 text-amber-900">{g.intake_notes}</div>}
        <Section title={t('grievancePortal.details')} description={t('grievancePortal.routing_notice')}><form className="space-y-4" onSubmit={e => { e.preventDefault(); save(false); }}>
            <FormRow label={t('grievances.common.subject')} error={form.errors.subject}><input className={inputCls} required maxLength={255} value={form.data.subject} onChange={e => form.setData('subject', e.target.value)} /></FormRow>
            <div className="grid gap-4 sm:grid-cols-2"><FormRow label={t('grievances.common.category')} error={form.errors.category_id}><select className={inputCls} required value={form.data.category_id} onChange={e => form.setData('category_id', e.target.value)}><option value="">{t('grievances.common.select')}</option>{categories.map(c => <option key={c.id} value={c.id}>{nameOf(c, locale)}</option>)}</select></FormRow><FormRow label={t('grievances.common.incident_date')} error={form.errors.incident_date}><DatePicker value={form.data.incident_date} onChange={v => form.setData('incident_date', v)} /></FormRow></div>
            <FormRow label={t('grievances.common.description')} error={form.errors.description}><textarea className={inputCls} required rows={8} maxLength={20000} value={form.data.description} onChange={e => form.setData('description', e.target.value)} /></FormRow>
            <FormRow label={t('grievancePortal.respondent')} error={form.errors.respondent_description}><textarea className={inputCls} rows={3} maxLength={2000} value={form.data.respondent_description} onChange={e => form.setData('respondent_description', e.target.value)} /></FormRow>
            {g && g.status !== 'draft' && <FormRow label={t('grievancePortal.amendment_reason')}><textarea className={inputCls} value={form.data.amendment_reason} onChange={e => form.setData('amendment_reason', e.target.value)} /></FormRow>}
            <FormRow label={t('grievances.common.files')} help={`${allowedExtensions.join(', ')} · ${maxFileKb} KB`} error={form.errors.files}><input type="file" multiple accept={allowedExtensions.map(x => `.${x}`).join(',')} onChange={e => { const files = Array.from(e.target.files ?? []); form.setData(d => ({ ...d, files, file_types: files.map(() => 'document') })); }} /></FormRow>
            {form.data.files.map((f, i) => <FormRow key={`${f.name}-${i}`} label={f.name}><select className={inputCls} value={form.data.file_types[i]} onChange={e => form.setData('file_types', form.data.file_types.map((v, n) => n === i ? e.target.value : v))}>{evidenceTypes.map(v => <option key={v} value={v}>{label('evidence_type', v)}</option>)}</select></FormRow>)}
            {Object.keys(form.errors).length > 0 && <ul role="alert" className="text-red-700">{Object.entries(form.errors).map(([k, v]) => <li key={k}>{v}</li>)}</ul>}
            <div className="flex gap-3"><button disabled={form.processing} className={secondaryBtn} type="submit">{t('grievancePortal.save_draft')}</button><button disabled={form.processing} className={primaryBtn} type="button" onClick={e => { if (e.currentTarget.form?.reportValidity()) save(true); }}>{t('grievancePortal.submit')}</button></div>
        </form></Section>
    </Workspace>;
}
