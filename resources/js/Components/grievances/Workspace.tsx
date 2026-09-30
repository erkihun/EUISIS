import { Head, Link, useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import DateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import DatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { useLocale } from '@/hooks/useLocale';
import { CaseNumber, ConfidentialNotice, Empty, FormRow, GPill, HandlerName, SlaBadge, Table, TablePanel, inputCls, pageCls, primaryBtn, secondaryBtn, tdCls, thCls, nameOf, useEnumLabel } from './ui';
import type { GrievanceRow, Paginated, TimelineEvent } from '@/types/grievances';

export function Workspace({ title, description, children, portal = false, actions, backHref }: { title: string; description?: string; children: ReactNode; portal?: boolean; actions?: ReactNode; backHref?: string }) {
    return <AuthenticatedLayout variant={portal ? 'portal' : 'default'}><Head title={title} /><div className={pageCls}><PageHeader title={title} description={description} actions={actions} backHref={backHref} />{children}</div></AuthenticatedLayout>;
}

export type InputField = { name: string; label: string; type?: 'text' | 'textarea' | 'select' | 'date' | 'datetime' | 'number' | 'checkbox' | 'file'; options?: { value: string; label: string }[]; required?: boolean; multiple?: boolean; accept?: string; help?: string; maxLength?: number };
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- Inertia's recursive FormDataConvertible makes useForm's generic too deep for tsc.
export type FormValues = Record<string, any>;

/** Small workflow forms keep server validation visible next to their own action. */
export function ActionForm({ url, title, fields = [], initial = {}, method = 'post', submitLabel, children, onSuccess }: {
    url: string; title?: string; fields?: InputField[]; initial?: FormValues; method?: 'post' | 'patch' | 'put' | 'delete' | 'get'; submitLabel?: string; children?: ReactNode; onSuccess?: () => void;
}) {
    const { t } = useLocale();
    const form = useForm<FormValues>({ ...Object.fromEntries(fields.map(f => [f.name, f.type === 'checkbox' ? false : f.type === 'file' ? (f.multiple ? [] : null) : ''])), ...initial });
    return <form className="space-y-3" onSubmit={e => { e.preventDefault(); form.submit(method, url, { preserveScroll: true, onSuccess }); }}>
        {title && <h4 className="font-semibold">{title}</h4>}
        <div className="grid gap-3 sm:grid-cols-2">{fields.map(f => <FormRow key={f.name} label={f.label} help={f.help} error={form.errors[f.name]}>
            {f.type === 'select' ? <select className={inputCls} value={String(form.data[f.name] ?? '')} required={f.required} onChange={e => form.setData(f.name, e.target.value)}><option value="">{t('grievances.common.select')}</option>{f.options?.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
                : f.type === 'textarea' ? <textarea className={inputCls} rows={4} value={String(form.data[f.name] ?? '')} required={f.required} maxLength={f.maxLength} onChange={e => form.setData(f.name, e.target.value)} />
                : f.type === 'date' ? <DatePicker value={String(form.data[f.name] ?? '')} required={f.required} onChange={v => form.setData(f.name, v)} />
                : f.type === 'datetime' ? <div className="flex gap-2"><DatePicker value={String(form.data[f.name] ?? '').slice(0, 10)} required={f.required} onChange={v => form.setData(f.name, v ? `${v}T${String(form.data[f.name] ?? '').slice(11, 16) || '09:00'}` : '')} /><input aria-label={f.label} type="time" className={inputCls} value={String(form.data[f.name] ?? '').slice(11, 16)} required={f.required} onChange={e => form.setData(f.name, `${String(form.data[f.name] ?? '').slice(0, 10)}T${e.target.value}`)} /></div>
                : f.type === 'checkbox' ? <input type="checkbox" checked={Boolean(form.data[f.name])} onChange={e => form.setData(f.name, e.target.checked)} />
                : f.type === 'file' ? <input className={inputCls} type="file" multiple={f.multiple} accept={f.accept} required={f.required} onChange={e => form.setData(f.name, f.multiple ? Array.from(e.target.files ?? []) : e.target.files?.[0] ?? null)} />
                : <input className={inputCls} type={f.type ?? 'text'} value={String(form.data[f.name] ?? '')} required={f.required} maxLength={f.maxLength} onChange={e => form.setData(f.name, e.target.value)} />}
        </FormRow>)}</div>
        {Object.keys(form.errors).length > 0 && <ul role="alert" className="text-sm text-red-700 dark:text-red-300">{Object.entries(form.errors).map(([k, v]) => <li key={k}>{v}</li>)}</ul>}
        {children}<button className={primaryBtn} disabled={form.processing}>{submitLabel ?? title ?? t('grievances.common.save')}</button>
    </form>;
}

export function CaseTable({ rows, page, portal = false }: { rows?: GrievanceRow[]; page?: Paginated<GrievanceRow>; portal?: boolean }) {
    const { t, locale } = useLocale(); const data = page?.data ?? rows ?? [];
    return <TablePanel page={page} empty={t('grievanceCases.empty')}><Table head={<>{['case_number', 'subject', 'status', 'handler', 'due', 'sla'].map(k => <th key={k} className={thCls}>{t(`grievances.common.${k}`)}</th>)}</>}>
        {data.map(g => <tr key={g.id}><td className={tdCls}><Link href={route(portal ? 'employee.grievances.show' : 'grievances.cases.show', g.id)}><CaseNumber value={g.reference_number} /></Link></td><td className={tdCls}>{g.subject ?? t('grievances.common.no_access_details')}<span className="block text-xs text-gray-500">{nameOf(g.category, locale)}</span></td><td className={tdCls}><GPill group="status" value={g.status} /></td><td className={tdCls}><HandlerName handler={g.handler} /></td><td className={tdCls}><DateDisplay value={g.sla?.due_at} /></td><td className={tdCls}><SlaBadge sla={g.sla} compact /></td></tr>)}
    </Table>{!data.length && !page && <Empty>{t('grievanceCases.empty')}</Empty>}</TablePanel>;
}

export function CaseBanner({ grievance: g }: { grievance: GrievanceRow }) {
    const { t } = useLocale();
    return <div className="space-y-3"><ConfidentialNotice level={g.confidentiality_level} /><div className="flex flex-wrap items-center gap-4 rounded-lg border p-4 dark:border-slate-700"><CaseNumber value={g.reference_number} /><GPill group="status" value={g.status} /><HandlerName handler={g.handler} /><span>{t('grievances.common.stage')}: {g.stage_no ?? '—'}</span><span>{t('grievances.common.submitted')}: <DateDisplay value={g.submitted_at} /></span><span>{t('grievances.common.due')}: <DateDisplay value={g.sla?.due_at} /></span><SlaBadge sla={g.sla} />{g.appeal_deadline_at && <span>{t('grievances.common.appeal_deadline')}: <DateDisplay value={g.appeal_deadline_at} /></span>}</div></div>;
}

export function Timeline({ events }: { events: TimelineEvent[] }) {
    const label = useEnumLabel(); const { locale } = useLocale();
    return <ol className="space-y-4">{events.map(e => {
        const handler = e.data?.handler as { name_en?: string; name_am?: string | null } | undefined;
        return <li key={e.id} className="border-l-2 border-gray-200 pl-4 dark:border-slate-700"><p className="font-medium">{label('event', e.event)}</p>{handler && <p className="text-sm">{nameOf({ name_en: handler.name_en ?? null, name_am: handler.name_am ?? null }, locale)}</p>}<p className="text-xs text-gray-500"><DateDisplay value={e.occurred_at} withTime />{e.actor ? ` · ${e.actor}` : ''}</p></li>;
    })}</ol>;
}

export function TabLinks({ items }: { items: { label: string; href: string; active?: boolean }[] }) {
    return <nav className="flex flex-wrap gap-2">{items.map(i => <Link key={i.href} className={i.active ? primaryBtn : secondaryBtn} href={i.href}>{i.label}</Link>)}</nav>;
}
