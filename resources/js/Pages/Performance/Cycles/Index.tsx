import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Field, Pill, Section, Table, TablePanel, compactInputCls, inputCls, nameOf, pageCls, primaryBtn, secondaryBtn, tdCls, thCls, useEnumLabel, type Bilingual, type Paginator } from '@/Components/performance/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Cycle = {
    id: string; code: string; name_en: string; name_am: string | null; organization: Bilingual;
    start_date: string; end_date: string; midyear: [string | null, string | null]; yearend: [string | null, string | null];
    status: string; is_current: boolean; read_only: boolean; next: string[];
};

type Props = {
    cycles: Paginator<Cycle>;
    statuses: string[];
    organizations: { id: string; name_en: string; name_am: string | null }[];
    canCreateGlobal: boolean;
    can: { create: boolean; update: boolean; activate: boolean; close: boolean };
};

export default function CyclesIndex({ cycles, organizations, canCreateGlobal, can }: Props) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const { confirm } = useConfirm();
    const [open, setOpen] = useState(false);
    const form = useForm({
        code: '', name_en: '', name_am: '', organization_id: canCreateGlobal ? '' : (organizations[0]?.id ?? ''),
        start_date: '', end_date: '', midyear_review_start_date: '', midyear_review_end_date: '', yearend_review_start_date: '', yearend_review_end_date: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        form.post(route('performance.cycles.store'), { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
    }

    async function move(cycle: Cycle, status: string) {
        if (!status) return;
        const { confirmed } = await confirm({ title: `${t('performance.cycles.moveTo')}: ${label('cycle', status)}`, description: nameOf(cycle, locale), confirmLabel: t('performance.cycles.moveTo'), cancelLabel: t('performance.actions.cancel'), variant: status === 'CANCELLED' ? 'danger' : undefined });
        if (confirmed) router.post(route('performance.cycles.transition', cycle.id), { status }, { preserveScroll: true });
    }

    const canMove = can.update || can.activate || can.close;
    const date = (key: keyof typeof form.data, text: string) => (
        <Field label={text} error={form.errors[key]}><LocalizedDatePicker value={form.data[key]} onChange={(v) => form.setData(key, v)} /></Field>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={t('performance.cycles.title')} description={t('performance.cycles.description')}
            actions={can.create && <button type="button" className={primaryBtn} onClick={() => setOpen((v) => !v)}>{t('performance.cycles.create')}</button>} />}>
            <Head title={t('performance.cycles.title')} />
            <div className={pageCls}>
                {open && (
                    <Section title={t('performance.cycles.create')}>
                        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label={t('performance.fields.code')} htmlFor="c-code" error={form.errors.code}><input id="c-code" className={inputCls} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} required /></Field>
                            <Field label={t('performance.fields.nameEn')} htmlFor="c-en" error={form.errors.name_en}><input id="c-en" className={inputCls} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} required /></Field>
                            <Field label={t('performance.fields.nameAm')} htmlFor="c-am" error={form.errors.name_am}><input id="c-am" className={inputCls} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} /></Field>
                            <Field label={t('performance.fields.organization')} htmlFor="c-org" error={form.errors.organization_id}>
                                <select id="c-org" className={inputCls} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}>
                                    {canCreateGlobal && <option value="">{t('performance.cycles.global')}</option>}
                                    {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                                </select>
                            </Field>
                            {date('start_date', t('performance.fields.startDate'))}
                            {date('end_date', t('performance.fields.endDate'))}
                            {date('midyear_review_start_date', `${t('performance.fields.midyear')} — ${t('performance.fields.from')}`)}
                            {date('midyear_review_end_date', `${t('performance.fields.midyear')} — ${t('performance.fields.to')}`)}
                            {date('yearend_review_start_date', `${t('performance.fields.yearend')} — ${t('performance.fields.from')}`)}
                            {date('yearend_review_end_date', `${t('performance.fields.yearend')} — ${t('performance.fields.to')}`)}
                            <div className="flex items-end justify-end gap-2 sm:col-span-2">
                                <button type="button" className={secondaryBtn} onClick={() => setOpen(false)}>{t('performance.actions.cancel')}</button>
                                <button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.actions.create')}</button>
                            </div>
                        </form>
                    </Section>
                )}

                <TablePanel page={cycles} empty={t('performance.cycles.empty')}>
                    <Table head={<>
                        <th className={thCls}>{t('performance.fields.name')}</th>
                        <th className={thCls}>{t('performance.fields.period')}</th>
                        <th className={thCls}>{t('performance.fields.midyear')}</th>
                        <th className={thCls}>{t('performance.fields.yearend')}</th>
                        <th className={thCls}>{t('performance.fields.status')}</th>
                        {canMove && <th className={thCls}><span className="sr-only">{t('performance.cycles.moveTo')}</span></th>}
                    </>}>
                        {cycles.data.map((cycle) => (
                            <tr key={cycle.id}>
                                <td className={tdCls}>
                                    <p className="font-medium text-gray-900 dark:text-slate-100">{nameOf(cycle, locale)}{cycle.is_current && <span className="ms-2 text-xs font-normal text-emerald-700 dark:text-emerald-400">{t('performance.cycles.current')}</span>}</p>
                                    <p className="text-xs text-gray-500 dark:text-slate-400"><span className="font-mono">{cycle.code}</span> · {cycle.organization ? nameOf(cycle.organization, locale) : t('performance.cycles.global')}</p>
                                </td>
                                <td className={`${tdCls} whitespace-nowrap`}><LocalizedDateDisplay value={cycle.start_date} /> – <LocalizedDateDisplay value={cycle.end_date} /></td>
                                <td className={`${tdCls} whitespace-nowrap text-xs`}><LocalizedDateDisplay value={cycle.midyear[0]} /> – <LocalizedDateDisplay value={cycle.midyear[1]} /></td>
                                <td className={`${tdCls} whitespace-nowrap text-xs`}><LocalizedDateDisplay value={cycle.yearend[0]} /> – <LocalizedDateDisplay value={cycle.yearend[1]} /></td>
                                <td className={tdCls}><Pill group="cycle" value={cycle.status} />{cycle.read_only && <span className="ms-2 text-xs text-gray-500">{t('performance.cycles.readOnly')}</span>}</td>
                                {canMove && (
                                    <td className={`${tdCls} text-right`}>
                                        {cycle.next.length > 0 && (
                                            <select aria-label={t('performance.cycles.moveTo')} className={compactInputCls} value="" onChange={(e) => move(cycle, e.target.value)}>
                                                <option value="">{t('performance.cycles.moveTo')}…</option>
                                                {cycle.next.map((s) => <option key={s} value={s}>{label('cycle', s)}</option>)}
                                            </select>
                                        )}
                                    </td>
                                )}
                            </tr>
                        ))}
                    </Table>
                </TablePanel>
            </div>
        </AuthenticatedLayout>
    );
}
