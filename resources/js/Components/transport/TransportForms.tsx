import { Link, useForm } from '@inertiajs/react';
import { Button, Card, FormField, Input, Select, Textarea, buttonClassName } from '@euisis/ui';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import SearchPicker from '@/Components/fieldWork/SearchPicker';
import OrganizationPicker from './OrganizationPicker';
import { useTransportLabels } from './labels';
import {
    DIRECTIONS, DRIVER_STATUSES, PASS_STATUSES, PROVIDER_STATUSES, VEHICLE_STATUSES, VEHICLE_TYPES,
    type ProviderOption, type ScopeType, type TransportDriver, type TransportPass, type TransportProvider,
    type TransportRoute, type TransportRouteStop, type TransportTrip, type TransportVehicle,
} from '@/types/transport';

/**
 * The transport forms, shared by the admin pages and the provider portal.
 * Admin forms choose the provider; portal forms are always the signed-in
 * provider's and never send provider_id (the server refuses it). Every field
 * shows its server validation error under it.
 */

type Mode = 'admin' | 'portal';

export function FormShell({ onSubmit, processing, cancelHref, submitLabel, children }: {
    onSubmit: (event: FormEvent) => void;
    processing: boolean;
    cancelHref: string;
    submitLabel: string;
    children: ReactNode;
}) {
    const { t } = useTransportLabels();
    return (
        <form onSubmit={onSubmit} noValidate>
            <Card className="space-y-6">
                {children}
                <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                    <Link href={cancelHref} className={buttonClassName({ variant: 'outline' })}>{t('transport.cancel')}</Link>
                    <Button type="submit" variant="primary" disabled={processing}>{submitLabel}</Button>
                </div>
            </Card>
        </form>
    );
}

export function Section({ title, description, children }: { title?: string; description?: string; children: ReactNode }) {
    return (
        <fieldset className="space-y-4">
            {title && <legend className="text-sm font-semibold text-(--app-foreground)">{title}</legend>}
            {description && <p className="-mt-2 text-sm text-(--app-muted-foreground)">{description}</p>}
            <div className="grid gap-4 sm:grid-cols-2">{children}</div>
        </fieldset>
    );
}

export function TextField({ label, value, onChange, error, required, type = 'text', wide, description, inputMode }: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    required?: boolean;
    type?: string;
    wide?: boolean;
    description?: string;
    inputMode?: 'numeric' | 'decimal' | 'tel' | 'email';
}) {
    return (
        <FormField label={label} required={required} error={error} description={description} className={wide ? 'sm:col-span-2' : undefined}>
            {({ id, describedBy, invalid }) => (
                <Input id={id} type={type} inputMode={inputMode} value={value} onChange={(e) => onChange(e.target.value)} aria-describedby={describedBy} aria-invalid={invalid} required={required} />
            )}
        </FormField>
    );
}

