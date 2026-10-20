import { useForm } from '@inertiajs/react';
import { Button, FormField, Input } from '@euisis/ui';
import { useState, type FormEvent } from 'react';
import SearchPicker from '@/Components/fieldWork/SearchPicker';
import OrganizationPicker from './OrganizationPicker';
import { useTransportLabels } from './labels';
import { FormShell, NotesField, Section, SelectField, TextField, s, useProviderOptions, type EmployeeHit } from './TransportForms';
import {
    DIRECTIONS, ELIGIBILITY_STATUSES, SETTLEMENT_MODELS, SETTLEMENT_PERIODS,
    type OrganizationOption, type ProviderOption, type TransportContract, type TransportDriver, type TransportEligibility, type TransportRoute, type TransportSchedule, type TransportVehicle,
} from '@/types/transport';

/** Forms for the verified-usage entities: contracts, schedules, eligibility grants, devices and extra trips. */

type ModelInfo = { value: string; terms: string[] };

export function ContractForm({ contract, today, providers, models, periods, termKeys }: { contract?: TransportContract; today: string; providers: ProviderOption[]; models: ModelInfo[]; periods: string[]; termKeys: string[] }) {
    const { t, label } = useTransportLabels();
    const providerOptions = useProviderOptions(providers);
    const [organization, setOrganization] = useState<OrganizationOption | null>(contract?.organization ?? null);
    const form = useForm({
        contract_reference: contract?.contract_reference ?? '',
        organization_id: contract?.organization_id ?? '',
        provider_id: contract?.provider_id ?? '',
        currency_code: contract?.currency_code ?? 'ETB',
        settlement_model: contract?.settlement_model ?? 'per_verified_boarding',
        settlement_period: contract?.settlement_period ?? 'monthly',
        terms: Object.fromEntries(termKeys.map((key) => [key, contract?.terms?.[key] ?? ''])) as Record<string, string>,
        service_coverage: s(contract?.service_coverage),
        terms_reference: s(contract?.terms_reference),
        effective_from: contract?.effective_from ?? today,
        effective_to: s(contract?.effective_to),
        notes: s(contract?.notes),
        agreement: null as File | null,
    });
    const required = models.find((m) => m.value === form.data.settlement_model)?.terms ?? [];

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, organization_id: d.organization_id || null, effective_to: d.effective_to || null, _method: contract ? 'PATCH' : 'POST' }));
        form.post(contract ? route('transport.contracts.update', contract.id) : route('transport.contracts.store'), { forceFormData: true });
    }

    return (
        <FormShell onSubmit={submit} processing={form.processing} cancelHref={contract ? route('transport.contracts.show', contract.id) : route('transport.contracts.index')} submitLabel={contract ? t('transport.save') : t('transport.create')}>
            <Section title={t('transport.contractDetails')}>
                <TextField label={t('transport.contractReference')} required value={form.data.contract_reference} onChange={(v) => form.setData('contract_reference', v)} error={form.errors.contract_reference} />
                <SelectField label={t('transport.provider')} required value={form.data.provider_id} onChange={(v) => form.setData('provider_id', v)} error={form.errors.provider_id} options={providerOptions} />
                <FormField label={t('transport.organization')} description={t('transport.contractOrganizationHelp')} error={form.errors.organization_id} className="sm:col-span-2">
                    {({ id }) => <OrganizationPicker id={id} value={form.data.organization_id} selected={organization} onChange={(orgId) => { form.setData('organization_id', orgId); if (!orgId) setOrganization(null); }} />}
                </FormField>
                <TextField label={t('transport.validFrom')} type="date" required value={form.data.effective_from} onChange={(v) => form.setData('effective_from', v)} error={form.errors.effective_from} />
                <TextField label={t('transport.validUntil')} type="date" value={form.data.effective_to} onChange={(v) => form.setData('effective_to', v)} error={form.errors.effective_to} />
                <TextField label={t('transport.currency')} required value={form.data.currency_code} onChange={(v) => form.setData('currency_code', v.toUpperCase())} error={form.errors.currency_code} />
                <TextField label={t('transport.termsReference')} value={form.data.terms_reference} onChange={(v) => form.setData('terms_reference', v)} error={form.errors.terms_reference} />
            </Section>
            <Section title={t('transport.settlementTerms')} description={t('transport.settlementTermsHelp')}>
                <SelectField label={t('transport.settlementModel')} required value={form.data.settlement_model} onChange={(v) => form.setData('settlement_model', v as typeof form.data.settlement_model)} error={form.errors.settlement_model}
                    options={SETTLEMENT_MODELS.map((m) => ({ value: m, label: label('settlementModels', m) }))} />
                <SelectField label={t('transport.settlementPeriod')} required value={form.data.settlement_period} onChange={(v) => form.setData('settlement_period', v as typeof form.data.settlement_period)} error={form.errors.settlement_period}
                    options={(periods.length ? periods : SETTLEMENT_PERIODS).map((p) => ({ value: p, label: label('settlementPeriods', p) }))} />
                {termKeys.map((key) => (
                    <TextField key={key} label={t(`transport.terms.${key}`)} value={form.data.terms[key] ?? ''} onChange={(v) => form.setData('terms', { ...form.data.terms, [key]: v })} error={(form.errors as Record<string, string>)[`terms.${key}`]}
                        inputMode="decimal" required={required.includes(key)} description={required.includes(key) ? t('transport.termRequiredForModel') : undefined} />
                ))}
                {form.data.settlement_model === 'other_approved_model' && <p className="text-sm text-amber-700 sm:col-span-2">{t('transport.otherModelHelp')}</p>}
            </Section>
            <Section title={t('transport.agreement')}>
                <FormField label={t('transport.serviceCoverage')} error={form.errors.service_coverage} className="sm:col-span-2">
                    {({ id }) => <textarea id={id} rows={3} className="w-full rounded-control border border-(--app-border) bg-(--app-surface) px-3 py-2 text-sm" value={form.data.service_coverage} onChange={(e) => form.setData('service_coverage', e.target.value)} />}
                </FormField>
                <FormField label={t('transport.agreementFile')} description={t('transport.agreementFileHelp')} error={form.errors.agreement} className="sm:col-span-2">
                    {({ id }) => <Input id={id} type="file" accept="application/pdf" onChange={(e) => form.setData('agreement', e.target.files?.[0] ?? null)} />}
                </FormField>
                <NotesField value={form.data.notes} onChange={(v) => form.setData('notes', v)} error={form.errors.notes} />
            </Section>
        </FormShell>
    );
}

