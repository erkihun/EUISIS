import ConfirmationDialog from '@/Components/ConfirmationDialog';
import PageHeader from '@/Components/PageHeader';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { When } from '@/modules/reporting/components/Badges';
import { ActionErrors, Notice } from '@/modules/reporting/components/Notices';
import { OrganizationPicker, PersonPicker } from '@/modules/reporting/components/Pickers';
import { errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, serverMessage, useReporting } from '@/modules/reporting/lib/helpers';
import type { Bilingual, Labelled, OrganizationRef, PersonRef, UnitRef } from '@/modules/reporting/types';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type JSX } from 'react';

interface Target {
    organization: OrganizationRef;
    /** Set when the work unit, not the whole institution, owes the report. */
    unit?: UnitRef | null;
    person: PersonRef | null;
    preparer: PersonRef | null;
    accountable: PersonRef | null;
    include_descendants?: boolean;
}

interface ReminderRules {
    days_before: number[];
    on_due_date: boolean;
    when_overdue: boolean;
    escalate_after_working_days: number | null;
}

interface PreviewRow extends Labelled {
    key: string;
    start: string;
    end: string;
    start_ethiopian: string;
    end_ethiopian: string;
    opens_at: string;
    due_at: string;
}

interface Props {
    schedule: null | {
        id: string; report_type_id: string; name_en: string; name_am: string; frequency: string; calendar_basis: string; fiscal_year_start_month: number;
        due_offset_days: number; opens_offset_days: number; due_time: string; timezone: string; holiday_adjustment: string; review_sla_working_days: number;
        revision_days: number; late_policy: string; receiving_organization_id: string | null; is_active: boolean; starts_on: string; ends_on: string | null; reminder_rules: ReminderRules;
    };
    targets: Target[];
    receiving: OrganizationRef | null;
    reviewers: PersonRef[];
    report_types: ({ id: string; frequency: string } & Bilingual)[];
    default_reminders: ReminderRules;
    ethiopian_months: { en: Record<number, string>; am: Record<number, string> };
}

const GREGORIAN_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/**
 * A schedule: which report, on which calendar, when it opens and falls due,
 * who must report and who reviews. Before anything is saved or published the
 * page shows the next periods with their exact opening and deadline moments,
 * in both calendars, so a wrong offset is seen before it reaches an institution.
 */
