import { useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';

type Option = { id: string; name_en?: string; name_am?: string | null; provider_code?: string; employee_number?: string; full_name?: string; route_code?: string; vehicle_code?: string; plate_number?: string };

function optionLabel(option: Option) {
    return option.full_name
        ? `${option.employee_number ?? ''} ${option.full_name}`.trim()
        : `${option.provider_code ?? option.route_code ?? option.vehicle_code ?? option.plate_number ?? ''} ${option.name_en ?? ''}`.trim();
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <label className="space-y-1 text-sm">
            <span className="font-medium text-slate-700 dark:text-slate-200">{label}</span>
            {children}
            {error && <span className="block text-xs text-red-600 dark:text-red-400">{error}</span>}
        </label>
    );
}

const inputClass = 'w-full rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950';

export function TransportProviderForm({ provider, organizations = [] }: { provider?: any; organizations?: Option[] }) {
    const { t } = useLocale();
    const editing = Boolean(provider?.id);
    const initialScope = provider?.assigned_scope_type ?? 'self';
    const form = useForm({
        provider_code: provider?.provider_code ?? '',
        name_en: provider?.name_en ?? '',
        name_am: provider?.name_am ?? '',
        assigned_organization_id: initialScope === 'citywide' ? '' : provider?.assigned_organization?.id ?? '',
        assigned_scope_type: initialScope,
        contact_person: provider?.contact_person ?? '',
        phone_number: '',
        email: provider?.email ?? '',
        address: '',
        license_number: provider?.profile?.license_number ?? '',
        registration_number: provider?.profile?.registration_number ?? '',
        status: provider?.status ?? 'active',
        create_provider_user: false,
        user_name: '',
        user_email: '',
        username: '',
        user_password: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        editing ? form.patch(route('transport.providers.update', provider.id)) : form.post(route('transport.providers.store'));
    }

    function setCitywide(checked: boolean) {
        form.setData({
            ...form.data,
            assigned_scope_type: checked ? 'citywide' : 'self',
            assigned_organization_id: checked ? '' : form.data.assigned_organization_id,
        });
    }

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
            <Field label={t('transport.providerCode')} error={form.errors.provider_code}><input className={inputClass} value={form.data.provider_code} onChange={(e) => form.setData('provider_code', e.target.value)} /></Field>
            <Field label={t('transport.nameEn')} error={form.errors.name_en}><input className={inputClass} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} /></Field>
            <Field label={t('transport.nameAm')} error={form.errors.name_am}><input className={inputClass} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} /></Field>
            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                <input type="checkbox" checked={form.data.assigned_scope_type === 'citywide'} onChange={(e) => setCitywide(e.target.checked)} />
                <span className="font-medium text-slate-700 dark:text-slate-200">{t('transport.assignAllOrganizations')}</span>
            </label>
            {form.data.assigned_scope_type !== 'citywide' && <Field label={t('transport.organization')} error={form.errors.assigned_organization_id}><select className={inputClass} value={form.data.assigned_organization_id} onChange={(e) => form.setData('assigned_organization_id', e.target.value)}><option value="" />{organizations.map((item) => <option key={item.id} value={item.id}>{optionLabel(item)}</option>)}</select></Field>}
            <Field label={t('transport.scope')} error={form.errors.assigned_scope_type}><select className={inputClass} value={form.data.assigned_scope_type} onChange={(e) => form.setData('assigned_scope_type', e.target.value)}><option value="self">{t('transport.scopeSelf')}</option><option value="subtree">{t('transport.scopeSubtree')}</option><option value="citywide">{t('transport.scopeCitywide')}</option></select></Field>
            <Field label={t('transport.status')} error={form.errors.status}><select className={inputClass} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}><option value="active">active</option><option value="inactive">inactive</option><option value="suspended">suspended</option></select></Field>
            <Field label={t('transport.contactPerson')} error={form.errors.contact_person}><input className={inputClass} value={form.data.contact_person} onChange={(e) => form.setData('contact_person', e.target.value)} /></Field>
            <Field label={t('transport.phone')} error={form.errors.phone_number}><input className={inputClass} value={form.data.phone_number} onChange={(e) => form.setData('phone_number', e.target.value)} /></Field>
            <Field label={t('transport.email')} error={form.errors.email}><input className={inputClass} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></Field>
            <Field label={t('transport.licenseNumber')} error={form.errors.license_number}><input className={inputClass} value={form.data.license_number} onChange={(e) => form.setData('license_number', e.target.value)} /></Field>
            {!editing && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.create_provider_user} onChange={(e) => form.setData('create_provider_user', e.target.checked)} />{t('transport.createProviderUser')}</label>}
            {!editing && <Field label={t('transport.userName')} error={form.errors.user_name}><input className={inputClass} value={form.data.user_name} onChange={(e) => form.setData('user_name', e.target.value)} /></Field>}
            {!editing && <Field label={t('transport.userEmail')} error={form.errors.user_email}><input className={inputClass} value={form.data.user_email} onChange={(e) => form.setData('user_email', e.target.value)} /></Field>}
            <div className="sm:col-span-2"><button disabled={form.processing} className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">{editing ? t('transport.update') : t('transport.create')}</button></div>
        </form>
    );
}