export function ScheduleForm({ schedule, today, providers, routes, vehicles, drivers, mode = 'admin', urls }: {
    schedule?: TransportSchedule; today: string; providers?: ProviderOption[]; routes: TransportRoute[]; vehicles: TransportVehicle[]; drivers: TransportDriver[]; mode?: 'admin' | 'portal';
    urls: { store: string; update: (id: string) => string; cancel: string };
}) {
    const { t, label, name } = useTransportLabels();
    const providerOptions = useProviderOptions(providers ?? []);
    const form = useForm({
        provider_id: schedule?.provider_id ?? '',
        transport_route_id: schedule?.transport_route_id ?? '',
        direction: schedule?.direction ?? 'inbound',
        service_days: schedule?.service_days ?? [1, 2, 3, 4, 5],
        planned_departure: s(schedule?.planned_departure),
        planned_arrival: s(schedule?.planned_arrival),
        skips_public_holidays: schedule?.skips_public_holidays ?? true,
        default_vehicle_id: schedule?.default_vehicle_id ?? '',
        default_driver_id: schedule?.default_driver_id ?? '',
        effective_from: schedule?.effective_from ?? today,
        effective_to: s(schedule?.effective_to),
        is_active: schedule?.is_active ?? true,
        notes: s(schedule?.notes),
    });
    const providerId = mode === 'portal' ? (routes[0]?.provider_id ?? '') : form.data.provider_id;
    const ownRoutes = routes.filter((r) => !providerId || r.provider_id === providerId);
    const ownVehicles = vehicles.filter((v) => !providerId || v.provider_id === providerId);
    const ownDrivers = drivers.filter((d) => !providerId || d.provider_id === providerId);
    const days = [1, 2, 3, 4, 5, 6, 7];

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => {
            const payload: Record<string, unknown> = { ...d, planned_arrival: d.planned_arrival || null, default_vehicle_id: d.default_vehicle_id || null, default_driver_id: d.default_driver_id || null, effective_to: d.effective_to || null };
            if (mode === 'portal') delete payload.provider_id;
            return payload;
        });
        schedule ? form.patch(urls.update(schedule.id)) : form.post(urls.store);
    }

    return (
        <FormShell onSubmit={submit} processing={form.processing} cancelHref={urls.cancel} submitLabel={schedule ? t('transport.save') : t('transport.create')}>
            <Section>
                {mode === 'admin' && <SelectField label={t('transport.provider')} required value={form.data.provider_id} onChange={(v) => form.setData((d) => ({ ...d, provider_id: v, transport_route_id: '', default_vehicle_id: '', default_driver_id: '' }))} error={form.errors.provider_id} options={providerOptions} />}
                <SelectField label={t('transport.route')} required value={form.data.transport_route_id} onChange={(v) => form.setData('transport_route_id', v)} error={form.errors.transport_route_id}
                    options={[{ value: '', label: t('transport.selectRoute') }, ...ownRoutes.map((r) => ({ value: r.id, label: `${r.route_code} — ${name(r)}` }))]} />
                <SelectField label={t('transport.direction')} required value={form.data.direction} onChange={(v) => form.setData('direction', v as typeof form.data.direction)} error={form.errors.direction}
                    options={DIRECTIONS.map((d) => ({ value: d, label: label('directions', d) }))} />
                <TextField label={t('transport.departure')} type="time" required value={form.data.planned_departure} onChange={(v) => form.setData('planned_departure', v)} error={form.errors.planned_departure} />
                <TextField label={t('transport.arrival')} type="time" value={form.data.planned_arrival} onChange={(v) => form.setData('planned_arrival', v)} error={form.errors.planned_arrival} />
                <FormField label={t('transport.serviceDays')} required error={form.errors.service_days} className="sm:col-span-2">
                    {() => (
                        <div className="flex flex-wrap gap-2">
                            {days.map((day) => {
                                const on = form.data.service_days.includes(day);
                                return (
                                    <button key={day} type="button" aria-pressed={on} onClick={() => form.setData('service_days', on ? form.data.service_days.filter((d) => d !== day) : [...form.data.service_days, day].sort())}
                                        className={`min-h-11 rounded-control border px-3 text-sm ${on ? 'border-primary bg-primary text-white' : 'border-(--app-border) bg-(--app-surface)'}`}>{t(`transport.weekdays.${day}`)}</button>
                                );
                            })}
                        </div>
                    )}
                </FormField>
                <SelectField label={t('transport.defaultVehicle')} value={form.data.default_vehicle_id} onChange={(v) => form.setData('default_vehicle_id', v)} error={form.errors.default_vehicle_id}
                    options={[{ value: '', label: t('transport.selectVehicle') }, ...ownVehicles.map((v) => ({ value: v.id, label: `${v.vehicle_code} · ${v.plate_number}` }))]} />
                <SelectField label={t('transport.defaultDriver')} value={form.data.default_driver_id} onChange={(v) => form.setData('default_driver_id', v)} error={form.errors.default_driver_id}
                    options={[{ value: '', label: t('transport.selectDriver') }, ...ownDrivers.map((d) => ({ value: d.id, label: d.full_name }))]} />
                <TextField label={t('transport.validFrom')} type="date" required value={form.data.effective_from} onChange={(v) => form.setData('effective_from', v)} error={form.errors.effective_from} />
                <TextField label={t('transport.validUntil')} type="date" value={form.data.effective_to} onChange={(v) => form.setData('effective_to', v)} error={form.errors.effective_to} />
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4" checked={form.data.skips_public_holidays} onChange={(e) => form.setData('skips_public_holidays', e.target.checked)} />{t('transport.skipsPublicHolidays')}</label>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />{t('transport.scheduleActive')}</label>
                <NotesField value={form.data.notes} onChange={(v) => form.setData('notes', v)} error={form.errors.notes} />
            </Section>
        </FormShell>
    );
}