export function SelectField({ label, value, onChange, error, required, options, wide }: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    required?: boolean;
    options: { value: string; label: string }[];
    wide?: boolean;
}) {
    return (
        <FormField label={label} required={required} error={error} className={wide ? 'sm:col-span-2' : undefined}>
            {({ id, describedBy, invalid }) => (
                <Select id={id} value={value} onChange={(e) => onChange(e.target.value)} aria-describedby={describedBy} aria-invalid={invalid} required={required}>
                    {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </Select>
            )}
        </FormField>
    );
}

export function NotesField({ value, onChange, error }: { value: string; onChange: (value: string) => void; error?: string }) {
    const { t } = useTransportLabels();
    return (
        <FormField label={t('transport.notes')} error={error} className="sm:col-span-2">
            {({ id, describedBy, invalid }) => <Textarea id={id} rows={3} value={value} onChange={(e) => onChange(e.target.value)} aria-describedby={describedBy} aria-invalid={invalid} />}
        </FormField>
    );
}

export function useProviderOptions(providers: ProviderOption[]) {
    const { t, name } = useTransportLabels();
    return [{ value: '', label: t('transport.selectProvider') }, ...providers.map((p) => ({ value: p.id, label: `${p.provider_code} — ${name(p)}` }))];
}

export const s = (value: string | number | null | undefined): string => (value === null || value === undefined ? '' : String(value));

// ── Route ───────────────────────────────────────────────────────────────

export function RouteForm({ mode, route: record, providers = [] }: { mode: Mode; route?: TransportRoute; providers?: ProviderOption[] }) {
    const { t, label } = useTransportLabels();
    const providerOptions = useProviderOptions(providers);
    const form = useForm({
        provider_id: record?.provider_id ?? '',
        route_code: record?.route_code ?? '',
        name_en: s(record?.name_en),
        name_am: s(record?.name_am),
        origin_en: s(record?.origin_en),
        origin_am: s(record?.origin_am),
        destination_en: s(record?.destination_en),
        destination_am: s(record?.destination_am),
        distance_km: s(record?.distance_km),
        estimated_duration_minutes: s(record?.estimated_duration_minutes),
        assigned_scope_type: (record?.assigned_scope_type ?? 'citywide') as ScopeType,
        assigned_organization_id: record?.assigned_organization_id ?? '',
        is_active: record?.is_active ?? true,
        notes: s(record?.notes),
    });
    const base = mode === 'admin' ? 'transport.routes' : 'provider.portal.transport.routes';

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => {
            // The portal may not name a provider or decide who rides.
            const { provider_id, assigned_scope_type, assigned_organization_id, ...rest } = data;
            return mode === 'admin' ? { ...rest, provider_id, assigned_scope_type, assigned_organization_id: assigned_scope_type === 'citywide' ? null : assigned_organization_id || null } : rest;
        });
        record ? form.patch(route(`${base}.update`, record.id)) : form.post(route(`${base}.store`));
    }

    return (
        <FormShell onSubmit={submit} processing={form.processing} cancelHref={route(`${base}.index`)} submitLabel={record ? t('transport.save') : t('transport.create')}>
            <Section title={t('transport.routeDetails')}>
                {mode === 'admin' && <SelectField label={t('transport.provider')} required value={form.data.provider_id} onChange={(v) => form.setData('provider_id', v)} error={form.errors.provider_id} options={providerOptions} wide />}
                <TextField label={t('transport.routeCode')} required value={form.data.route_code} onChange={(v) => form.setData('route_code', v)} error={form.errors.route_code} />
                <div className="hidden sm:block" />
                <TextField label={t('transport.nameEn')} required value={form.data.name_en} onChange={(v) => form.setData('name_en', v)} error={form.errors.name_en} />
                <TextField label={t('transport.nameAm')} value={form.data.name_am} onChange={(v) => form.setData('name_am', v)} error={form.errors.name_am} />
                <TextField label={`${t('transport.origin')} (EN)`} required value={form.data.origin_en} onChange={(v) => form.setData('origin_en', v)} error={form.errors.origin_en} />
                <TextField label={`${t('transport.origin')} (አማ)`} value={form.data.origin_am} onChange={(v) => form.setData('origin_am', v)} error={form.errors.origin_am} />
                <TextField label={`${t('transport.destination')} (EN)`} required value={form.data.destination_en} onChange={(v) => form.setData('destination_en', v)} error={form.errors.destination_en} />
                <TextField label={`${t('transport.destination')} (አማ)`} value={form.data.destination_am} onChange={(v) => form.setData('destination_am', v)} error={form.errors.destination_am} />
                <TextField label={t('transport.distanceKm')} type="number" inputMode="decimal" value={form.data.distance_km} onChange={(v) => form.setData('distance_km', v)} error={form.errors.distance_km} />
                <TextField label={t('transport.durationMinutes')} type="number" inputMode="numeric" value={form.data.estimated_duration_minutes} onChange={(v) => form.setData('estimated_duration_minutes', v)} error={form.errors.estimated_duration_minutes} />
                <label className="flex items-center gap-2 text-sm sm:col-span-2">
                    <input type="checkbox" className="h-4 w-4" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                    {t('transport.routeActive')}
                </label>
            </Section>
            {mode === 'admin' && (
                <Section title={t('transport.whoMayRide')} description={t('transport.whoMayRideHelp')}>
                    <SelectField label={t('transport.scope')} required value={form.data.assigned_scope_type} onChange={(v) => form.setData('assigned_scope_type', v as ScopeType)} error={form.errors.assigned_scope_type}
                        options={(['citywide', 'subtree', 'self'] as ScopeType[]).map((v) => ({ value: v, label: label('scopeLabels', v) }))} />
                    {form.data.assigned_scope_type !== 'citywide' && (
                        <FormField label={t('transport.organization')} required error={form.errors.assigned_organization_id}>
                            {({ id }) => <OrganizationPicker id={id} value={form.data.assigned_organization_id} selected={record?.assigned_organization} onChange={(v) => form.setData('assigned_organization_id', v)} />}
                        </FormField>
                    )}
                </Section>
            )}
            <Section><NotesField value={form.data.notes} onChange={(v) => form.setData('notes', v)} error={form.errors.notes} /></Section>
        </FormShell>
    );
}