export function TransportRouteAdminForm({ routeData, providers = [] }: { routeData?: any; providers?: Option[] }) {
    const { t } = useLocale();
    const editing = Boolean(routeData?.id);
    const form = useForm({ provider_id: routeData?.provider_id ?? '', route_code: routeData?.route_code ?? '', name_en: routeData?.name_en ?? '', name_am: routeData?.name_am ?? '', origin_en: routeData?.origin_en ?? '', destination_en: routeData?.destination_en ?? '', assigned_scope_type: routeData?.assigned_scope_type ?? 'self', is_active: routeData?.is_active ?? true });
    function submit(event: FormEvent) { event.preventDefault(); editing ? form.patch(route('transport.routes.update', routeData.id)) : form.post(route('transport.routes.store')); }
    return <form onSubmit={submit} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
        <Field label={t('transport.provider')} error={form.errors.provider_id}><select className={inputClass} value={form.data.provider_id} onChange={(e) => form.setData('provider_id', e.target.value)}><option value="" />{providers.map((item) => <option key={item.id} value={item.id}>{optionLabel(item)}</option>)}</select></Field>
        <Field label={t('transport.routeCode')} error={form.errors.route_code}><input className={inputClass} value={form.data.route_code} onChange={(e) => form.setData('route_code', e.target.value)} /></Field>
        <Field label={t('transport.nameEn')} error={form.errors.name_en}><input className={inputClass} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} /></Field>
        <Field label={t('transport.nameAm')} error={form.errors.name_am}><input className={inputClass} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} /></Field>
        <Field label={t('transport.origin')} error={form.errors.origin_en}><input className={inputClass} value={form.data.origin_en} onChange={(e) => form.setData('origin_en', e.target.value)} /></Field>
        <Field label={t('transport.destination')} error={form.errors.destination_en}><input className={inputClass} value={form.data.destination_en} onChange={(e) => form.setData('destination_en', e.target.value)} /></Field>
        <div className="sm:col-span-2"><button disabled={form.processing} className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">{editing ? t('transport.update') : t('transport.create')}</button></div>
    </form>;
}

export function TransportVehicleAdminForm({ vehicle, providers = [], routes = [] }: { vehicle?: any; providers?: Option[]; routes?: Option[] }) {
    const { t } = useLocale();
    const editing = Boolean(vehicle?.id);
    const form = useForm({ provider_id: vehicle?.provider_id ?? '', vehicle_code: vehicle?.vehicle_code ?? '', plate_number: vehicle?.plate_number ?? '', vehicle_type: vehicle?.vehicle_type ?? 'bus', capacity: vehicle?.capacity ?? '', status: vehicle?.status ?? 'active', assigned_route_id: vehicle?.assigned_route_id ?? '' });
    function submit(event: FormEvent) { event.preventDefault(); editing ? form.patch(route('transport.vehicles.update', vehicle.id)) : form.post(route('transport.vehicles.store')); }
    return <form onSubmit={submit} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
        <Field label={t('transport.provider')} error={form.errors.provider_id}><select className={inputClass} value={form.data.provider_id} onChange={(e) => form.setData('provider_id', e.target.value)}><option value="" />{providers.map((item) => <option key={item.id} value={item.id}>{optionLabel(item)}</option>)}</select></Field>
        <Field label={t('transport.vehicleCode')} error={form.errors.vehicle_code}><input className={inputClass} value={form.data.vehicle_code} onChange={(e) => form.setData('vehicle_code', e.target.value)} /></Field>
        <Field label={t('transport.plateNumber')} error={form.errors.plate_number}><input className={inputClass} value={form.data.plate_number} onChange={(e) => form.setData('plate_number', e.target.value)} /></Field>
        <Field label={t('transport.capacity')} error={form.errors.capacity}><input className={inputClass} value={form.data.capacity} onChange={(e) => form.setData('capacity', e.target.value)} /></Field>
        <Field label={t('transport.assignedRoute')} error={form.errors.assigned_route_id}><select className={inputClass} value={form.data.assigned_route_id} onChange={(e) => form.setData('assigned_route_id', e.target.value)}><option value="" />{routes.map((item) => <option key={item.id} value={item.id}>{optionLabel(item)}</option>)}</select></Field>
        <div className="sm:col-span-2"><button disabled={form.processing} className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">{editing ? t('transport.update') : t('transport.create')}</button></div>
    </form>;
}