export function EligibilityForm({ grant, today, providers, contracts }: { grant?: TransportEligibility; today: string; providers: ProviderOption[]; contracts: { id: string; contract_reference: string; version_no: number }[] }) {
    const { t, label, name } = useTransportLabels();
    const [employee, setEmployee] = useState<EmployeeHit | null>(grant?.employee ?? null);
    const [organization, setOrganization] = useState<OrganizationOption | null>(grant?.organization ?? null);
    const form = useForm({
        subject_type: grant?.subject_type ?? 'organization',
        employee_id: grant?.employee_id ?? '',
        organization_id: grant?.organization_id ?? '',
        scope_type: grant?.scope_type ?? 'subtree',
        provider_id: grant?.provider_id ?? '',
        transport_contract_id: grant?.transport_contract_id ?? '',
        status: grant?.status ?? 'active',
        effective_from: grant?.effective_from ?? today,
        effective_to: s(grant?.effective_to),
        authorization_reference: s(grant?.authorization_reference),
        notes: s(grant?.notes),
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, employee_id: d.employee_id || null, organization_id: d.organization_id || null, provider_id: d.provider_id || null, transport_contract_id: d.transport_contract_id || null, effective_to: d.effective_to || null }));
        grant ? form.patch(route('transport.eligibility.update', grant.id)) : form.post(route('transport.eligibility.store'));
    }

    return (
        <FormShell onSubmit={submit} processing={form.processing} cancelHref={route('transport.eligibility.index')} submitLabel={grant ? t('transport.save') : t('transport.create')}>
            <Section description={t('transport.eligibilityHelp')}>
                <SelectField label={t('transport.subjectType')} required value={form.data.subject_type} onChange={(v) => form.setData('subject_type', v as typeof form.data.subject_type)} error={form.errors.subject_type}
                    options={[{ value: 'organization', label: label('subjectTypes', 'organization') }, { value: 'employee', label: label('subjectTypes', 'employee') }]} />
                {form.data.subject_type === 'organization' ? (
                    <>
                        <FormField label={t('transport.organization')} required error={form.errors.organization_id}>
                            {({ id }) => <OrganizationPicker id={id} value={form.data.organization_id} selected={organization} onChange={(orgId) => { form.setData('organization_id', orgId); if (!orgId) setOrganization(null); }} />}
                        </FormField>
                        <SelectField label={t('transport.scope')} required value={form.data.scope_type} onChange={(v) => form.setData('scope_type', v as typeof form.data.scope_type)} error={form.errors.scope_type}
                            options={[{ value: 'self', label: t('transport.scopeSelf') }, { value: 'subtree', label: t('transport.scopeSubtree') }]} />
                    </>
                ) : (
                    <div className="space-y-1.5 sm:col-span-2">
                        {employee ? (
                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-control border border-(--app-border) px-3 py-2 text-sm">
                                <span>{employee.full_name} <span className="text-(--app-muted-foreground)">({employee.employee_number}{employee.organization ? ` · ${name(employee.organization)}` : ''})</span></span>
                                <Button type="button" variant="ghost" size="sm" onClick={() => { setEmployee(null); form.setData('employee_id', ''); }}>{t('transport.change')}</Button>
                            </div>
                        ) : (
                            <SearchPicker<EmployeeHit> url={route('lookup.employees')} label={`${t('transport.employee')} *`} placeholder={t('transport.searchEmployee')} render={(e) => `${e.full_name} (${e.employee_number})`} onPick={(e) => { setEmployee(e); form.setData('employee_id', e.id); }} />
                        )}
                        {form.errors.employee_id && <p className="text-sm text-red-600" role="alert">{form.errors.employee_id}</p>}
                    </div>
                )}
                <SelectField label={t('transport.provider')} value={form.data.provider_id} onChange={(v) => form.setData('provider_id', v)} error={form.errors.provider_id}
                    options={[{ value: '', label: t('transport.anyProvider') }, ...providers.map((p) => ({ value: p.id, label: `${p.provider_code} — ${name(p)}` }))]} />
                <SelectField label={t('transport.contract')} value={form.data.transport_contract_id} onChange={(v) => form.setData('transport_contract_id', v)} error={form.errors.transport_contract_id}
                    options={[{ value: '', label: t('transport.noContract') }, ...contracts.map((c) => ({ value: c.id, label: `${c.contract_reference} v${c.version_no}` }))]} />
                <TextField label={t('transport.validFrom')} type="date" required value={form.data.effective_from} onChange={(v) => form.setData('effective_from', v)} error={form.errors.effective_from} />
                <TextField label={t('transport.validUntil')} type="date" value={form.data.effective_to} onChange={(v) => form.setData('effective_to', v)} error={form.errors.effective_to} />
                <TextField label={t('transport.authorizationReference')} required value={form.data.authorization_reference} onChange={(v) => form.setData('authorization_reference', v)} error={form.errors.authorization_reference} description={t('transport.authorizationReferenceHelp')} />
                <SelectField label={t('transport.status')} required value={form.data.status} onChange={(v) => form.setData('status', v as typeof form.data.status)} error={form.errors.status}
                    options={ELIGIBILITY_STATUSES.map((v) => ({ value: v, label: label('statuses', v) }))} />
                <NotesField value={form.data.notes} onChange={(v) => form.setData('notes', v)} error={form.errors.notes} />
            </Section>
        </FormShell>
    );
}

