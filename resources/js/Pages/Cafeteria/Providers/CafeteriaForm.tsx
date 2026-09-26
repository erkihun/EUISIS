import FormSection from '@/Components/FormSection';
import { FormField } from '@euisis/ui';
import { inputCls, SelectOptions, useNames, type NamePair } from '@/Components/Cafeteria/PolicyUi';
import { useLocale } from '@/hooks/useLocale';
import { Link } from '@inertiajs/react';
import type { FormEvent } from 'react';

export type OrgOption = { id: string; code: string; name_en: string; name_am: string | null };
export type PayeeOption = { id: string; provider_code: string; name_en: string; name_am: string | null; status: string };
export type NetworkOption = { id: string; provider_id: string; code: string; name_en: string; name_am: string | null; status: string };
export type ParentOption = { id: string; provider_id: string | null; cafeteria_service_network_id: string | null; code: string; name_en: string; name_am: string | null; location_type: string | null };

export type CafeteriaFormData = {
    provider_mode: 'existing' | 'new';
    provider_id: string;
    provider_code: string;
    provider_name_en: string;
    provider_name_am: string;
    location_type: 'main' | 'branch' | 'service_point';
    network_mode: 'existing' | 'new';
    cafeteria_service_network_id: string;
    network_code: string;
    network_name_en: string;
    parent_cafeteria_id: string;
    code: string;
    name_en: string;
    name_am: string;
    organization_id: string;
    operational_status: 'open' | 'temporarily_closed' | 'closed';
    opening_time: string;
    closing_time: string;
    capacity: string;
    contact_person: string;
    phone_number: string;
    email: string;
    location: string;
    is_active: boolean;
};

type FormApi = {
    data: CafeteriaFormData;
    errors: Partial<Record<keyof CafeteriaFormData, string>>;
    processing: boolean;
    setData: <K extends keyof CafeteriaFormData>(key: K, value: CafeteriaFormData[K]) => void;
};

/**
 * Create/edit form for a cafeteria LOCATION. The provider (payee) and network
 * decide where it sits; the organization it "primarily serves" is only a label.
 * Who may eat here and at what subsidy is set under Organization Access,
 * Service Assignments and Service Policies.
 */
