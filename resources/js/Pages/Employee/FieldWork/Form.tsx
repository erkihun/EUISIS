import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedTimePicker from '@/Components/Calendar/LocalizedTimePicker';
import SearchPicker from '@/Components/fieldWork/SearchPicker';
import { employeeName, inputCls, labelCls, named, panelCls, primaryBtn, secondaryBtn } from '@/Components/fieldWork/helpers';
import type { DestinationType, EmployeeRef, Named, Option } from '@/Components/fieldWork/types';
import { useLocale } from '@/hooks/useLocale';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type JSX, type ReactNode } from 'react';

type TypeOption = Option & { description_en: string | null; description_am: string | null };

type Values = {
    field_work_type_id: string;
    purpose: string;
    activity_description: string;
    destination_type: DestinationType;
    destination_organization_id: string;
    destination_organization_unit_id: string;
    external_organization_name: string;
    site_name: string;
    destination_address: string;
    contact_person: string;
    contact_phone: string;
    expected_latitude: string;
    expected_longitude: string;
    geofence_radius_m: string;
    start_date: string;
    start_time: string;
    return_date: string;
    return_time: string;
    participants: EmployeeRef[];
    destination_organization: Option | null;
};

type Props = {
    employee: EmployeeRef | null;
    placement: { organization: Named; organization_unit: Named; position: Named } | null;
    types: TypeOption[];
    destinationTypes: DestinationType[];
    canAddTeam: boolean;
    canSubmit: boolean;
    today: string;
    fieldWork: { id: string; reference_number: string; status: string; decision_reason: string | null } | null;
    values: Values | null;
};

/**
 * Create or correct a field work request. There is no employee, assignment,
 * status or supervisor field: the server takes the requester and placement
 * from the signed-in account and resolves the supervisor on submission.
 */
