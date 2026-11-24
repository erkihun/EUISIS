import { cx } from '@/Components/ui';
import { OrganizationPicker } from '@/modules/reporting/components/Pickers';
import { errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { Bilingual, OrganizationRef } from '@/modules/reporting/types';
import { useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

interface Target {
    organization: OrganizationRef;
    include_descendants: boolean;
}

/**
 * A special (one-off) report: its period and deadline are typed, nothing
 * recurs. Publishing creates one expected report for every institution
 * chosen, exactly as a scheduled period does.
 */
export default function SpecialReportForm({ reportTypes }: { reportTypes: ({ id: string } & Bilingual)[] }): JSX.Element {
    const { rt, name } = useReporting();
    const [open, setOpen] = useState(false);
    const [targets, setTargets] = useState<Target[]>([]);
    const [adding, setAdding] = useState<OrganizationRef | null>(null);
    const [withChildren, setWithChildren] = useState(false);
    const form = useForm({ report_type_id: '', period_label_am: '', period_label_en: '', period_start: '', period_end: '', opens_at: '', due_at: '', late_policy: 'accept' });
    const errors = form.errors as Record<string, string>;
    const publish = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, targets: targets.map((t) => ({ organization_id: t.organization.id, include_descendants: t.include_descendants })) }));
        form.post(route('reporting.schedules.special'), { preserveScroll: true, onSuccess: () => { form.reset(); setTargets([]); setOpen(false); } });
    };
    const text = (id: string, key: 'period_label_am' | 'period_label_en', label: string, lang: string) => (
        <div>
            <label htmlFor={id} className={labelCls}>{label}</label>
            <input id={id} lang={lang} type="text" className={inputCls} maxLength={200} required value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} />
            {errors[key] && <p className={errorCls} role="alert">{errors[key]}</p>}
        </div>
    );
    const when = (id: string, key: 'period_start' | 'period_end' | 'opens_at' | 'due_at', label: string, type: 'date' | 'datetime-local') => (
        <div>
            <label htmlFor={id} className={labelCls}>{label}</label>
            <input id={id} type={type} className={inputCls} required value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} />
            {errors[key] && <p className={errorCls} role="alert">{errors[key]}</p>}
        </div>
    );

    if (!open) {
        return (
            <section className={cx(panelCls, 'flex flex-wrap items-center justify-between gap-3 p-4')} aria-labelledby="sp-title">
                <div>
                    <h2 id="sp-title" className={sectionTitleCls}>{rt('special.title')}</h2>
                    <p className={cx(mutedCls, 'text-xs')}>{rt('special.description')}</p>
                </div>
                <button type="button" className={secondaryBtn} onClick={() => setOpen(true)}>{rt('special.open')}</button>
            </section>
        );
    }

    return (
        <form onSubmit={publish} className={cx(panelCls, 'space-y-4 p-4')} aria-labelledby="sp-title">
            <div>
                <h2 id="sp-title" className={sectionTitleCls}>{rt('special.title')}</h2>
                <p className={cx(mutedCls, 'text-xs')}>{rt('special.description')}</p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label htmlFor="sp-type" className={labelCls}>{rt('filters.reportType')}</label>
                    <select id="sp-type" className={inputCls} required value={form.data.report_type_id} onChange={(e) => form.setData('report_type_id', e.target.value)}>
                        <option value="">{rt('common.choose')}</option>
                        {reportTypes.map((type) => <option key={type.id} value={type.id}>{name(type)}</option>)}
                    </select>
                    {errors.report_type_id && <p className={errorCls} role="alert">{errors.report_type_id}</p>}
                </div>
                {text('sp-label-am', 'period_label_am', rt('special.labelAm'), 'am')}
                {text('sp-label-en', 'period_label_en', rt('special.labelEn'), 'en')}
                {when('sp-start', 'period_start', rt('special.periodStart'), 'date')}
                {when('sp-end', 'period_end', rt('special.periodEnd'), 'date')}
                <div>
                    <label htmlFor="sp-late" className={labelCls}>{rt('schedules.latePolicy')}</label>
                    <select id="sp-late" className={inputCls} value={form.data.late_policy} onChange={(e) => form.setData('late_policy', e.target.value)}>
                        {['accept', 'accept_with_reason', 'block'].map((value) => <option key={value} value={value}>{rt(`schedules.latePolicyOption.${value}`)}</option>)}
                    </select>
                </div>
                {when('sp-opens', 'opens_at', rt('special.opensAt'), 'datetime-local')}
                {when('sp-due', 'due_at', rt('special.dueAt'), 'datetime-local')}
                <p className={cx(mutedCls, 'self-end pb-2 text-xs')}>{rt('common.localTime')}</p>
            </div>

            <fieldset className="space-y-2">
                <legend className="text-sm font-semibold">{rt('schedules.sectionWho')}</legend>
                <div className="grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
                    <OrganizationPicker label={rt('schedules.addInstitution')} value={adding} onChange={setAdding} />
                    <label className="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" checked={withChildren} onChange={(e) => setWithChildren(e.target.checked)} />{rt('schedules.withDescendants')}</label>
                    <button type="button" className={secondaryBtn} disabled={adding === null || targets.some((t) => t.organization.id === adding.id)}
                        onClick={() => { if (adding) { setTargets([...targets, { organization: adding, include_descendants: withChildren }]); setAdding(null); setWithChildren(false); } }}>{rt('common.add')}</button>
                </div>
                {errors.targets && <p className={errorCls} role="alert">{errors.targets}</p>}
                {targets.length === 0 ? <p className={mutedCls}>{rt('schedules.noTargets')}</p> : (
                    <ul className="divide-y divide-(--app-border)">
                        {targets.map((target, index) => (
                            <li key={target.organization.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <span>{target.organization.code} · {name(target.organization)}{target.include_descendants ? ` (${rt('schedules.withDescendants')})` : ''}</span>
                                <button type="button" className={secondaryBtn} onClick={() => setTargets(targets.filter((_, i) => i !== index))}>{rt('common.remove')}</button>
                            </li>
                        ))}
                    </ul>
                )}
            </fieldset>

            <div className="flex flex-wrap gap-2">
                <button type="submit" className={primaryBtn} disabled={form.processing || targets.length === 0}>{rt('special.publish')}</button>
                <button type="button" className={secondaryBtn} onClick={() => setOpen(false)}>{rt('common.cancel')}</button>
            </div>
        </form>
    );
}