export function TransportDriverAdminForm({ driver, providers = [], vehicles = [] }: { driver?: any; providers?: Option[]; vehicles?: Option[] }) {
    const { t } = useLocale();
    const editing = Boolean(driver?.id);
    const form = useForm({ provider_id: driver?.provider_id ?? '', full_name: driver?.full_name ?? '', license_number: driver?.license_number ?? '', status: driver?.status ?? 'active', assigned_vehicle_id: driver?.assigned_vehicle_id ?? '' });
    function submit(event: FormEvent) { event.preventDefault(); editing ? form.patch(route('transport.drivers.update', driver.id)) : form.post(route('transport.drivers.store')); }
    return <form onSubmit={submit} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
        <Field label={t('transport.provider')} error={form.errors.provider_id}><select className={inputClass} value={form.data.provider_id} onChange={(e) => form.setData('provider_id', e.target.value)}><option value="" />{providers.map((item) => <option key={item.id} value={item.id}>{optionLabel(item)}</option>)}</select></Field>
        <Field label={t('transport.driverName')} error={form.errors.full_name}><input className={inputClass} value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} /></Field>
        <Field label={t('transport.licenseNumber')} error={form.errors.license_number}><input className={inputClass} value={form.data.license_number} onChange={(e) => form.setData('license_number', e.target.value)} /></Field>
        <Field label={t('transport.assignedVehicle')} error={form.errors.assigned_vehicle_id}><select className={inputClass} value={form.data.assigned_vehicle_id} onChange={(e) => form.setData('assigned_vehicle_id', e.target.value)}><option value="" />{vehicles.map((item) => <option key={item.id} value={item.id}>{optionLabel(item)}</option>)}</select></Field>
        <div className="sm:col-span-2"><button disabled={form.processing} className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">{editing ? t('transport.update') : t('transport.create')}</button></div>
    </form>;
}

export function TransportPassAdminForm({ pass, providers = [], routes = [], employees = [] }: { pass?: any; providers?: Option[]; routes?: Option[]; employees?: Option[] }) {
    const { t } = useLocale();
    const editing = Boolean(pass?.id);
    const form = useForm({ employee_id: pass?.employee_id ?? '', provider_id: pass?.provider_id ?? '', transport_route_id: pass?.transport_route_id ?? '', valid_from: pass?.valid_from ?? '', valid_until: pass?.valid_until ?? '', status: pass?.status ?? 'active' });
    function submit(event: FormEvent) { event.preventDefault(); editing ? form.patch(route('transport.passes.update', pass.id)) : form.post(route('transport.passes.store')); }
    return <form onSubmit={submit} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
        <Field label={t('transport.employee')} error={form.errors.employee_id}><select className={inputClass} value={form.data.employee_id} onChange={(e) => form.setData('employee_id', e.target.value)}><option value="" />{employees.map((item) => <option key={item.id} value={item.id}>{optionLabel(item)}</option>)}</select></Field>
        <Field label={t('transport.provider')} error={form.errors.provider_id}><select className={inputClass} value={form.data.provider_id} onChange={(e) => form.setData('provider_id', e.target.value)}><option value="" />{providers.map((item) => <option key={item.id} value={item.id}>{optionLabel(item)}</option>)}</select></Field>
        <Field label={t('transport.route')} error={form.errors.transport_route_id}><select className={inputClass} value={form.data.transport_route_id} onChange={(e) => form.setData('transport_route_id', e.target.value)}><option value="" />{routes.map((item) => <option key={item.id} value={item.id}>{optionLabel(item)}</option>)}</select></Field>
        <Field label={t('transport.validFrom')} error={form.errors.valid_from}><input type="date" className={inputClass} value={form.data.valid_from} onChange={(e) => form.setData('valid_from', e.target.value)} /></Field>
        <Field label={t('transport.validUntil')} error={form.errors.valid_until}><input type="date" className={inputClass} value={form.data.valid_until} onChange={(e) => form.setData('valid_until', e.target.value)} /></Field>
        <Field label={t('transport.status')} error={form.errors.status}><select className={inputClass} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}><option value="active">active</option><option value="suspended">suspended</option><option value="expired">expired</option><option value="cancelled">cancelled</option></select></Field>
        <div className="sm:col-span-2"><button disabled={form.processing} className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">{editing ? t('transport.update') : t('transport.create')}</button></div>
    </form>;
}
