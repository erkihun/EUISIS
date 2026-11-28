import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { cx } from '@/Components/ui';
import { Notice } from '@/modules/leave/components/Common';
import { errorCls, formatDays, inputCls, labelCls, mutedCls, panelCls, primaryBtn, sectionTitleCls, textareaCls, useLeave } from '@/modules/leave/lib/helpers';
import type { EmployeeRef, LeaveSummary, TypeSummary } from '@/modules/leave/types';
import { useForm } from '@inertiajs/react';
import { useMemo, type FormEvent, type JSX, type ReactNode } from 'react';

interface Values {
    leave_type_id: string;
    starts_on: string;
    ends_on: string;
    is_half_day: boolean;
    half_day_part: 'morning' | 'afternoon';
    expected_delivery_on: string;
    reason: string;
    contact_during_leave: string;
    /** The planned period of the leave schedule this request is made from, if any. */
    plan_id: string;
    documents: File[];
}

function addDays(iso: string, days: number): string {
    const date = new Date(`${iso}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

/** Calendar days of a period, and its Monday-to-Friday days as a first estimate of the working days. */
function spanOf(from: string, to: string): { calendar: number; weekdays: number } | null {
    if (!from || !to || to < from) return null;
    let calendar = 0;
    let weekdays = 0;
    for (let day = from; day <= to && calendar < 400; day = addDays(day, 1)) {
        calendar += 1;
        const weekday = new Date(`${day}T00:00:00Z`).getUTCDay();
        if (weekday !== 0 && weekday !== 6) weekdays += 1;
    }

    return { calendar, weekdays };
}

/**
 * The leave request form: one form, top to bottom. What the chosen leave
 * type allows and what the request will take is shown as it is filled. The
 * server counts the days and applies the rules: the figures here only help
 * the person choose. Used by the request page and the popup on My leave.
 * Only the employee asks, signed in with their own account.
 */
export function LeaveRequestForm({ employee, summary, today, cancel, stay = false, onSuccess, initial }: {
    /** A period to start from: one the employee planned in their leave schedule. */
    initial?: { plan_id: string; leave_type_id: string; starts_on: string; ends_on: string };
    employee: EmployeeRef | null;
    summary: LeaveSummary;
    today: string;
    /** The "cancel" control: a link back on a page, a close button in a popup. */
    cancel: ReactNode;
    /** Stay on the page that opened the form instead of opening the new request. */
    stay?: boolean;
    onSuccess?: () => void;
}): JSX.Element {
    const { lt, name, dayCount } = useLeave();
    const types = useMemo(() => (summary.types).filter((type) => type.has_rule && type.employee_can_request), [summary]);
    const form = useForm<Values>({
        leave_type_id: initial?.leave_type_id ?? '', starts_on: initial?.starts_on ?? '', ends_on: initial?.ends_on ?? '',
        is_half_day: false, half_day_part: 'morning', expected_delivery_on: '', reason: '', contact_during_leave: '', plan_id: initial?.plan_id ?? '', documents: [],
    });
    const d = form.data;
    const type: TypeSummary | undefined = types.find((candidate) => candidate.id === d.leave_type_id);
    const maternity = type?.category === 'maternity';
    const prenatal = type?.settings?.prenatal_days ?? null;
    const postnatal = type?.settings?.postnatal_days ?? null;
    // Maternity with an expected date: the last day is set by the law, not typed.
    const plannedEnd = maternity && d.expected_delivery_on && postnatal !== null ? addDays(d.expected_delivery_on, postnatal - 1) : '';
    // Half days may run over several days; with no last day chosen it is the first day alone.
    const endsOn = plannedEnd || d.ends_on || (d.is_half_day ? d.starts_on : '');
    const span = spanOf(d.starts_on, endsOn);
    const counted = span === null ? null : type?.day_unit === 'working' ? span.weekdays : span.calendar;
    const asked = counted === null ? null : d.is_half_day ? counted / 2 : counted;
    const free = type?.uses_balance ? (type.free ?? 0) : null;
    const error = (field: keyof Values | 'employee' | 'documents.0') => (form.errors as Record<string, string>)[field] ? <p className={errorCls}>{(form.errors as Record<string, string>)[field]}</p> : null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, ends_on: plannedEnd || data.ends_on || (data.is_half_day ? data.starts_on : ''), ...(stay ? { stay: 1 } : {}) }));
        form.post(route('employee.leave.store'), { forceFormData: true, preserveScroll: true, onSuccess });
    };

    return (
        <form onSubmit={submit} className="space-y-5">
                {!summary.has_rules && <Notice tone="warning">{lt('balance.noRulesAtAll')}</Notice>}
                <section className={cx(panelCls, 'space-y-4 p-5')}>
                    <h2 className={sectionTitleCls}>{lt('form.what')}</h2>
                    <div>
                        <label htmlFor="lv-type" className={labelCls}>{lt('form.type')}<span className="text-red-600"> *</span></label>
                        <select id="lv-type" className={inputCls} value={d.leave_type_id} required
                            onChange={(e) => form.setData((data) => ({ ...data, leave_type_id: e.target.value, is_half_day: false, expected_delivery_on: '' }))}>
                            <option value="">{lt('form.chooseType')}</option>
                            {types.map((option) => <option key={option.id} value={option.id}>{name(option)}</option>)}
                        </select>
                        {error('leave_type_id')}
                    </div>

                    {type && (
                        <div className="rounded-control border border-(--app-border) bg-(--app-surface-muted,transparent) px-3 py-2 text-sm">
                            <ul className="space-y-1 text-(--app-foreground)">
                                {free !== null && <li>{lt('form.info.free', { days: dayCount(free, type.day_unit) })}</li>}
                                <li>{lt(type.day_unit === 'working' ? 'form.info.unitWorking' : 'form.info.unitCalendar')}</li>
                                {type.max_days_per_request !== null && <li>{lt('balance.limitPerRequest', { days: dayCount(type.max_days_per_request, type.day_unit) })}</li>}
                                {type.max_days_per_year !== null && <li>{lt('balance.limitPerYear', { days: dayCount(type.max_days_per_year, type.day_unit) })}</li>}
                                {maternity && prenatal !== null && postnatal !== null && <li>{lt('form.info.maternity', { prenatal, postnatal })}</li>}
                                <li>{lt(`pay.${type.pay_class}`)}</li>
                                {type.requires_document && <li>{lt('form.info.documentRequired')}</li>}
                                {type.category === 'sick' && type.settings?.certificate_from_days !== undefined && <li>{lt('form.info.certificateFrom', { days: type.settings.certificate_from_days })}</li>}
                                {type.carry_over_mode === 'none' && <li>{lt('balance.notCarried')}</li>}
                            </ul>
                        </div>
                    )}

                    {type?.allows_half_day && (
                        <div className="flex flex-wrap items-center gap-4">
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={d.is_half_day} onChange={(e) => form.setData('is_half_day', e.target.checked)} />
                                {lt('form.halfDay')}
                            </label>
                            {d.is_half_day && (
                                <select aria-label={lt('form.halfDayPart')} className={cx(inputCls, 'max-w-48')} value={d.half_day_part} onChange={(e) => form.setData('half_day_part', e.target.value as Values['half_day_part'])}>
                                    <option value="morning">{lt('common.half.morning')}</option>
                                    <option value="afternoon">{lt('common.half.afternoon')}</option>
                                </select>
                            )}
                            {error('is_half_day')}
                        </div>
                    )}

                    {maternity && (
                        <div>
                            <span className={labelCls}>{lt('form.expectedDelivery')}</span>
                            <LocalizedDatePicker value={d.expected_delivery_on} onChange={(v) => form.setData((data) => ({ ...data, expected_delivery_on: v, starts_on: v && prenatal !== null && !data.starts_on ? addDays(v, -prenatal) : data.starts_on }))} />
                            <p className={cx(mutedCls, 'mt-1 text-xs')}>{lt('form.expectedDeliveryOptional')}</p>
                            {error('expected_delivery_on')}
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <span className={labelCls}>{lt('form.startsOn')}<span className="text-red-600"> *</span></span>
                            <LocalizedDatePicker value={d.starts_on} min={type && ['sick', 'maternity', 'paternity'].includes(type.category) ? undefined : today} onChange={(v) => form.setData('starts_on', v)} />
                            {error('starts_on')}
                        </div>
                        {(
                            <div>
                                <span className={labelCls}>{lt('form.endsOn')}{!d.is_half_day && <span className="text-red-600"> *</span>}</span>
                                {plannedEnd ? <p className="pt-2 text-sm font-medium text-(--app-foreground)"><LocalizedDateDisplay value={plannedEnd} /> <span className={cx(mutedCls, 'text-xs font-normal')}>{lt('form.setByLaw')}</span></p>
                                    : <LocalizedDatePicker value={d.ends_on} min={d.starts_on || undefined} onChange={(v) => form.setData('ends_on', v)} />}
                                {error('ends_on')}
                            </div>
                        )}
                    </div>

                    {type && asked !== null && (
                        <div className={cx('rounded-control border px-3 py-2 text-sm', free !== null && asked > free ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100' : 'border-(--app-border) text-(--app-foreground)')} role="status">
                            <p className="font-medium">{lt(type.day_unit === 'working' ? 'form.result.aboutWorking' : 'form.result.days', { days: formatDays(asked) })}</p>
                            {free !== null && <p className="text-xs">{asked > free ? lt('form.result.notEnough', { free: formatDays(free) }) : lt('form.result.remaining', { left: formatDays(free - asked) })}</p>}
                            {d.is_half_day && <p className="text-xs">{lt('form.result.halfEachDay')}</p>}
                            {type.day_unit === 'working' && <p className={cx(mutedCls, 'text-xs')}>{lt('form.result.holidayNote')}</p>}
                        </div>
                    )}
                </section>

                <section className={cx(panelCls, 'space-y-4 p-5')}>
                    <h2 className={sectionTitleCls}>{lt('form.details')}</h2>
                    <div>
                        <label htmlFor="lv-reason" className={labelCls}>{lt('form.reason')}</label>
                        <textarea id="lv-reason" rows={3} maxLength={2000} className={textareaCls} value={d.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                        {(type?.category === 'sick' || maternity) && <p className={cx(mutedCls, 'mt-1 text-xs')}>{lt('form.reasonPrivate')}</p>}
                        {error('reason')}
                    </div>
                    <div>
                        <label htmlFor="lv-contact" className={labelCls}>{lt('form.contact')}</label>
                        <input id="lv-contact" type="text" maxLength={255} className={inputCls} value={d.contact_during_leave} onChange={(e) => form.setData('contact_during_leave', e.target.value)} />
                        {error('contact_during_leave')}
                    </div>
                    <div>
                        <label htmlFor="lv-docs" className={labelCls}>{lt('form.documents')}</label>
                        <input id="lv-docs" type="file" multiple accept=".pdf,.jpg,.jpeg,.png" className="block w-full text-sm" onChange={(e) => form.setData('documents', Array.from(e.target.files ?? []).slice(0, 5))} />
                        <p className={cx(mutedCls, 'mt-1 text-xs')}>{lt('form.documentsHelp')}</p>
                        {error('documents.0')}
                    </div>
                </section>

                <div className="flex flex-wrap justify-end gap-3">
                    {cancel}
                    <button type="submit" className={primaryBtn} disabled={form.processing || !type}>{lt('form.submit')}</button>
                </div>
        </form>
    );
}