export default function CafeteriaForm({ form, mode, organizations, payees, networks, parents, lockedProvider, onSubmit, cancelHref }: {
    form: FormApi;
    mode: 'create' | 'edit';
    organizations: OrgOption[];
    payees: PayeeOption[];
    networks: NetworkOption[];
    parents: ParentOption[];
    /** Edit: the operating provider, fixed once set. */
    lockedProvider?: NamePair;
    onSubmit: (e: FormEvent) => void;
    cancelHref: string;
}) {
    const { t } = useLocale();
    const { label } = useNames();
    const d = form.data;
    const tt = (key: string) => t(`cafeteriaPolicy.${key}`);
    const creatingProvider = mode === 'create' && d.provider_mode === 'new';
    const providerId = creatingProvider ? null : (d.provider_id || null);
    const providerNetworks = networks.filter((n) => n.provider_id === providerId);
    const networkParents = parents.filter((p) => p.cafeteria_service_network_id === d.cafeteria_service_network_id);
    const payeeItems = payees.map((p) => ({ id: p.id, code: p.provider_code, name_en: p.name_en, name_am: p.name_am }));

    return (
        <form onSubmit={onSubmit} className="space-y-6">
            <FormSection title={tt('placementSection')} description={tt('cafeteriasDescription')}>
                {mode === 'create' ? (
                    <>
                        <FormField label={tt('providerMode')} error={form.errors.provider_mode}>
                            {({ id }) => (
                                <select id={id} className={inputCls} value={d.provider_mode} onChange={(e) => {
                                    const value = e.target.value as 'existing' | 'new';
                                    form.setData('provider_mode', value);
                                    // A new provider has no network yet: its main cafeteria starts one.
                                    if (value === 'new') { form.setData('network_mode', 'new'); form.setData('location_type', 'main'); }
                                }}>
                                    <option value="existing">{tt('providerExisting')}</option>
                                    <option value="new">{tt('providerNew')}</option>
                                </select>
                            )}
                        </FormField>
                        {d.provider_mode === 'existing' ? (
                            <FormField label={tt('provider')} required error={form.errors.provider_id}>
                                {({ id }) => (
                                    <select id={id} className={inputCls} value={d.provider_id} required onChange={(e) => {
                                        form.setData('provider_id', e.target.value);
                                        form.setData('cafeteria_service_network_id', '');
                                        form.setData('parent_cafeteria_id', '');
                                    }}>
                                        <SelectOptions items={payeeItems} placeholder={tt('selectPlaceholder')} />
                                    </select>
                                )}
                            </FormField>
                        ) : (
                            <>
                                <FormField label={tt('providerCode')} required error={form.errors.provider_code}>
                                    {({ id }) => <input id={id} className={inputCls} value={d.provider_code} required onChange={(e) => form.setData('provider_code', e.target.value.toUpperCase())} />}
                                </FormField>
                                <FormField label={tt('providerNameEn')} required error={form.errors.provider_name_en}>
                                    {({ id }) => <input id={id} className={inputCls} value={d.provider_name_en} required onChange={(e) => form.setData('provider_name_en', e.target.value)} />}
                                </FormField>
                                <FormField label={tt('providerNameAm')} error={form.errors.provider_name_am}>
                                    {({ id }) => <input id={id} className={inputCls} value={d.provider_name_am} onChange={(e) => form.setData('provider_name_am', e.target.value)} />}
                                </FormField>
                            </>
                        )}
                    </>
                ) : (
                    <FormField label={tt('provider')} error={form.errors.provider_id}>
                        {({ id }) => lockedProvider ? (
                            <input id={id} className={inputCls} value={label(lockedProvider)} disabled />
                        ) : (
                            // A legacy location without a provider may be adopted once.
                            <select id={id} className={inputCls} value={d.provider_id} onChange={(e) => { form.setData('provider_id', e.target.value); form.setData('cafeteria_service_network_id', ''); }}>
                                <SelectOptions items={payeeItems} placeholder={tt('selectPlaceholder')} />
                            </select>
                        )}
                    </FormField>
                )}

                <FormField label={tt('locationType')} required error={form.errors.location_type}>
                    {({ id }) => (
                        <select id={id} className={inputCls} value={d.location_type} disabled={creatingProvider} onChange={(e) => {
                            const value = e.target.value as CafeteriaFormData['location_type'];
                            form.setData('location_type', value);
                            if (value !== 'main') { form.setData('network_mode', 'existing'); } else { form.setData('parent_cafeteria_id', ''); }
                        }}>
                            {(['main', 'branch', 'service_point'] as const).map((type) => <option key={type} value={type}>{t(`cafeteriaPolicy.locationTypes.${type}`)}</option>)}
                        </select>
                    )}
                </FormField>

                {mode === 'create' && d.location_type === 'main' && (
                    <FormField label={tt('networkMode')} error={form.errors.network_mode}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={d.network_mode} disabled={creatingProvider} onChange={(e) => form.setData('network_mode', e.target.value as 'existing' | 'new')}>
                                <option value="existing">{tt('networkExisting')}</option>
                                <option value="new">{tt('networkNew')}</option>
                            </select>
                        )}
                    </FormField>
                )}

                {d.network_mode === 'new' && mode === 'create' ? (
                    <>
                        <FormField label={tt('networkCode')} required error={form.errors.network_code}>
                            {({ id }) => <input id={id} className={inputCls} value={d.network_code} required onChange={(e) => form.setData('network_code', e.target.value.toUpperCase())} />}
                        </FormField>
                        <FormField label={tt('networkName')} required error={form.errors.network_name_en}>
                            {({ id }) => <input id={id} className={inputCls} value={d.network_name_en} required onChange={(e) => form.setData('network_name_en', e.target.value)} />}
                        </FormField>
                    </>
                ) : (
                    <FormField label={tt('network')} required={d.location_type !== 'main'} error={form.errors.cafeteria_service_network_id}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={d.cafeteria_service_network_id} required={d.location_type !== 'main'}
                                onChange={(e) => { form.setData('cafeteria_service_network_id', e.target.value); form.setData('parent_cafeteria_id', ''); }}>
                                <SelectOptions items={providerNetworks} placeholder={tt('selectPlaceholder')} />
                            </select>
                        )}
                    </FormField>
                )}

                {d.location_type !== 'main' && (
                    <FormField label={tt('parentCafeteria')} error={form.errors.parent_cafeteria_id}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={d.parent_cafeteria_id} onChange={(e) => form.setData('parent_cafeteria_id', e.target.value)}>
                                <SelectOptions items={networkParents.map((p) => ({ ...p, name_en: `${p.name_en} — ${t(`cafeteriaPolicy.locationTypes.${p.location_type ?? 'main'}`)}` }))} placeholder={tt('mainCafeteria')} />
                            </select>
                        )}
                    </FormField>
                )}
            </FormSection>

            <FormSection title={tt('identitySection')}>
                <FormField label={t('cafeteria.providerCode')} required error={form.errors.code}>
                    {({ id }) => <input id={id} className={inputCls} value={d.code} required disabled={mode === 'edit'} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}
                </FormField>
                <FormField label={tt('nameEn')} required error={form.errors.name_en}>
                    {({ id }) => <input id={id} className={inputCls} value={d.name_en} required onChange={(e) => form.setData('name_en', e.target.value)} />}
                </FormField>
                <FormField label={tt('nameAm')} error={form.errors.name_am}>
                    {({ id }) => <input id={id} className={inputCls} value={d.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />}
                </FormField>
                <FormField label={tt('primarilyServes')} description={tt('primarilyServesHelp')} error={form.errors.organization_id}>
                    {({ id, describedBy }) => (
                        <select id={id} aria-describedby={describedBy} className={inputCls} value={d.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}>
                            <SelectOptions items={organizations} placeholder={tt('none')} />
                        </select>
                    )}
                </FormField>
            </FormSection>

            <FormSection title={tt('operationsSection')}>
                <FormField label={tt('operationalStatus')} required error={form.errors.operational_status}>
                    {({ id }) => (
                        <select id={id} className={inputCls} value={d.operational_status} onChange={(e) => form.setData('operational_status', e.target.value as CafeteriaFormData['operational_status'])}>
                            {(['open', 'temporarily_closed', 'closed'] as const).map((s) => <option key={s} value={s}>{t(`cafeteriaPolicy.statuses.${s}`)}</option>)}
                        </select>
                    )}
                </FormField>
                <FormField label={tt('capacity')} error={form.errors.capacity}>
                    {({ id }) => <input id={id} type="number" min={1} className={inputCls} value={d.capacity} onChange={(e) => form.setData('capacity', e.target.value)} />}
                </FormField>
                <FormField label={tt('openingTime')} error={form.errors.opening_time}>
                    {({ id }) => <input id={id} type="time" className={inputCls} value={d.opening_time} onChange={(e) => form.setData('opening_time', e.target.value)} />}
                </FormField>
                <FormField label={tt('closingTime')} error={form.errors.closing_time}>
                    {({ id }) => <input id={id} type="time" className={inputCls} value={d.closing_time} onChange={(e) => form.setData('closing_time', e.target.value)} />}
                </FormField>
                <label className="flex items-center gap-2 text-sm md:col-span-2">
                    <input type="checkbox" checked={d.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                    {tt('isActive')}
                </label>
            </FormSection>

            <FormSection title={tt('contactSection')}>
                <FormField label={tt('contactPerson')} error={form.errors.contact_person}>
                    {({ id }) => <input id={id} className={inputCls} value={d.contact_person} onChange={(e) => form.setData('contact_person', e.target.value)} />}
                </FormField>
                <FormField label={tt('phoneNumber')} error={form.errors.phone_number}>
                    {({ id }) => <input id={id} className={inputCls} value={d.phone_number} onChange={(e) => form.setData('phone_number', e.target.value)} />}
                </FormField>
                <FormField label={tt('email')} error={form.errors.email}>
                    {({ id }) => <input id={id} type="email" className={inputCls} value={d.email} onChange={(e) => form.setData('email', e.target.value)} />}
                </FormField>
                <FormField label={tt('location')} error={form.errors.location}>
                    {({ id }) => <input id={id} className={inputCls} value={d.location} onChange={(e) => form.setData('location', e.target.value)} />}
                </FormField>
            </FormSection>

            <div className="flex justify-end gap-3">
                <Link href={cancelHref} className="rounded-[var(--radius-control)] border border-[color:var(--app-border)] px-4 py-2 text-sm font-medium hover:bg-[color:var(--app-surface-muted)]">{tt('cancel')}</Link>
                <button type="submit" disabled={form.processing} className="rounded-[var(--radius-control)] bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60">{tt('save')}</button>
            </div>
        </form>
    );
}