// ── Vehicle ─────────────────────────────────────────────────────────────

export function VehicleForm({ mode, vehicle, providers = [], routes = [] }: { mode: Mode; vehicle?: TransportVehicle; providers?: ProviderOption[]; routes?: TransportRoute[] }) {
    const { t, label, name } = useTransportLabels();
    const providerOptions = useProviderOptions(providers);
    const form = useForm({
        provider_id: vehicle?.provider_id ?? '',
        vehicle_code: vehicle?.vehicle_code ?? '',
        plate_number: vehicle?.plate_number ?? '',
        vehicle_type: s(vehicle?.vehicle_type),
        capacity: s(vehicle?.capacity),
        status: vehicle?.status ?? 'active',
        assigned_route_id: vehicle?.assigned_route_id ?? '',
        model: s(vehicle?.model),
        year: s(vehicle?.year),
        color: s(vehicle?.color),
        notes: s(vehicle?.notes),
    });
    const base = mode === 'admin' ? 'transport.vehicles' : 'provider.portal.transport.vehicles';
    const providerRoutes = routes.filter((r) => mode === 'portal' || r.provider_id === form.data.provider_id);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform(({ provider_id, ...rest }) => ({
            ...(mode === 'admin' ? { provider_id } : {}),
            ...rest,
            vehicle_type: rest.vehicle_type || null,
            assigned_route_id: rest.assigned_route_id || null,
        }));
        vehicle ? form.patch(route(`${base}.update`, vehicle.id)) : form.post(route(`${base}.store`));
    }

    return (
        <FormShell onSubmit={submit} processing={form.processing} cancelHref={route(`${base}.index`)} submitLabel={vehicle ? t('transport.save') : t('transport.create')}>
            <Section>
                {mode === 'admin' && <SelectField label={t('transport.provider')} required value={form.data.provider_id} onChange={(v) => form.setData((d) => ({ ...d, provider_id: v, assigned_route_id: '' }))} error={form.errors.provider_id} options={providerOptions} wide />}
                <TextField label={t('transport.vehicleCode')} required value={form.data.vehicle_code} onChange={(v) => form.setData('vehicle_code', v)} error={form.errors.vehicle_code} />
                <TextField label={t('transport.plateNumber')} required value={form.data.plate_number} onChange={(v) => form.setData('plate_number', v)} error={form.errors.plate_number} />
                <SelectField label={t('transport.vehicleType')} value={form.data.vehicle_type} onChange={(v) => form.setData('vehicle_type', v)} error={form.errors.vehicle_type}
                    options={[{ value: '', label: '—' }, ...VEHICLE_TYPES.map((v) => ({ value: v, label: label('vehicleTypes', v) }))]} />
                <TextField label={t('transport.capacity')} type="number" inputMode="numeric" value={form.data.capacity} onChange={(v) => form.setData('capacity', v)} error={form.errors.capacity} />
                <SelectField label={t('transport.status')} required value={form.data.status} onChange={(v) => form.setData('status', v as typeof form.data.status)} error={form.errors.status}
                    options={VEHICLE_STATUSES.map((v) => ({ value: v, label: label('statuses', v) }))} />
                <SelectField label={t('transport.assignedRoute')} value={form.data.assigned_route_id} onChange={(v) => form.setData('assigned_route_id', v)} error={form.errors.assigned_route_id}
                    options={[{ value: '', label: t('transport.noRoute') }, ...providerRoutes.map((r) => ({ value: r.id, label: `${r.route_code} — ${name(r)}` }))]} />
                <TextField label={t('transport.model')} value={form.data.model} onChange={(v) => form.setData('model', v)} error={form.errors.model} />
                <TextField label={t('transport.year')} type="number" inputMode="numeric" value={form.data.year} onChange={(v) => form.setData('year', v)} error={form.errors.year} />
                <TextField label={t('transport.color')} value={form.data.color} onChange={(v) => form.setData('color', v)} error={form.errors.color} />
                <NotesField value={form.data.notes} onChange={(v) => form.setData('notes', v)} error={form.errors.notes} />
            </Section>
        </FormShell>
    );
}