export default function FieldWorkForm({ employee, placement, types, destinationTypes, canAddTeam, canSubmit, today, fieldWork, values }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const form = useForm<Values & { action: 'draft' | 'submit'; participant_employee_ids: string[] }>({
        ...(values ?? {
            field_work_type_id: '', purpose: '', activity_description: '',
            destination_type: 'registered_organization', destination_organization_id: '', destination_organization_unit_id: '',
            external_organization_name: '', site_name: '', destination_address: '', contact_person: '', contact_phone: '',
            expected_latitude: '', expected_longitude: '', geofence_radius_m: '',
            start_date: today, start_time: '08:30', return_date: today, return_time: '12:30',
            participants: [], destination_organization: null,
        }),
        action: 'draft',
        participant_employee_ids: (values?.participants ?? []).map((p) => p.id),
    });
    const [units, setUnits] = useState<Option[]>([]);
    const d = form.data;
    const errors = form.errors as Record<string, string>;

    useEffect(() => {
        if (d.destination_type !== 'registered_organization' || !d.destination_organization_id) {
            setUnits([]);
            return;
        }
        window.axios.get<{ data: Option[] }>(route('employee.field-work.lookup.units', d.destination_organization_id))
            .then((response) => setUnits(response.data.data))
            .catch(() => setUnits([]));
    }, [d.destination_type, d.destination_organization_id]);

    function save(event: FormEvent, action: 'draft' | 'submit') {
        event.preventDefault();
        form.transform((data) => {
            const { participants, destination_organization: _org, ...rest } = data;
            return { ...rest, action, participant_employee_ids: participants.map((p) => p.id) };
        });
        if (fieldWork) form.put(route('employee.field-work.update', fieldWork.id), { preserveScroll: true });
        else form.post(route('employee.field-work.store'), { preserveScroll: true });
    }

    if (!employee || !placement) {
        return (
            <PortalPage title={t('fieldWork.form.createTitle')}>
                <p className={`${panelCls} p-4 text-sm text-gray-600 dark:text-slate-300`}>{t(employee ? 'fieldWork.noPlacement' : 'fieldWork.noEmployee')}</p>
            </PortalPage>
        );
    }

    const error = (key: string) => errors[key] && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{errors[key]}</p>;
    const text = (key: keyof Values, label: string, required = false, multiline = false, max = 255) => (
        <div>
            <label htmlFor={`fw-${key}`} className={labelCls}>{label}{required && <span className="text-red-600"> *</span>}</label>
            {multiline
                ? <textarea id={`fw-${key}`} rows={3} maxLength={max} className={inputCls} value={String(d[key] ?? '')} onChange={(e) => form.setData(key, e.target.value as never)} />
                : <input id={`fw-${key}`} maxLength={max} className={inputCls} value={String(d[key] ?? '')} onChange={(e) => form.setData(key, e.target.value as never)} />}
            {error(key)}
        </div>
    );
    const section = (title: string, children: ReactNode) => (
        <fieldset className={`${panelCls} grid gap-3 p-4 sm:grid-cols-2`}>
            <legend className="sr-only">{title}</legend>
            <h2 className="text-sm font-semibold text-gray-900 sm:col-span-2 dark:text-slate-100">{title}</h2>
            {children}
        </fieldset>
    );
    const selectedType = types.find((type) => type.id === d.field_work_type_id);
    const registered = d.destination_type === 'registered_organization';
    const external = d.destination_type === 'external_organization';

    return (
        <PortalPage
            title={t(fieldWork ? 'fieldWork.form.editTitle' : 'fieldWork.form.createTitle')}
            description={t('fieldWork.notTransfer')}
            backHref={fieldWork ? route('employee.field-work.show', fieldWork.id) : route('employee.field-work.index')}
        >
            <form onSubmit={(e) => save(e, 'draft')} className="mx-auto max-w-4xl space-y-4">
                {fieldWork?.status === 'returned_for_correction' && fieldWork.decision_reason && (
                    <p role="status" className="rounded-panel border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                        {t('fieldWork.form.returnedNotice')} {fieldWork.decision_reason}
                    </p>
                )}

                <p className={`${panelCls} p-3 text-sm text-gray-700 dark:text-slate-300`}>
                    <span className="text-gray-500 dark:text-slate-400">{t('fieldWork.my.placement')}: </span>
                    {employeeName(employee, locale)} · {[placement.organization, placement.organization_unit, placement.position].map((p) => named(p ?? undefined, locale)).filter(Boolean).join(' · ')}
                </p>

                {section(t('fieldWork.form.sectionWork'), (
                    <>
                        <div className="sm:col-span-2">
                            <label htmlFor="fw-type" className={labelCls}>{t('fieldWork.fields.type')}<span className="text-red-600"> *</span></label>
                            {types.length === 0 ? (
                                <p className="text-sm text-amber-800 dark:text-amber-300">{t('fieldWork.form.noTypes')}</p>
                            ) : (
                                <select id="fw-type" className={inputCls} value={d.field_work_type_id} onChange={(e) => form.setData('field_work_type_id', e.target.value)}>
                                    <option value="">—</option>
                                    {types.map((type) => <option key={type.id} value={type.id}>{named(type, locale)}</option>)}
                                </select>
                            )}
                            {selectedType && (locale === 'am' ? selectedType.description_am ?? selectedType.description_en : selectedType.description_en) && (
                                <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{locale === 'am' ? selectedType.description_am ?? selectedType.description_en : selectedType.description_en}</p>
                            )}
                            {error('field_work_type_id')}
                        </div>
                        <div className="sm:col-span-2">{text('purpose', t('fieldWork.fields.purpose'), true, true, 2000)}</div>
                        <div className="sm:col-span-2">{text('activity_description', t('fieldWork.fields.activity'), false, true, 5000)}</div>
                    </>
                ))}

                {section(t('fieldWork.form.sectionDestination'), (
                    <>
                        <div className="sm:col-span-2">
                            <span className={labelCls}>{t('fieldWork.fields.destinationType')}<span className="text-red-600"> *</span></span>
                            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={t('fieldWork.fields.destinationType')}>
                                {destinationTypes.map((type) => (
                                    <label key={type} className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm ${d.destination_type === type ? 'border-[color:var(--color-primary)] text-[color:var(--color-primary)]' : 'border-gray-300 text-gray-700 dark:border-slate-700 dark:text-slate-300'}`}>
                                        <input type="radio" name="destination_type" value={type} checked={d.destination_type === type} onChange={() => form.setData('destination_type', type)} />
                                        {t(`fieldWork.destinationTypes.${type}`)}
                                    </label>
                                ))}
                            </div>
                            {error('destination_type')}
                        </div>

                        {registered && (
                            <>
                                <div className="sm:col-span-2">
                                    {d.destination_organization ? (
                                        <div className="flex items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-slate-700">
                                            <span>{named(d.destination_organization, locale)}</span>
                                            <button type="button" className="text-xs font-medium text-red-700 hover:underline dark:text-red-400" onClick={() => form.setData((data) => ({ ...data, destination_organization: null, destination_organization_id: '', destination_organization_unit_id: '' }))}>{t('fieldWork.actions.remove')}</button>
                                        </div>
                                    ) : (
                                        <SearchPicker<Option>
                                            url={route('employee.field-work.lookup.organizations')}
                                            label={`${t('fieldWork.fields.destinationOrganization')} *`}
                                            placeholder={t('fieldWork.fields.searchOrganization')}
                                            render={(row) => named(row, locale)}
                                            onPick={(row) => form.setData((data) => ({ ...data, destination_organization: row, destination_organization_id: row.id, destination_organization_unit_id: '' }))}
                                        />
                                    )}
                                    {error('destination_organization_id')}
                                </div>
                                {units.length > 0 && (
                                    <div className="sm:col-span-2">
                                        <label htmlFor="fw-unit" className={labelCls}>{t('fieldWork.fields.destinationUnit')}</label>
                                        <select id="fw-unit" className={inputCls} value={d.destination_organization_unit_id} onChange={(e) => form.setData('destination_organization_unit_id', e.target.value)}>
                                            <option value="">—</option>
                                            {units.map((unit) => <option key={unit.id} value={unit.id}>{named(unit, locale)}</option>)}
                                        </select>
                                        {error('destination_organization_unit_id')}
                                    </div>
                                )}
                            </>
                        )}
                        {external && <div className="sm:col-span-2">{text('external_organization_name', t('fieldWork.fields.externalOrganization'), true)}</div>}
                        {!registered && !external && <div className="sm:col-span-2">{text('site_name', t('fieldWork.fields.siteName'), true)}</div>}
                        <div className="sm:col-span-2">{text('destination_address', t('fieldWork.fields.address'), !registered, false, 500)}</div>
                        {text('contact_person', t('fieldWork.fields.contactPerson'), false, false, 150)}
                        {text('contact_phone', t('fieldWork.fields.contactPhone'), false, false, 32)}

                        <div className="sm:col-span-2">
                            <p className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('fieldWork.fields.expectedPoint')}</p>
                            <p className="text-xs text-gray-500 dark:text-slate-400">{t('fieldWork.fields.expectedPointHint')}</p>
                        </div>
                        {text('expected_latitude', t('fieldWork.fields.latitude'), false, false, 20)}
                        {text('expected_longitude', t('fieldWork.fields.longitude'), false, false, 20)}
                        {text('geofence_radius_m', t('fieldWork.fields.radius'), false, false, 6)}
                    </>
                ))}

                {section(t('fieldWork.form.sectionSchedule'), (
                    <>
                        <div>
                            <span className={labelCls}>{t('fieldWork.fields.startDate')}<span className="text-red-600"> *</span></span>
                            <LocalizedDatePicker value={d.start_date} onChange={(v) => form.setData('start_date', v)} />
                            {error('start_date')}
                        </div>
                        <div>
                            <span className={labelCls}>{t('fieldWork.fields.startTime')}<span className="text-red-600"> *</span></span>
                            <LocalizedTimePicker value={d.start_time} onChange={(v) => form.setData('start_time', v)} />
                            {error('start_time')}
                        </div>
                        <div>
                            <span className={labelCls}>{t('fieldWork.fields.returnDate')}<span className="text-red-600"> *</span></span>
                            <LocalizedDatePicker value={d.return_date} min={d.start_date} onChange={(v) => form.setData('return_date', v)} />
                            {error('return_date')}
                        </div>
                        <div>
                            <span className={labelCls}>{t('fieldWork.fields.returnTime')}<span className="text-red-600"> *</span></span>
                            <LocalizedTimePicker value={d.return_time} onChange={(v) => form.setData('return_time', v)} />
                            {error('return_time')}
                        </div>
                    </>
                ))}

                {canAddTeam && section(t('fieldWork.form.sectionTeam'), (
                    <div className="space-y-2 sm:col-span-2">
                        <p className="text-xs text-gray-500 dark:text-slate-400">{t('fieldWork.fields.participantsHint')}</p>
                        {d.participants.length > 0 && (
                            <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm dark:divide-slate-800 dark:border-slate-700">
                                {d.participants.map((p) => (
                                    <li key={p.id} className="flex items-center justify-between gap-2 px-3 py-2">
                                        <span>{employeeName(p, locale)} <span className="text-xs text-gray-500 dark:text-slate-400">{p.employee_number}</span></span>
                                        <button type="button" className="text-xs font-medium text-red-700 hover:underline dark:text-red-400" onClick={() => form.setData('participants', d.participants.filter((x) => x.id !== p.id))}>{t('fieldWork.actions.remove')}</button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <SearchPicker<EmployeeRef>
                            url={route('employee.field-work.lookup.colleagues')}
                            label={t('fieldWork.fields.participants')}
                            placeholder={t('fieldWork.fields.searchColleague')}
                            render={(row) => `${employeeName(row, locale)} · ${row.employee_number ?? ''}`}
                            exclude={d.participants.map((p) => p.id)}
                            onPick={(row) => form.setData('participants', [...d.participants, row])}
                        />
                        {error('participant_employee_ids')}
                    </div>
                ))}

                {(errors.conflicts || errors.employee || errors.status) && (
                    <p role="alert" className="whitespace-pre-line rounded-md bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-300">{errors.conflicts ?? errors.employee ?? errors.status}</p>
                )}

                <div className="flex flex-wrap gap-2">
                    <button type="submit" disabled={form.processing} className={secondaryBtn}>{t('fieldWork.actions.saveDraft')}</button>
                    {canSubmit && <button type="button" disabled={form.processing} onClick={(e) => save(e, 'submit')} className={primaryBtn}>{t('fieldWork.actions.submit')}</button>}
                    <Link href={fieldWork ? route('employee.field-work.show', fieldWork.id) : route('employee.field-work.index')} className={secondaryBtn}>{t('fieldWork.actions.back')}</Link>
                </div>
            </form>
        </PortalPage>
    );
}