export function DeviceForm({ providers, vehicles }: { providers: ProviderOption[]; vehicles: TransportVehicle[] }) {
    const { t } = useTransportLabels();
    const providerOptions = useProviderOptions(providers);
    const form = useForm({ provider_id: '', transport_vehicle_id: '', device_code: '', name: '', platform: '', software_version: '' });
    const ownVehicles = vehicles.filter((v) => v.provider_id === form.data.provider_id);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, transport_vehicle_id: d.transport_vehicle_id || null, platform: d.platform || null, software_version: d.software_version || null }));
        form.post(route('transport.devices.store'));
    }

    return (
        <FormShell onSubmit={submit} processing={form.processing} cancelHref={route('transport.devices.index')} submitLabel={t('transport.registerDevice')}>
            <Section description={t('transport.deviceHelp')}>
                <SelectField label={t('transport.provider')} required value={form.data.provider_id} onChange={(v) => form.setData((d) => ({ ...d, provider_id: v, transport_vehicle_id: '' }))} error={form.errors.provider_id} options={providerOptions} />
                <SelectField label={t('transport.boundVehicle')} value={form.data.transport_vehicle_id} onChange={(v) => form.setData('transport_vehicle_id', v)} error={form.errors.transport_vehicle_id}
                    options={[{ value: '', label: t('transport.anyVehicleOfProvider') }, ...ownVehicles.map((v) => ({ value: v.id, label: `${v.vehicle_code} · ${v.plate_number}` }))]} />
                <TextField label={t('transport.deviceCode')} required value={form.data.device_code} onChange={(v) => form.setData('device_code', v)} error={form.errors.device_code} />
                <TextField label={t('transport.deviceName')} required value={form.data.name} onChange={(v) => form.setData('name', v)} error={form.errors.name} />
                <TextField label={t('transport.platform')} value={form.data.platform} onChange={(v) => form.setData('platform', v)} error={form.errors.platform} />
                <TextField label={t('transport.softwareVersion')} value={form.data.software_version} onChange={(v) => form.setData('software_version', v)} error={form.errors.software_version} />
            </Section>
        </FormShell>
    );
}