// ── Driver ──────────────────────────────────────────────────────────────

export function DriverForm({ mode, driver, providers = [], vehicles = [] }: { mode: Mode; driver?: TransportDriver; providers?: ProviderOption[]; vehicles?: TransportVehicle[] }) {
    const { t, label } = useTransportLabels();
    const providerOptions = useProviderOptions(providers);
    const form = useForm({
        provider_id: driver?.provider_id ?? '',
        full_name: driver?.full_name ?? '',
        phone_number: s(driver?.phone_number),
        license_number: s(driver?.license_number),
        license_expiry_date: s(driver?.license_expiry_date),
        status: driver?.status ?? 'active',
        assigned_vehicle_id: driver?.assigned_vehicle_id ?? '',
        notes: s(driver?.notes),
    });
    const base = mode === 'admin' ? 'transport.drivers' : 'provider.portal.transport.drivers';
    const providerVehicles = vehicles.filter((v) => mode === 'portal' || v.provider_id === form.data.provider_id);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform(({ provider_id, ...rest }) => ({
            ...(mode === 'admin' ? { provider_id } : {}),
            ...rest,
            license_expiry_date: rest.license_expiry_date || null,
            assigned_vehicle_id: rest.assigned_vehicle_id || null,
        }));
        driver ? form.patch(route(`${base}.update`, driver.id)) : form.post(route(`${base}.store`));
    }

    return (
        <FormShell onSubmit={submit} processing={form.processing} cancelHref={route(`${base}.index`)} submitLabel={driver ? t('transport.save') : t('transport.create')}>
            <Section>
                {mode === 'admin' && <SelectField label={t('transport.provider')} required value={form.data.provider_id} onChange={(v) => form.setData((d) => ({ ...d, provider_id: v, assigned_vehicle_id: '' }))} error={form.errors.provider_id} options={providerOptions} wide />}
                <TextField label={t('transport.driverName')} required value={form.data.full_name} onChange={(v) => form.setData('full_name', v)} error={form.errors.full_name} />
                <TextField label={t('transport.phoneNumber')} type="tel" inputMode="tel" value={form.data.phone_number} onChange={(v) => form.setData('phone_number', v)} error={form.errors.phone_number} />
                <TextField label={t('transport.licenseNumber')} value={form.data.license_number} onChange={(v) => form.setData('license_number', v)} error={form.errors.license_number} />
                <TextField label={t('transport.licenseExpiry')} type="date" value={form.data.license_expiry_date} onChange={(v) => form.setData('license_expiry_date', v)} error={form.errors.license_expiry_date} />
                <SelectField label={t('transport.status')} required value={form.data.status} onChange={(v) => form.setData('status', v as typeof form.data.status)} error={form.errors.status}
                    options={DRIVER_STATUSES.map((v) => ({ value: v, label: label('statuses', v) }))} />
                <SelectField label={t('transport.assignedVehicle')} value={form.data.assigned_vehicle_id} onChange={(v) => form.setData('assigned_vehicle_id', v)} error={form.errors.assigned_vehicle_id}
                    options={[{ value: '', label: t('transport.selectVehicle') }, ...providerVehicles.map((v) => ({ value: v.id, label: `${v.vehicle_code} — ${v.plate_number}` }))]} />
                <NotesField value={form.data.notes} onChange={(v) => form.setData('notes', v)} error={form.errors.notes} />
            </Section>
        </FormShell>
    );
}

