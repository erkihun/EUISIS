import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { dangerBtn, errorCls, inputCls, labelCls, panelCls, primaryBtn, secondaryBtn, useSm } from '@/Components/serviceMonitoring/helpers';
import type { DigitalServiceRow, FormDescription, NamedWithId, ServiceLevel, TechnologyType } from '@/types/serviceMonitoring';
import { router, useForm } from '@inertiajs/react';
import { useId, useState, type FormEvent, type JSX } from 'react';

const TECHNOLOGIES: TechnologyType[] = ['web_system', 'mobile_app', 'ussd_sms', 'kiosk', 'api_integration', 'other'];

type ServiceForm = {
    level: ServiceLevel; parent_entry_id: string; service_id: string; service_name_am: string; service_name_en: string;
    technology_type: TechnologyType; technology_other: string; system_name: string; system_count: string; go_live_on: string; remark: string;
};

interface Props {
    submissionId: string;
    definition: FormDescription;
    totals: { main_total: number | null; sub_total: number | null; catalog_main_total: number };
    services: DigitalServiceRow[];
    catalog: NamedWithId[];
    fiscalYear: { from: string; to: string };
    /** The digital services list: false once Section III is marked "None". */
    editable: boolean;
    /** The institution's totals stay required with "None" (Section IV checks against them), so they follow the report, not the "None" mark. */
    totalsEditable: boolean;
}

