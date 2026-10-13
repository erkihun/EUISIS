import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { inputCls, panelCls, primaryBtn, secondaryBtn } from '@/Components/dailyActivity/helpers';
import { Field, TextArea } from '@/Components/discipline/shared';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';

/** Open a draft case. The accused's placement is taken from their current assignment on the server. */
export default function DisciplineCreate({ tracks, severities }: { tracks: string[]; severities: string[] }): JSX.Element {
    const { t } = useLocale();
    const form = useForm({ employee_number: '', track: 'formal', severity: 'serious', summary: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(route('discipline.cases.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('discipline.createTitle')} description={t('discipline.createDescription')} />}>
            <Head title={t('discipline.createTitle')} />
            <form onSubmit={submit} className={`${panelCls} grid max-w-2xl gap-3 p-4`}>
                <Field id="employee_number" label={t('discipline.employeeNumber')} error={form.errors.employee_number}>
                    <input id="employee_number" className={inputCls} value={form.data.employee_number} onChange={(e) => form.setData('employee_number', e.target.value)} required />
                </Field>
                <Field id="severity" label={t('discipline.severityLabel')} error={form.errors.severity}>
                    <select id="severity" className={inputCls} value={form.data.severity}
                        onChange={(e) => form.setData((d) => ({ ...d, severity: e.target.value, track: e.target.value === 'serious' ? 'formal' : d.track }))}>
                        {severities.map((s) => <option key={s} value={s}>{t(`discipline.severities.${s}`)}</option>)}
                    </select>
                </Field>
                <Field id="track" label={t('discipline.trackLabel')} error={form.errors.track}>
                    <select id="track" className={inputCls} value={form.data.track} onChange={(e) => form.setData('track', e.target.value)}>
                        {tracks.map((s) => <option key={s} value={s} disabled={s === 'expedited' && form.data.severity !== 'minor'}>{t(`discipline.tracks.${s}`)}</option>)}
                    </select>
                </Field>
                <Field id="summary" label={t('discipline.summary')} error={form.errors.summary}>
                    <TextArea id="summary" value={form.data.summary} onChange={(v) => form.setData('summary', v)} />
                </Field>
                <div className="flex gap-2">
                    <button type="submit" className={primaryBtn} disabled={form.processing}>{t('discipline.newCase')}</button>
                    <Link href={route('discipline.cases.index')} className={secondaryBtn}>{t('discipline.title')}</Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