// ── Trip (portal only) ──────────────────────────────────────────────────

export function TripForm({ trip, today, routes = [], vehicles = [], drivers = [] }: { trip?: TransportTrip; today: string; routes?: TransportRoute[]; vehicles?: TransportVehicle[]; drivers?: TransportDriver[] }) {
    const { t, label, name } = useTransportLabels();
    const form = useForm({
        transport_route_id: trip?.transport_route_id ?? '',
        transport_vehicle_id: trip?.transport_vehicle_id ?? '',
        transport_driver_id: trip?.transport_driver_id ?? '',
        trip_number: trip?.trip_number ?? '',
        trip_date: trip?.trip_date ?? today,
        departure_time: s(trip?.departure_time),
        arrival_time: s(trip?.arrival_time),
        status: trip?.status === 'assigned' ? 'assigned' : 'planned',
        direction: trip?.direction ?? 'inbound',
        capacity: s(trip?.capacity),
        expected_riders: s(trip?.expected_riders),
        notes: s(trip?.notes),
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            transport_vehicle_id: data.transport_vehicle_id || null,
            transport_driver_id: data.transport_driver_id || null,
            trip_number: data.trip_number || null,
            departure_time: data.departure_time || null,
            arrival_time: data.arrival_time || null,
            capacity: data.capacity || null,
            expected_riders: data.expected_riders || null,
            // A vehicle makes the trip assigned; starting, completing and cancelling are dispatch actions.
            status: data.transport_vehicle_id ? 'assigned' : 'planned',
        }));
        trip ? form.patch(route('provider.portal.transport.trips.update', trip.id)) : form.post(route('provider.portal.transport.trips.store'));
    }

    return (
        <FormShell onSubmit={submit} processing={form.processing} cancelHref={route('provider.portal.transport.trips.index')} submitLabel={trip ? t('transport.save') : t('transport.create')}>
            <Section>
                <SelectField label={t('transport.route')} required value={form.data.transport_route_id} onChange={(v) => form.setData('transport_route_id', v)} error={form.errors.transport_route_id} wide
                    options={[{ value: '', label: t('transport.selectRoute') }, ...routes.filter((r) => r.is_active || r.id === trip?.transport_route_id).map((r) => ({ value: r.id, label: `${r.route_code} — ${name(r)}` }))]} />
                <SelectField label={t('transport.direction')} required value={form.data.direction} onChange={(v) => form.setData('direction', v as typeof form.data.direction)} error={form.errors.direction}
                    options={DIRECTIONS.map((d) => ({ value: d, label: label('directions', d) }))} />
                <TextField label={t('transport.tripDate')} type="date" required value={form.data.trip_date} onChange={(v) => form.setData('trip_date', v)} error={form.errors.trip_date} />
                <TextField label={t('transport.tripNumber')} value={form.data.trip_number} onChange={(v) => form.setData('trip_number', v)} error={form.errors.trip_number} description={trip ? undefined : t('transport.tripNumberHelp')} />
                <TextField label={t('transport.departure')} type="time" value={form.data.departure_time} onChange={(v) => form.setData('departure_time', v)} error={form.errors.departure_time} />
                <TextField label={t('transport.arrival')} type="time" value={form.data.arrival_time} onChange={(v) => form.setData('arrival_time', v)} error={form.errors.arrival_time} />
                <SelectField label={t('transport.vehicle')} value={form.data.transport_vehicle_id} onChange={(v) => form.setData('transport_vehicle_id', v)} error={form.errors.transport_vehicle_id}
                    options={[{ value: '', label: t('transport.selectVehicle') }, ...vehicles.map((v) => ({ value: v.id, label: `${v.vehicle_code} — ${v.plate_number}` }))]} />
                <SelectField label={t('transport.driver')} value={form.data.transport_driver_id} onChange={(v) => form.setData('transport_driver_id', v)} error={form.errors.transport_driver_id}
                    options={[{ value: '', label: t('transport.selectDriver') }, ...drivers.map((d) => ({ value: d.id, label: d.full_name }))]} />
                <TextField label={t('transport.capacity')} type="number" inputMode="numeric" value={form.data.capacity} onChange={(v) => form.setData('capacity', v)} error={form.errors.capacity} />
                <NotesField value={form.data.notes} onChange={(v) => form.setData('notes', v)} error={form.errors.notes} />
            </Section>
        </FormShell>
    );
}