export function AdminTripForm({ today, providers, routes, vehicles, drivers }: { today: string; providers: ProviderOption[]; routes: TransportRoute[]; vehicles: TransportVehicle[]; drivers: TransportDriver[] }) {
    const { t, label, name } = useTransportLabels();
    const providerOptions = useProviderOptions(providers);
    const form = useForm({ provider_id: '', transport_route_id: '', transport_vehicle_id: '', transport_driver_id: '', direction: 'inbound', trip_date: today, departure_time: '', arrival_time: '', capacity: '', notes: '' });
    const own = <T extends { provider_id: string }>(rows: T[]) => rows.filter((r) => r.provider_id === form.data.provider_id);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, transport_vehicle_id: d.transport_vehicle_id || null, transport_driver_id: d.transport_driver_id || null, departure_time: d.departure_time || null, arrival_time: d.arrival_time || null, capacity: d.capacity || null }));
        form.post(route('transport.trips.store'));
    }

    return (
        <FormShell onSubmit={submit} processing={form.processing} cancelHref={route('transport.trips.index')} submitLabel={t('transport.planExtraTrip')}>
            <Section description={t('transport.extraTripHelp')}>
                <SelectField label={t('transport.provider')} required value={form.data.provider_id} onChange={(v) => form.setData((d) => ({ ...d, provider_id: v, transport_route_id: '', transport_vehicle_id: '', transport_driver_id: '' }))} error={form.errors.provider_id} options={providerOptions} />
                <SelectField label={t('transport.route')} required value={form.data.transport_route_id} onChange={(v) => form.setData('transport_route_id', v)} error={form.errors.transport_route_id}
                    options={[{ value: '', label: t('transport.selectRoute') }, ...own(routes).map((r) => ({ value: r.id, label: `${r.route_code} — ${name(r)}` }))]} />
                <SelectField label={t('transport.direction')} required value={form.data.direction} onChange={(v) => form.setData('direction', v)} error={form.errors.direction} options={DIRECTIONS.map((d) => ({ value: d, label: label('directions', d) }))} />
                <TextField label={t('transport.tripDate')} type="date" required value={form.data.trip_date} onChange={(v) => form.setData('trip_date', v)} error={form.errors.trip_date} />
                <TextField label={t('transport.departure')} type="time" value={form.data.departure_time} onChange={(v) => form.setData('departure_time', v)} error={form.errors.departure_time} />
                <TextField label={t('transport.arrival')} type="time" value={form.data.arrival_time} onChange={(v) => form.setData('arrival_time', v)} error={form.errors.arrival_time} />
                <SelectField label={t('transport.vehicle')} value={form.data.transport_vehicle_id} onChange={(v) => form.setData('transport_vehicle_id', v)} error={form.errors.transport_vehicle_id}
                    options={[{ value: '', label: t('transport.selectVehicle') }, ...own(vehicles).map((v) => ({ value: v.id, label: `${v.vehicle_code} · ${v.plate_number}` }))]} />
                <SelectField label={t('transport.driver')} value={form.data.transport_driver_id} onChange={(v) => form.setData('transport_driver_id', v)} error={form.errors.transport_driver_id}
                    options={[{ value: '', label: t('transport.selectDriver') }, ...own(drivers).map((d) => ({ value: d.id, label: d.full_name }))]} />
                <TextField label={t('transport.capacity')} type="number" inputMode="numeric" value={form.data.capacity} onChange={(v) => form.setData('capacity', v)} error={form.errors.capacity} />
                <NotesField value={form.data.notes} onChange={(v) => form.setData('notes', v)} error={form.errors.notes} />
            </Section>
        </FormShell>
    );
}