export default function ScheduleForm({ schedule, targets: initialTargets, receiving, reviewers: initialReviewers, report_types, default_reminders, ethiopian_months }: Props): JSX.Element {
    const { rt, name, label, moment, am } = useReporting();
    const today = new Date().toISOString().slice(0, 10);
    const form = useForm({
        report_type_id: schedule?.report_type_id ?? '', name_en: schedule?.name_en ?? '', name_am: schedule?.name_am ?? '',
        frequency: schedule?.frequency ?? 'monthly', calendar_basis: schedule?.calendar_basis ?? 'ethiopian', fiscal_year_start_month: schedule?.fiscal_year_start_month ?? 11,
        due_offset_days: schedule?.due_offset_days ?? 5, opens_offset_days: schedule?.opens_offset_days ?? -7, due_time: schedule?.due_time ?? '17:30',
        timezone: schedule?.timezone ?? 'Africa/Addis_Ababa', holiday_adjustment: schedule?.holiday_adjustment ?? 'next_working_day',
        review_sla_working_days: schedule?.review_sla_working_days ?? 5, revision_days: schedule?.revision_days ?? 5, late_policy: schedule?.late_policy ?? 'accept',
        starts_on: schedule?.starts_on ?? today, ends_on: schedule?.ends_on ?? '', is_active: schedule?.is_active ?? true,
        reminder_rules: schedule?.reminder_rules ?? default_reminders,
    });
    const [targets, setTargets] = useState<Target[]>(initialTargets);
    const [receivingOrg, setReceivingOrg] = useState<OrganizationRef | null>(receiving);
    const [reviewers, setReviewers] = useState<PersonRef[]>(initialReviewers);
    const [adding, setAdding] = useState<OrganizationRef | null>(null);
    const [withChildren, setWithChildren] = useState(false);
    // The work units of the institution being added; '' means the institution as a whole.
    const [units, setUnits] = useState<UnitRef[]>([]);
    const [addingUnit, setAddingUnit] = useState('');
    useEffect(() => {
        setUnits([]);
        setAddingUnit('');
        if (!adding) return;
        let current = true;
        window.axios.get(route('reporting.lookups.units'), { params: { organization_id: adding.id } })
            .then((response: { data: { items: UnitRef[] } }) => { if (current) setUnits(response.data.items); })
            .catch(() => undefined);

        return () => { current = false; };
    }, [adding?.id]);
    const chosenUnit = units.find((unit) => unit.id === addingUnit) ?? null;
    const alreadyAdded = adding !== null && targets.some((t) => t.organization.id === adding.id && t.person === null && (t.unit?.id ?? '') === addingUnit);
    const [preview, setPreview] = useState<PreviewRow[]>([]);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [publish, setPublish] = useState<PreviewRow | null>(null);
    const errors = form.errors as Record<string, string>;
    const months = form.data.calendar_basis === 'ethiopian' ? Object.entries(am ? ethiopian_months.am : ethiopian_months.en) : GREGORIAN_MONTHS.map((month, index) => [String(index + 1), month]);

    // The preview follows the rules as they are typed.
    const { frequency, calendar_basis, fiscal_year_start_month, due_offset_days, opens_offset_days, due_time, timezone, holiday_adjustment, starts_on } = form.data;
    useEffect(() => {
        const timer = setTimeout(() => {
            window.axios.post(route('reporting.schedules.preview'), { frequency, calendar_basis, fiscal_year_start_month, due_offset_days, opens_offset_days, due_time, timezone, holiday_adjustment, starts_on })
                .then((response: { data: { periods: PreviewRow[] } }) => { setPreview(response.data.periods); setPreviewError(null); })
                .catch((error: unknown) => { setPreview([]); setPreviewError(serverMessage(error, rt('schedules.previewFailed'))); });
        }, 400);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [frequency, calendar_basis, fiscal_year_start_month, due_offset_days, opens_offset_days, due_time, timezone, holiday_adjustment, starts_on]);

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data, ends_on: data.ends_on || null, receiving_organization_id: receivingOrg?.id ?? null, reviewer_user_ids: reviewers.map((r) => r.id),
            targets: targets.map((t) => ({ organization_id: t.organization.id, organization_unit_id: t.unit?.id ?? null, person_user_id: t.person?.id ?? null, preparer_user_id: t.preparer?.id ?? null, accountable_user_id: t.accountable?.id ?? null, include_descendants: t.include_descendants ?? false })),
        }));
        if (schedule) form.put(route('reporting.schedules.update', schedule.id), { preserveScroll: true });
        else form.post(route('reporting.schedules.store'));
    };
    const number = (id: string, key: 'due_offset_days' | 'opens_offset_days' | 'review_sla_working_days' | 'revision_days', text: string, help?: string) => (
        <div>
            <label htmlFor={id} className={labelCls}>{text}</label>
            <input id={id} type="number" className={inputCls} value={form.data[key]} onChange={(e) => form.setData(key, Number(e.target.value))} aria-describedby={help ? `${id}-help` : undefined} />
            {help && <p id={`${id}-help`} className={cx(mutedCls, 'mt-1 text-xs')}>{help}</p>}
            {errors[key] && <p className={errorCls} role="alert">{errors[key]}</p>}
        </div>
    );
    const rules = form.data.reminder_rules;
    const setRules = (patch: Partial<ReminderRules>) => form.setData('reminder_rules', { ...rules, ...patch });

    return (
        <AuthenticatedLayout header={<PageHeader title={schedule ? rt('schedules.edit') : rt('schedules.add')} description={schedule?.name_en} backHref={route('reporting.schedules.index')} />}>
            <Head title={schedule ? rt('schedules.edit') : rt('schedules.add')} />
            <ActionErrors except={Object.keys(errors)} />
            <form onSubmit={save} className="space-y-5 pb-4">
                <section className={cx(panelCls, 'grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3')} aria-labelledby="sf-what">
                    <h2 id="sf-what" className={cx(sectionTitleCls, 'sm:col-span-2 lg:col-span-3')}>{rt('schedules.sectionWhat')}</h2>
                    <div>
                        <label htmlFor="sf-type" className={labelCls}>{rt('filters.reportType')}</label>
                        <select id="sf-type" className={inputCls} required value={form.data.report_type_id} onChange={(e) => { const type = report_types.find((t) => t.id === e.target.value); form.setData((d) => ({ ...d, report_type_id: e.target.value, frequency: type && type.frequency !== 'ad_hoc' ? type.frequency : d.frequency })); }}>
                            <option value="">{rt('common.choose')}</option>
                            {report_types.map((type) => <option key={type.id} value={type.id}>{name(type)}</option>)}
                        </select>
                        {errors.report_type_id && <p className={errorCls} role="alert">{errors.report_type_id}</p>}
                    </div>
                    <div>
                        <label htmlFor="sf-name-am" className={labelCls}>{rt('schedules.nameAm')}</label>
                        <input id="sf-name-am" lang="am" type="text" className={inputCls} required maxLength={200} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />
                    </div>
                    <div>
                        <label htmlFor="sf-name-en" className={labelCls}>{rt('schedules.nameEn')}</label>
                        <input id="sf-name-en" lang="en" type="text" className={inputCls} required maxLength={200} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} />
                    </div>
                </section>

                <section className={cx(panelCls, 'space-y-3 p-4')} aria-labelledby="sf-when">
                    <h2 id="sf-when" className={sectionTitleCls}>{rt('schedules.sectionWhen')}</h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label htmlFor="sf-freq" className={labelCls}>{rt('types.frequency')}</label>
                            <select id="sf-freq" className={inputCls} value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)}>
                                {['weekly', 'monthly', 'quarterly', 'semiannual', 'annual'].map((value) => <option key={value} value={value}>{rt(`frequency.${value}`)}</option>)}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="sf-basis" className={labelCls}>{rt('schedules.calendar')}</label>
                            <select id="sf-basis" className={inputCls} value={form.data.calendar_basis} onChange={(e) => form.setData((d) => ({ ...d, calendar_basis: e.target.value, fiscal_year_start_month: e.target.value === 'ethiopian' ? 11 : 7 }))} aria-describedby="sf-basis-help">
                                <option value="ethiopian">{rt('calendar.basis.ethiopian')}</option>
                                <option value="gregorian">{rt('calendar.basis.gregorian')}</option>
                            </select>
                            <p id="sf-basis-help" className={cx(mutedCls, 'mt-1 text-xs')}>{rt('schedules.calendarHelp')}</p>
                        </div>
                        <div>
                            <label htmlFor="sf-fiscal" className={labelCls}>{rt('schedules.fiscalStart')}</label>
                            <select id="sf-fiscal" className={inputCls} value={form.data.fiscal_year_start_month} disabled={form.data.frequency === 'weekly' || form.data.frequency === 'monthly'} onChange={(e) => form.setData('fiscal_year_start_month', Number(e.target.value))}>
                                {months.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="sf-start" className={labelCls}>{rt('schedules.startsOn')}</label>
                            <input id="sf-start" type="date" className={inputCls} required value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} />
                            {errors.starts_on && <p className={errorCls} role="alert">{errors.starts_on}</p>}
                        </div>
                        {number('sf-opens', 'opens_offset_days', rt('schedules.opensOffset'), rt('schedules.opensOffsetHelp'))}
                        {number('sf-due', 'due_offset_days', rt('schedules.dueOffset'), rt('schedules.dueOffsetHelp'))}
                        <div>
                            <label htmlFor="sf-time" className={labelCls}>{rt('schedules.dueTime')}</label>
                            <input id="sf-time" type="time" className={inputCls} required value={form.data.due_time} onChange={(e) => form.setData('due_time', e.target.value)} aria-describedby="sf-time-help" />
                            <p id="sf-time-help" className={cx(mutedCls, 'mt-1 text-xs')}>{rt('common.localTime')}</p>
                            {errors.due_time && <p className={errorCls} role="alert">{errors.due_time}</p>}
                        </div>
                        <div>
                            <label htmlFor="sf-holiday" className={labelCls}>{rt('schedules.holiday')}</label>
                            <select id="sf-holiday" className={inputCls} value={form.data.holiday_adjustment} onChange={(e) => form.setData('holiday_adjustment', e.target.value)}>
                                {['next_working_day', 'previous_working_day', 'none'].map((value) => <option key={value} value={value}>{rt(`schedules.holidayOption.${value}`)}</option>)}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="sf-end" className={labelCls}>{rt('schedules.endsOn')} ({rt('common.optional')})</label>
                            <input id="sf-end" type="date" className={inputCls} value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} />
                            {errors.ends_on && <p className={errorCls} role="alert">{errors.ends_on}</p>}
                        </div>
                        <div>
                            <label htmlFor="sf-late" className={labelCls}>{rt('schedules.latePolicy')}</label>
                            <select id="sf-late" className={inputCls} value={form.data.late_policy} onChange={(e) => form.setData('late_policy', e.target.value)}>
                                {['accept', 'accept_with_reason', 'block'].map((value) => <option key={value} value={value}>{rt(`schedules.latePolicyOption.${value}`)}</option>)}
                            </select>
                        </div>
                    </div>

                    <div className="border-t border-(--app-border) pt-3" aria-live="polite">
                        <h3 className="text-sm font-semibold">{rt('schedules.preview')}</h3>
                        <p className={cx(mutedCls, 'text-xs')}>{rt('schedules.previewHelp')}</p>
                        {previewError && <p className={errorCls} role="alert">{previewError}</p>}
                        {preview.length > 0 && (
                            <div className="mt-2 overflow-x-auto">
                                <table className="w-full min-w-[52rem] text-sm">
                                    <thead>
                                        <tr className="border-b border-(--app-border) text-xs text-(--app-muted-foreground)">
                                            {['period', 'covers', 'opens', 'due'].map((column) => <th key={column} scope="col" className="px-2 py-1.5 text-start font-medium">{rt(`schedules.col.${column}`)}</th>)}
                                            {schedule && <th scope="col" className="px-2 py-1.5"><span className="sr-only">{rt('schedules.publishPeriod')}</span></th>}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-(--app-border)">
                                        {preview.map((row) => (
                                            <tr key={row.key}>
                                                <th scope="row" className="px-2 py-1.5 text-start font-medium">{label(row)}</th>
                                                <td className="px-2 py-1.5">{row.start_ethiopian} – {row.end_ethiopian}<span className={cx(mutedCls, 'block text-xs')}>{row.start} – {row.end}</span></td>
                                                <td className="px-2 py-1.5"><When iso={row.opens_at} /></td>
                                                <td className="px-2 py-1.5"><When iso={row.due_at} strong /></td>
                                                {schedule && <td className="px-2 py-1.5 text-end"><button type="button" className={secondaryBtn} disabled={form.isDirty} title={form.isDirty ? rt('consolidation.saveFirst') : undefined} onClick={() => setPublish(row)}>{rt('schedules.publishPeriod')}</button></td>}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <p className={cx(mutedCls, 'mt-2 text-xs')}>{rt('schedules.autoPublishNote')}</p>
                    </div>
                </section>

                <section className={cx(panelCls, 'space-y-3 p-4')} aria-labelledby="sf-who">
                    <h2 id="sf-who" className={sectionTitleCls}>{rt('schedules.sectionWho')}</h2>
                    <Notice>{rt('schedules.obligationNote')}</Notice>
                    <div className="grid items-end gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto_auto]">
                        <OrganizationPicker label={rt('schedules.addInstitution')} value={adding} onChange={setAdding} />
                        <div>
                            <label htmlFor="sf-unit" className={labelCls}>{rt('schedules.whoReports')}</label>
                            <select id="sf-unit" className={inputCls} value={addingUnit} disabled={adding === null} onChange={(e) => { setAddingUnit(e.target.value); setWithChildren(false); }} aria-describedby="sf-unit-help">
                                <option value="">{rt('schedules.wholeInstitution')}</option>
                                {units.map((unit) => <option key={unit.id} value={unit.id}>{unit.code ? `${unit.code} · ` : ''}{name(unit)}</option>)}
                            </select>
                        </div>
                        <label className="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" checked={withChildren} disabled={addingUnit !== ''} onChange={(e) => setWithChildren(e.target.checked)} />{rt('schedules.withDescendants')}</label>
                        <button type="button" className={secondaryBtn} disabled={adding === null || alreadyAdded}
                            onClick={() => { if (adding) { setTargets([...targets, { organization: adding, unit: chosenUnit, person: null, preparer: null, accountable: null, include_descendants: chosenUnit === null && withChildren }]); if (chosenUnit && receivingOrg === null) setReceivingOrg(adding); setAdding(null); setWithChildren(false); } }}>{rt('common.add')}</button>
                    </div>
                    <p id="sf-unit-help" className={cx(mutedCls, 'text-xs')}>{rt('schedules.unitHelp')}</p>
                    {errors.targets && <p className={errorCls} role="alert">{errors.targets}</p>}
                    {targets.length === 0 ? <p className={mutedCls}>{rt('schedules.noTargets')}</p> : (
                        <ul className="divide-y divide-(--app-border)">
                            {targets.map((target, index) => (
                                <li key={`${target.organization.id}-${target.unit?.id ?? target.person?.id ?? 'org'}`} className="space-y-2 py-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-sm font-medium">{target.organization.code} · {name(target.organization)}{target.unit ? ` — ${name(target.unit)}` : ''}{target.include_descendants ? ` (${rt('schedules.withDescendants')})` : ''}</p>
                                        <button type="button" className={secondaryBtn} onClick={() => setTargets(targets.filter((_, i) => i !== index))}>{rt('common.remove')}</button>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <PersonPicker label={rt('show.accountable')} value={target.accountable} onChange={(person) => setTargets(targets.map((t, i) => (i === index ? { ...t, accountable: person } : t)))} />
                                        <PersonPicker label={rt('show.preparer')} value={target.preparer} onChange={(person) => setTargets(targets.map((t, i) => (i === index ? { ...t, preparer: person } : t)))} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className={cx(panelCls, 'space-y-3 p-4')} aria-labelledby="sf-review">
                    <h2 id="sf-review" className={sectionTitleCls}>{rt('schedules.sectionReview')}</h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <OrganizationPicker label={rt('types.receiving')} value={receivingOrg} onChange={setReceivingOrg} help={rt('schedules.receivingHelp')} error={errors.receiving_organization_id} />
                        {number('sf-sla', 'review_sla_working_days', rt('schedules.reviewSla'))}
                        {number('sf-rev', 'revision_days', rt('schedules.revisionDays'))}
                    </div>
                    <div>
                        <p className={labelCls}>{rt('schedules.reviewers')}</p>
                        <p className={cx(mutedCls, 'text-xs')}>{rt('schedules.reviewersHelp')}</p>
                        <ol className="mt-1 list-decimal space-y-1 ps-5 text-sm">
                            {reviewers.map((reviewer, index) => (
                                <li key={reviewer.id}>{reviewer.name} <button type="button" className="ms-2 text-xs text-primary hover:underline" onClick={() => setReviewers(reviewers.filter((_, i) => i !== index))}>{rt('common.remove')}</button></li>
                            ))}
                        </ol>
                        {reviewers.length < 6 && <div className="mt-2 max-w-md"><PersonPicker label={rt('schedules.addReviewer')} value={null} onChange={(person) => person && !reviewers.some((r) => r.id === person.id) && setReviewers([...reviewers, person])} /></div>}
                    </div>
                </section>

                <section className={cx(panelCls, 'space-y-3 p-4')} aria-labelledby="sf-reminders">
                    <h2 id="sf-reminders" className={sectionTitleCls}>{rt('schedules.sectionReminders')}</h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label htmlFor="sf-days" className={labelCls}>{rt('settings.daysBefore')}</label>
                            <input id="sf-days" type="text" inputMode="numeric" className={inputCls} value={rules.days_before.join(', ')} onChange={(e) => setRules({ days_before: e.target.value.split(/[^0-9]+/).filter(Boolean).map(Number).slice(0, 6) })} aria-describedby="sf-days-help" />
                            <p id="sf-days-help" className={cx(mutedCls, 'mt-1 text-xs')}>{rt('settings.daysBeforeHelp')}</p>
                        </div>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={rules.on_due_date} onChange={(e) => setRules({ on_due_date: e.target.checked })} />{rt('settings.onDueDate')}</label>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={rules.when_overdue} onChange={(e) => setRules({ when_overdue: e.target.checked })} />{rt('settings.whenOverdue')}</label>
                        <div>
                            <label htmlFor="sf-esc" className={labelCls}>{rt('settings.escalateAfter')}</label>
                            <input id="sf-esc" type="number" min={1} max={30} className={inputCls} value={rules.escalate_after_working_days ?? ''} onChange={(e) => setRules({ escalate_after_working_days: e.target.value === '' ? null : Number(e.target.value) })} />
                        </div>
                    </div>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />{rt('schedules.active')}</label>
                </section>

                <button type="submit" className={primaryBtn} disabled={form.processing || targets.length === 0}>{rt('common.save')}</button>
            </form>

            <ConfirmationDialog
                open={publish !== null} onClose={() => setPublish(null)}
                title={rt('schedules.publishTitle', { period: publish ? label(publish) : '' })}
                description={rt('schedules.publishDescription', { count: targets.length, due: publish ? moment(publish.due_at) : '' })}
                confirmLabel={rt('schedules.publishPeriod')}
                onConfirm={() => schedule && publish && router.post(route('reporting.schedules.publish', schedule.id), { date: publish.start }, { preserveScroll: true, onFinish: () => setPublish(null) })}
            />
        </AuthenticatedLayout>
    );
}