// ── Provider (admin) ────────────────────────────────────────────────────

export function ProviderForm({ provider }: { provider?: TransportProvider }) {
    const { t, label } = useTransportLabels();
    const form = useForm({
        provider_code: provider?.provider_code ?? '',
        name_en: s(provider?.name_en),
        name_am: s(provider?.name_am),
        assigned_scope_type: (provider?.assigned_scope_type ?? 'citywide') as ScopeType,
        assigned_organization_id: provider?.assigned_organization_id ?? '',
        contact_person: s(provider?.contact_person),
        phone_number: s(provider?.phone_number),
        email: s(provider?.email),
        address: s(provider?.address),
        license_number: s(provider?.profile?.license_number),
        registration_number: s(provider?.profile?.registration_number),
        service_area_description_en: s(provider?.profile?.service_area_description_en),
        service_area_description_am: s(provider?.profile?.service_area_description_am),
        status: provider?.status ?? 'active',
        create_provider_user: false,
        user_name: '',
        user_email: '',
        username: '',
        user_password: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform(({ create_provider_user, user_name, user_email, username, user_password, ...rest }) => ({
            ...rest,
            assigned_organization_id: rest.assigned_scope_type === 'citywide' ? null : rest.assigned_organization_id || null,
            ...(provider ? {} : { create_provider_user, ...(create_provider_user ? { user_name, user_email: user_email || null, username: username || null, user_password: user_password || null } : {}) }),
        }));
        provider ? form.patch(route('transport.providers.update', provider.id)) : form.post(route('transport.providers.store'));
    }

    return (
        <FormShell onSubmit={submit} processing={form.processing} cancelHref={provider ? route('transport.providers.show', provider.id) : route('transport.providers.index')} submitLabel={provider ? t('transport.save') : t('transport.create')}>
            <Section title={t('transport.providerInfo')}>
                <TextField label={t('transport.providerCode')} required value={form.data.provider_code} onChange={(v) => form.setData('provider_code', v)} error={form.errors.provider_code} />
                <SelectField label={t('transport.status')} required value={form.data.status} onChange={(v) => form.setData('status', v as typeof form.data.status)} error={form.errors.status}
                    options={PROVIDER_STATUSES.map((v) => ({ value: v, label: label('statuses', v) }))} />
                <TextField label={t('transport.nameEn')} required value={form.data.name_en} onChange={(v) => form.setData('name_en', v)} error={form.errors.name_en} />
                <TextField label={t('transport.nameAm')} value={form.data.name_am} onChange={(v) => form.setData('name_am', v)} error={form.errors.name_am} />
                <TextField label={t('transport.licenseNumber')} value={form.data.license_number} onChange={(v) => form.setData('license_number', v)} error={form.errors.license_number} />
                <TextField label={t('transport.registrationNumber')} value={form.data.registration_number} onChange={(v) => form.setData('registration_number', v)} error={form.errors.registration_number} />
            </Section>
            <Section title={t('transport.whoMayRide')} description={t('transport.whoMayRideHelp')}>
                <SelectField label={t('transport.scope')} required value={form.data.assigned_scope_type} onChange={(v) => form.setData('assigned_scope_type', v as ScopeType)} error={form.errors.assigned_scope_type}
                    options={(['citywide', 'subtree', 'self'] as ScopeType[]).map((v) => ({ value: v, label: label('scopeLabels', v) }))} />
                {form.data.assigned_scope_type !== 'citywide' && (
                    <FormField label={t('transport.organization')} required error={form.errors.assigned_organization_id}>
                        {({ id }) => <OrganizationPicker id={id} value={form.data.assigned_organization_id} selected={provider?.assigned_organization} onChange={(v) => form.setData('assigned_organization_id', v)} />}
                    </FormField>
                )}
                <TextField label={t('transport.serviceAreaEn')} wide value={form.data.service_area_description_en} onChange={(v) => form.setData('service_area_description_en', v)} error={form.errors.service_area_description_en} />
                <TextField label={t('transport.serviceAreaAm')} wide value={form.data.service_area_description_am} onChange={(v) => form.setData('service_area_description_am', v)} error={form.errors.service_area_description_am} />
            </Section>
            <Section title={t('transport.contactDetails')}>
                <TextField label={t('transport.contactPerson')} value={form.data.contact_person} onChange={(v) => form.setData('contact_person', v)} error={form.errors.contact_person} />
                <TextField label={t('transport.phone')} type="tel" inputMode="tel" value={form.data.phone_number} onChange={(v) => form.setData('phone_number', v)} error={form.errors.phone_number} />
                <TextField label={t('transport.email')} type="email" inputMode="email" value={form.data.email} onChange={(v) => form.setData('email', v)} error={form.errors.email} />
                <TextField label={t('transport.address')} value={form.data.address} onChange={(v) => form.setData('address', v)} error={form.errors.address} />
            </Section>
            {!provider && (
                <fieldset className="space-y-4">
                    <label className="flex items-center gap-2 text-sm font-semibold">
                        <input type="checkbox" className="h-4 w-4" checked={form.data.create_provider_user} onChange={(e) => form.setData('create_provider_user', e.target.checked)} />
                        {t('transport.portalAccount')}
                    </label>
                    <p className="-mt-2 text-sm text-(--app-muted-foreground)">{t('transport.portalAccountHelp')}</p>
                    {form.data.create_provider_user && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <TextField label={t('transport.userName')} required value={form.data.user_name} onChange={(v) => form.setData('user_name', v)} error={form.errors.user_name} />
                            <TextField label={t('transport.userEmail')} type="email" inputMode="email" value={form.data.user_email} onChange={(v) => form.setData('user_email', v)} error={form.errors.user_email} />
                            <TextField label={t('transport.username')} value={form.data.username} onChange={(v) => form.setData('username', v)} error={form.errors.username} />
                            <TextField label={t('transport.password')} type="password" value={form.data.user_password} onChange={(v) => form.setData('user_password', v)} error={form.errors.user_password} />
                        </div>
                    )}
                </fieldset>
            )}
        </FormShell>
    );
}