/** Section III (D20): the institution's service totals and its digital services as a main → sub tree; only verified rows count. */
export default function Section3Panel({ submissionId, definition, totals, services, catalog, fiscalYear, editable, totalsEditable }: Props): JSX.Element {
    const { sm, name, locale } = useSm();
    const calendar = useCalendarSystem();
    const id = useId();
    const [editing, setEditing] = useState<{ row: DigitalServiceRow | null; parent: string | null } | null>(null);
    const totalsForm = useForm({ main_total: totals.main_total === null ? '' : String(totals.main_total), sub_total: totals.sub_total === null ? '' : String(totals.sub_total) });
    const form = useForm<ServiceForm>({ level: 'main', parent_entry_id: '', service_id: '', service_name_am: '', service_name_en: '', technology_type: 'web_system', technology_other: '', system_name: '', system_count: '1', go_live_on: fiscalYear.from, remark: '' });
    const columns = definition.section_3.columns;
    const mains = services.filter((s) => s.level === 'main');

    function open(row: DigitalServiceRow | null, parent: string | null) {
        form.clearErrors();
        form.setData(row ? {
            level: row.level, parent_entry_id: row.parent_entry_id ?? '', service_id: row.service_id ?? '', service_name_am: row.service_name_am ?? '', service_name_en: row.service_name_en ?? '',
            technology_type: row.technology_type, technology_other: row.technology_other ?? '', system_name: row.system_name, system_count: String(row.system_count), go_live_on: row.go_live_on, remark: row.remark ?? '',
        } : { level: parent ? 'sub' : 'main', parent_entry_id: parent ?? '', service_id: '', service_name_am: '', service_name_en: '', technology_type: 'web_system', technology_other: '', system_name: '', system_count: '1', go_live_on: fiscalYear.from, remark: '' });
        setEditing({ row, parent });
    }

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, service_id: d.service_id || null, parent_entry_id: d.level === 'sub' ? d.parent_entry_id : null, system_count: Number(d.system_count) }));
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing?.row) form.put(route('service-monitoring.submissions.section3.update', [submissionId, editing.row.id]), options);
        else form.post(route('service-monitoring.submissions.section3.store', submissionId), options);
    }

    function saveTotals(event: FormEvent) {
        event.preventDefault();
        totalsForm.transform((d) => ({ main_total: d.main_total === '' ? null : Number(d.main_total), sub_total: d.sub_total === '' ? null : Number(d.sub_total) }));
        totalsForm.put(route('service-monitoring.submissions.section4', submissionId), { preserveScroll: true, onSuccess: () => totalsForm.setDefaults() });
    }

    const serviceName = (s: DigitalServiceRow) => name({ name_en: s.service_name_en, name_am: s.service_name_am });
    const row = (s: DigitalServiceRow) => (
        <li key={s.id} className={`flex flex-wrap items-start justify-between gap-2 py-2 ${s.level === 'sub' ? 'ms-6 border-s-2 border-(--app-border) ps-3' : ''}`}>
            <div className="min-w-0 text-sm">
                <p className="font-medium text-(--app-foreground)">{serviceName(s)}{s.is_catalog_gap && <span className="ms-2 rounded bg-amber-100 px-1.5 text-xs text-amber-900 dark:bg-amber-900 dark:text-amber-100">{sm('edit.section3.catalogGap')}</span>}</p>
                <p className="text-xs text-(--app-muted-foreground)">
                    {sm(`enums.technology_type.${s.technology_type}`)}{s.technology_other ? ` (${s.technology_other})` : ''} / {s.system_name} · {columns.system_count}: {s.system_count} · <LocalizedDateDisplay value={s.go_live_on} />
                </p>
                <p className="text-xs">{sm('edit.section3.verification')}: {sm(`enums.verification_status.${s.verification_status}`)}{s.rejection_reason ? ` — ${s.rejection_reason}` : ''}</p>
            </div>
            {editable && (
                <div className="flex flex-wrap gap-1">
                    {s.level === 'main' && <button type="button" className={secondaryBtn} onClick={() => open(null, s.id)}>{sm('edit.section3.addSub')}</button>}
                    <button type="button" className={secondaryBtn} onClick={() => open(s, s.parent_entry_id)}>{sm('common.edit')}</button>
                    <button type="button" className={dangerBtn} onClick={() => router.delete(route('service-monitoring.submissions.section3.destroy', [submissionId, s.id]), { preserveScroll: true })}>{sm('common.delete')}</button>
                </div>
            )}
        </li>
    );

    return (
        <div className="space-y-4">
            <p className="text-sm font-semibold text-(--app-foreground)">{definition.section_3.title}</p>
            {definition.section_3.notes.map((n, i) => <p key={i} className="text-xs text-(--app-muted-foreground)">{n}</p>)}

            <form onSubmit={saveTotals} className={`${panelCls} grid gap-3 p-3 sm:grid-cols-3`} aria-label={sm('edit.section3.totals')}>
                <div>
                    <label htmlFor={`${id}-main`} className={labelCls}>{columns.main_total}</label>
                    <input id={`${id}-main`} type="number" min={0} className={inputCls} disabled={!totalsEditable} value={totalsForm.data.main_total} onChange={(e) => totalsForm.setData('main_total', e.target.value)} />
                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{sm('edit.section3.catalogCount', { count: totals.catalog_main_total })}</p>
                </div>
                <div>
                    <label htmlFor={`${id}-sub`} className={labelCls}>{columns.sub_total}</label>
                    <input id={`${id}-sub`} type="number" min={0} className={inputCls} disabled={!totalsEditable} value={totalsForm.data.sub_total} onChange={(e) => totalsForm.setData('sub_total', e.target.value)} />
                </div>
                {totalsEditable && <div className="flex items-end"><button type="submit" className={primaryBtn} disabled={totalsForm.processing || !totalsForm.isDirty}>{sm('common.save')}</button></div>}
            </form>

            <section aria-labelledby={`${id}-services`} className={`${panelCls} p-3`}>
                <h3 id={`${id}-services`} className="text-sm font-semibold">{columns.service_name}</h3>
                {mains.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{sm('common.empty')}</p> : (
                    <ul className="divide-y divide-(--app-border)">
                        {mains.flatMap((m) => [row(m), ...services.filter((s) => s.parent_entry_id === m.id).map(row)])}
                    </ul>
                )}
                {editable && editing === null && <button type="button" className={`${primaryBtn} mt-2`} onClick={() => open(null, null)}>{sm('edit.section3.add')}</button>}
            </section>

            {editing !== null && (
                <form onSubmit={save} className={`${panelCls} grid gap-3 p-4 sm:grid-cols-2`}>
                    {form.data.level === 'sub' && <p className="text-sm sm:col-span-2">{sm('enums.service_level.sub')}: {serviceName(mains.find((m) => m.id === form.data.parent_entry_id) ?? mains[0])}</p>}
                    <div className="sm:col-span-2">
                        <label htmlFor={`${id}-catalog`} className={labelCls}>{sm('edit.section3.catalogService')}</label>
                        <select id={`${id}-catalog`} className={inputCls} value={form.data.service_id} onChange={(e) => form.setData('service_id', e.target.value)}>
                            <option value="">{sm('edit.section3.freeText')}</option>
                            {catalog.map((c) => <option key={c.id} value={c.id}>{name(c)}</option>)}
                        </select>
                        {form.errors.service_id && <p className={errorCls}>{form.errors.service_id}</p>}
                    </div>
                    {form.data.service_id === '' && (
                        <>
                            <div>
                                <label htmlFor={`${id}-nam`} className={labelCls}>{sm('edit.section3.nameAm')}</label>
                                <input id={`${id}-nam`} className={inputCls} value={form.data.service_name_am} onChange={(e) => form.setData('service_name_am', e.target.value)} required />
                                {form.errors.service_name_am && <p className={errorCls}>{form.errors.service_name_am}</p>}
                            </div>
                            <div>
                                <label htmlFor={`${id}-nen`} className={labelCls}>{sm('edit.section3.nameEn')}</label>
                                <input id={`${id}-nen`} className={inputCls} value={form.data.service_name_en} onChange={(e) => form.setData('service_name_en', e.target.value)} />
                            </div>
                        </>
                    )}
                    <div>
                        <label htmlFor={`${id}-tech`} className={labelCls}>{sm('edit.section3.technology')}</label>
                        <select id={`${id}-tech`} className={inputCls} value={form.data.technology_type} onChange={(e) => form.setData('technology_type', e.target.value as TechnologyType)}>
                            {TECHNOLOGIES.map((t) => <option key={t} value={t}>{sm(`enums.technology_type.${t}`)}</option>)}
                        </select>
                    </div>
                    {form.data.technology_type === 'other' && (
                        <div>
                            <label htmlFor={`${id}-other`} className={labelCls}>{sm('edit.section3.otherTechnology')}</label>
                            <input id={`${id}-other`} className={inputCls} value={form.data.technology_other} onChange={(e) => form.setData('technology_other', e.target.value)} required />
                        </div>
                    )}
                    <div>
                        <label htmlFor={`${id}-sys`} className={labelCls}>{sm('edit.section3.systemName')}</label>
                        <input id={`${id}-sys`} className={inputCls} value={form.data.system_name} onChange={(e) => form.setData('system_name', e.target.value)} required />
                    </div>
                    <div>
                        <label htmlFor={`${id}-count`} className={labelCls}>{sm('edit.section3.systemCount')}</label>
                        <input id={`${id}-count`} type="number" min={1} className={inputCls} value={form.data.system_count} onChange={(e) => form.setData('system_count', e.target.value)} required />
                    </div>
                    <div>
                        <label htmlFor={`${id}-live`} className={labelCls}>{sm('edit.section3.goLive')}</label>
                        <LocalizedDatePicker id={`${id}-live`} min={fiscalYear.from} max={fiscalYear.to} className={inputCls} value={form.data.go_live_on} onChange={(iso) => form.setData('go_live_on', iso)} required />
                        <p className="mt-1 text-xs text-(--app-muted-foreground)">{sm('edit.section3.goLiveHint', { from: formatDateDisplay(fiscalYear.from, calendar, locale), to: formatDateDisplay(fiscalYear.to, calendar, locale) })}</p>
                        {form.errors.go_live_on && <p className={errorCls}>{form.errors.go_live_on}</p>}
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor={`${id}-remark`} className={labelCls}>{columns.remark}</label>
                        <input id={`${id}-remark`} className={inputCls} value={form.data.remark} onChange={(e) => form.setData('remark', e.target.value)} />
                    </div>
                    {form.errors.parent_entry_id && <p className={`${errorCls} sm:col-span-2`}>{form.errors.parent_entry_id}</p>}
                    {(form.errors as Record<string, string>).section_3 && <p className={`${errorCls} sm:col-span-2`}>{(form.errors as Record<string, string>).section_3}</p>}
                    <div className="flex gap-2 sm:col-span-2">
                        <button type="submit" className={primaryBtn} disabled={form.processing}>{sm('common.save')}</button>
                        <button type="button" className={secondaryBtn} onClick={() => setEditing(null)}>{sm('common.cancel')}</button>
                    </div>
                </form>
            )}
        </div>
    );
}
