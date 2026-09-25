import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import Lookup from '@/Components/performance/Lookup';
import { Empty, Field, Pager, employeeName, Pill, Section, Table, compactInputCls, inputCls, nameOf, pageCls, primaryBtn, secondaryBtn, smallBtn, tdCls, thCls, useEnumLabel, type Bilingual, type Paginator } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Row = {
    id: string; status: string; version: number; is_temporary: boolean;
    employee: { name: string | null; name_en: string | null; number: string | null };
    organization: Bilingual; unit: Bilingual; cycle: Bilingual; effective_from: string; effective_to: string;
};

type AssignmentHit = {
    assignment_id: string; employee_id: string; is_current: boolean; name: string | null; name_en: string | null; number: string | null;
    organization: string | null; unit: string | null; position: string | null; effective_from: string | null; effective_to: string | null;
};

type Props = {
    agreements: Paginator<Row>;
    filters: { search: string; cycle_id: string | null; status: string | null };
    statuses: string[];
    cycles: { id: string; name_en: string; name_am: string | null; status: string }[];
    can: { create: boolean };
};

export default function AgreementsIndex({ agreements, filters, statuses, cycles, can }: Props) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState(filters.search);
    const [picked, setPicked] = useState('');
    const form = useForm({ employee_assignment_id: '', cycle_id: cycles[0]?.id ?? '', is_temporary: false });

    function filter(values: Record<string, string | null | undefined>) {
        router.get(route('performance.agreements.index'), Object.fromEntries(Object.entries({ ...filters, ...values }).filter(([, v]) => v)), { preserveState: true, replace: true });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(route('performance.agreements.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('performance.agreements.title')} description={t('performance.agreements.description')}
            actions={can.create && <button type="button" className={primaryBtn} onClick={() => setOpen((v) => !v)}>{t('performance.agreements.create')}</button>} />}>
            <Head title={t('performance.agreements.title')} />
            <div className={pageCls}>
                {open && (
                    <Section title={t('performance.agreements.create')}>
                        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label={t('performance.agreements.findEmployee')} htmlFor="a-emp" error={form.errors.employee_assignment_id} className="sm:col-span-2">
                                <Lookup<AssignmentHit> id="a-emp" url={route('performance.lookups.employees')} minChars={2} value={form.data.employee_assignment_id} display={picked}
                                    keyOf={(a) => a.assignment_id}
                                    render={(a) => `${employeeName(a, locale)} (${a.number ?? ''}) · ${[a.organization, a.unit, a.position].filter(Boolean).join(' › ')}${a.is_current ? '' : ' · —'}`}
                                    onChange={(id, a) => { form.setData('employee_assignment_id', id); setPicked(a ? `${employeeName(a, locale)} (${a.number ?? ''}) · ${a.position ?? ''}` : ''); }}
                                    placeholder={t('performance.agreements.findEmployee')} />
                            </Field>
                            <Field label={t('performance.fields.cycle')} htmlFor="a-cycle" error={form.errors.cycle_id}>
                                <select id="a-cycle" className={inputCls} value={form.data.cycle_id} onChange={(e) => form.setData('cycle_id', e.target.value)} required>
                                    {cycles.map((c) => <option key={c.id} value={c.id}>{nameOf(c, locale)} ({label('cycle', c.status)})</option>)}
                                </select>
                            </Field>
                            <label className="flex min-h-10 items-end gap-2 pb-2 text-sm"><input type="checkbox" checked={form.data.is_temporary} onChange={(e) => form.setData('is_temporary', e.target.checked)} />{t('performance.fields.temporary')}</label>
                            <div className="flex justify-end gap-2 sm:col-span-2 lg:col-span-4">
                                <button type="button" className={secondaryBtn} onClick={() => setOpen(false)}>{t('performance.actions.cancel')}</button>
                                <button type="submit" className={primaryBtn} disabled={form.processing || !form.data.employee_assignment_id}>{t('performance.actions.create')}</button>
                            </div>
                        </form>
                    </Section>
                )}

                <Section title={t('performance.agreements.title')} actions={
                    <div className="flex flex-wrap gap-2">
                        <form onSubmit={(e) => { e.preventDefault(); filter({ search }); }} className="flex gap-2">
                            <input aria-label={t('performance.actions.search')} className={compactInputCls} value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('performance.agreements.findEmployee')} />
                            <button type="submit" className={smallBtn}>{t('performance.actions.search')}</button>
                        </form>
                        <select aria-label={t('performance.fields.cycle')} className={compactInputCls} value={filters.cycle_id ?? ''} onChange={(e) => filter({ cycle_id: e.target.value })}>
                            <option value="">{t('performance.fields.cycle')}: —</option>
                            {cycles.map((c) => <option key={c.id} value={c.id}>{nameOf(c, locale)}</option>)}
                        </select>
                        <select aria-label={t('performance.fields.status')} className={compactInputCls} value={filters.status ?? ''} onChange={(e) => filter({ status: e.target.value })}>
                            <option value="">{t('performance.fields.status')}: —</option>
                            {statuses.map((s) => <option key={s} value={s}>{label('agreement', s)}</option>)}
                        </select>
                    </div>
                }>
                    {agreements.data.length === 0 ? <Empty>{t('performance.agreements.empty')}</Empty> : (
                        <Table head={<>
                            <th className={thCls}>{t('performance.fields.employee')}</th>
                            <th className={thCls}>{t('performance.fields.unit')}</th>
                            <th className={thCls}>{t('performance.fields.cycle')}</th>
                            <th className={thCls}>{t('performance.fields.effective')}</th>
                            <th className={thCls}>{t('performance.fields.status')}</th>
                        </>}>
                            {agreements.data.map((a) => (
                                <tr key={a.id}>
                                    <td className={tdCls}>
                                        <Link href={route('performance.agreements.show', a.id)} className="font-medium text-[color:var(--color-primary)] hover:underline">{employeeName(a.employee, locale)}</Link>
                                        <p className="text-xs text-gray-500">{a.employee.number}{a.is_temporary ? ` · ${t('performance.fields.temporary')}` : ''}{a.version > 1 ? ` · v${a.version}` : ''}</p>
                                    </td>
                                    <td className={tdCls}>{nameOf(a.unit, locale) || nameOf(a.organization, locale)}</td>
                                    <td className={tdCls}>{nameOf(a.cycle, locale)}</td>
                                    <td className={`${tdCls} whitespace-nowrap text-xs`}><LocalizedDateDisplay value={a.effective_from} /> – <LocalizedDateDisplay value={a.effective_to} /></td>
                                    <td className={tdCls}><Pill group="agreement" value={a.status} /></td>
                                </tr>
                            ))}
                        </Table>
                    )}
                    <Pager page={agreements} />
                </Section>
            </div>
        </AuthenticatedLayout>
    );
}
