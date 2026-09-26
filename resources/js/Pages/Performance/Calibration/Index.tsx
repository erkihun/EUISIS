import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Empty, Field, Pager, Pill, Section, Table, inputCls, nameOf, pageCls, primaryBtn, secondaryBtn, tdCls, thCls, type Bilingual, type Paginator } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Option = { id: string; name_en: string; name_am: string | null };

type Props = {
    sessions: Paginator<{ id: string; title: string; status: string; session_date: string | null; items_count: number; cycle: Bilingual; organization: Bilingual }>;
    cycles: Option[];
    organizations: Option[];
    committees: (Option & { organization_id: string | null })[];
    can: { manage: boolean };
};

export default function CalibrationIndex({ sessions, cycles, organizations, committees, can }: Props) {
    const { t, locale } = useLocale();
    const [open, setOpen] = useState(false);
    const form = useForm({ cycle_id: cycles[0]?.id ?? '', organization_id: organizations[0]?.id ?? '', committee_id: '', title: '', session_date: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        form.post(route('performance.calibration.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('performance.calibration.title')} description={t('performance.calibration.description')}
            actions={can.manage && cycles.length > 0 && <button type="button" className={primaryBtn} onClick={() => setOpen((v) => !v)}>{t('performance.calibration.create')}</button>} />}>
            <Head title={t('performance.calibration.title')} />
            <div className={pageCls}>
                {open && (
                    <Section title={t('performance.calibration.create')}>
                        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <Field label={t('performance.fields.title')} error={form.errors.title}><input className={inputCls} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} required /></Field>
                            <Field label={t('performance.fields.cycle')} error={form.errors.cycle_id}>
                                <select className={inputCls} value={form.data.cycle_id} onChange={(e) => form.setData('cycle_id', e.target.value)}>{cycles.map((c) => <option key={c.id} value={c.id}>{nameOf(c, locale)}</option>)}</select>
                            </Field>
                            <Field label={t('performance.fields.organization')} error={form.errors.organization_id}>
                                <select className={inputCls} value={form.data.organization_id} onChange={(e) => form.setData({ ...form.data, organization_id: e.target.value, committee_id: '' })}>{organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}</select>
                            </Field>
                            <Field label={t('performance.fields.committee')} error={form.errors.committee_id}>
                                <select className={inputCls} value={form.data.committee_id} onChange={(e) => form.setData('committee_id', e.target.value)}>
                                    <option value="">—</option>
                                    {committees.filter((c) => c.organization_id === form.data.organization_id).map((c) => <option key={c.id} value={c.id}>{nameOf(c, locale)}</option>)}
                                </select>
                            </Field>
                            <Field label={t('performance.fields.sessionDate')} error={form.errors.session_date}><LocalizedDatePicker value={form.data.session_date} onChange={(v) => form.setData('session_date', v)} /></Field>
                            <div className="flex items-end justify-end gap-2">
                                <button type="button" className={secondaryBtn} onClick={() => setOpen(false)}>{t('performance.actions.cancel')}</button>
                                <button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.actions.create')}</button>
                            </div>
                        </form>
                    </Section>
                )}
                <Section title={t('performance.calibration.title')}>
                    {sessions.data.length === 0 ? <Empty>{t('performance.calibration.empty')}</Empty> : (
                        <Table head={<>
                            <th className={thCls}>{t('performance.fields.title')}</th>
                            <th className={thCls}>{t('performance.fields.organization')}</th>
                            <th className={thCls}>{t('performance.fields.sessionDate')}</th>
                            <th className={thCls}>#</th>
                            <th className={thCls}>{t('performance.fields.status')}</th>
                        </>}>
                            {sessions.data.map((s) => (
                                <tr key={s.id}>
                                    <td className={tdCls}><Link href={route('performance.calibration.show', s.id)} className="font-medium text-[color:var(--color-primary)] hover:underline">{s.title}</Link><p className="text-xs text-gray-500">{nameOf(s.cycle, locale)}</p></td>
                                    <td className={tdCls}>{nameOf(s.organization, locale)}</td>
                                    <td className={tdCls}><LocalizedDateDisplay value={s.session_date} /></td>
                                    <td className={`${tdCls} tabular-nums`}>{s.items_count}</td>
                                    <td className={tdCls}><Pill group="session" value={s.status} /></td>
                                </tr>
                            ))}
                        </Table>
                    )}
                    <Pager page={sessions} />
                </Section>
            </div>
        </AuthenticatedLayout>
    );
}