// ── Pass (admin) ────────────────────────────────────────────────────────

export type EmployeeHit = { id: string; employee_number: string; full_name: string; organization?: { name_en: string | null; name_am: string | null } | null };

export function PassForm({ pass, today, providers = [], routes = [], stops = [] }: { pass?: TransportPass; today: string; providers?: ProviderOption[]; routes?: TransportRoute[]; stops?: TransportRouteStop[] }) {
    const { t, label, name } = useTransportLabels();
    const providerOptions = useProviderOptions(providers);
    const [employee, setEmployee] = useState<EmployeeHit | null>(pass?.employee ?? null);
    const form = useForm({
        employee_id: pass?.employee_id ?? '',
        provider_id: pass?.provider_id ?? '',
        transport_route_id: pass?.transport_route_id ?? '',
        boarding_stop_id: pass?.boarding_stop_id ?? '',
        evening_stop_id: pass?.evening_stop_id ?? '',
        valid_from: pass?.valid_from ?? today,
        valid_until: s(pass?.valid_until),
        status: pass?.status ?? 'active',
        notes: s(pass?.notes),
    });
    const providerRoutes = routes.filter((r) => r.provider_id === form.data.provider_id);
    const routeStops = stops.filter((stop) => stop.transport_route_id === form.data.transport_route_id);
    const stopOptions = (allowed: 'pickup_allowed' | 'dropoff_allowed') => [{ value: '', label: t('transport.noStop') }, ...routeStops.filter((stop) => stop[allowed]).map((stop) => ({ value: stop.id, label: `${stop.sequence}. ${name(stop)}` }))];

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, transport_route_id: data.transport_route_id || null, boarding_stop_id: data.boarding_stop_id || null, evening_stop_id: data.evening_stop_id || null, valid_until: data.valid_until || null }));
        pass ? form.patch(route('transport.passes.update', pass.id)) : form.post(route('transport.passes.store'));
    }

    return (
        <FormShell onSubmit={submit} processing={form.processing} cancelHref={route('transport.passes.index')} submitLabel={pass ? t('transport.save') : t('transport.create')}>
            <Section>
                <div className="space-y-1.5 sm:col-span-2">
                    {employee ? (
                        <>
                            <p className="text-sm font-medium">{t('transport.employee')}</p>
                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-control border border-(--app-border) px-3 py-2 text-sm">
                                <span>{employee.full_name} <span className="text-(--app-muted-foreground)">({employee.employee_number}{employee.organization ? ` · ${name(employee.organization)}` : ''})</span></span>
                                <Button type="button" variant="ghost" size="sm" onClick={() => { setEmployee(null); form.setData('employee_id', ''); }}>{t('transport.change')}</Button>
                            </div>
                        </>
                    ) : (
                        <SearchPicker<EmployeeHit>
                            url={route('lookup.employees')}
                            label={`${t('transport.employee')} *`}
                            placeholder={t('transport.searchEmployee')}
                            render={(e) => `${e.full_name} (${e.employee_number})`}
                            onPick={(e) => { setEmployee(e); form.setData('employee_id', e.id); }}
                        />
                    )}
                    {form.errors.employee_id && <p className="text-sm text-red-600" role="alert">{form.errors.employee_id}</p>}
                </div>
                <SelectField label={t('transport.provider')} required value={form.data.provider_id} onChange={(v) => form.setData((d) => ({ ...d, provider_id: v, transport_route_id: '' }))} error={form.errors.provider_id} options={providerOptions} />
                <SelectField label={t('transport.route')} value={form.data.transport_route_id} onChange={(v) => form.setData((d) => ({ ...d, transport_route_id: v, boarding_stop_id: '', evening_stop_id: '' }))} error={form.errors.transport_route_id}
                    options={[{ value: '', label: t('transport.selectRoute') }, ...providerRoutes.map((r) => ({ value: r.id, label: `${r.route_code} — ${name(r)}` }))]} />
                {form.data.transport_route_id && (
                    <>
                        <SelectField label={t('transport.boardingStop')} value={form.data.boarding_stop_id} onChange={(v) => form.setData('boarding_stop_id', v)} error={form.errors.boarding_stop_id} options={stopOptions('pickup_allowed')} />
                        <SelectField label={t('transport.eveningStop')} value={form.data.evening_stop_id} onChange={(v) => form.setData('evening_stop_id', v)} error={form.errors.evening_stop_id} options={stopOptions('dropoff_allowed')} />
                    </>
                )}
                <TextField label={t('transport.validFrom')} type="date" required value={form.data.valid_from} onChange={(v) => form.setData('valid_from', v)} error={form.errors.valid_from} />
                <TextField label={t('transport.validUntil')} type="date" value={form.data.valid_until} onChange={(v) => form.setData('valid_until', v)} error={form.errors.valid_until} />
                <SelectField label={t('transport.status')} required value={form.data.status} onChange={(v) => form.setData('status', v as typeof form.data.status)} error={form.errors.status}
                    options={PASS_STATUSES.map((v) => ({ value: v, label: label('statuses', v) }))} />
                <NotesField value={form.data.notes} onChange={(v) => form.setData('notes', v)} error={form.errors.notes} />
            </Section>
        </FormShell>
    );
}
