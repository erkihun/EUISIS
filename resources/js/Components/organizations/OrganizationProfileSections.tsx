import type { ReactNode } from 'react';
import FormSection from '@/Components/FormSection';
import LocationPicker from '@/Components/fieldWork/LocationPicker';
import type { MapConfig } from '@/Components/fieldWork/LocationMap';
import { useLocale } from '@/hooks/useLocale';
import { cx, inputClassName, textareaClassName } from '@/Components/ui';

/**
 * The parts of the organization form shared by Create and Edit: short names,
 * location (address and the GPS point with its check-in radius, which pre-fill
 * field work sent to the organization) and official contact.
 */

export type OrganizationProfile = {
    short_name_en: string;
    short_name_am: string;
    address_en: string;
    address_am: string;
    latitude: string;
    longitude: string;
    geofence_radius_m: string;
    public_contact_phone: string;
    official_email: string;
    website: string;
    po_box: string;
};

type Stored = { [K in keyof OrganizationProfile]?: string | number | null };

export function organizationProfile(organization?: Stored | null): OrganizationProfile {
    const value = (key: keyof OrganizationProfile) => {
        const raw = organization?.[key];
        return raw === null || raw === undefined ? '' : String(raw);
    };

    return {
        short_name_en: value('short_name_en'),
        short_name_am: value('short_name_am'),
        address_en: value('address_en'),
        address_am: value('address_am'),
        latitude: value('latitude'),
        longitude: value('longitude'),
        geofence_radius_m: value('geofence_radius_m'),
        public_contact_phone: value('public_contact_phone'),
        official_email: value('official_email'),
        website: value('website'),
        po_box: value('po_box'),
    };
}

/** Empty text is sent as null, so clearing a field clears the stored value. */
export function organizationProfilePayload(data: OrganizationProfile): Record<keyof OrganizationProfile, string | null> {
    return Object.fromEntries(
        Object.entries(data).map(([key, value]) => [key, value.trim() === '' ? null : value.trim()]),
    ) as Record<keyof OrganizationProfile, string | null>;
}

const inputCls = cx(inputClassName, 'w-full');
const textareaCls = cx(textareaClassName, 'w-full');

export function OrgField({ label, error, children, help, required }: { label: string; error?: string; children: ReactNode; help?: string; required?: boolean }) {
    return (
        <div>
            <label className="block text-xs font-medium text-(--app-muted-foreground)">
                {label}
                {required && <span className="ml-1 text-red-600" aria-hidden="true">*</span>}
            </label>
            <div className="mt-1">{children}</div>
            {help && <p className="mt-1 text-xs text-(--app-muted-foreground)">{help}</p>}
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

type SectionProps = {
    data: OrganizationProfile;
    errors: Partial<Record<string, string>>;
    set: (values: Partial<OrganizationProfile>) => void;
};

/** Short names sit with the full names in the identity section. */
export function ShortNameFields({ data, errors, set }: SectionProps) {
    const { t } = useLocale();

    return (
        <>
            <OrgField label={t('organizations.shortNameEn')} error={errors.short_name_en} help={t('organizations.shortNameHelp')}>
                <input className={inputCls} maxLength={50} placeholder="e.g. AACSB" value={data.short_name_en} onChange={(e) => set({ short_name_en: e.target.value })} />
            </OrgField>
            <OrgField label={t('organizations.shortNameAm')} error={errors.short_name_am}>
                <input className={inputCls} maxLength={50} value={data.short_name_am} onChange={(e) => set({ short_name_am: e.target.value })} />
            </OrgField>
        </>
    );
}

export function LocationSection({ data, errors, set, map, step }: SectionProps & { map: MapConfig; step: number }) {
    const { t } = useLocale();

    return (
        <FormSection id="organization-location" step={step} title={t('organizations.sectionLocation')} description={t('organizations.sectionLocationHelp')} grid={false}>
            <div className="grid gap-4 md:grid-cols-2">
                <OrgField label={t('organizations.addressEn')} error={errors.address_en}>
                    <textarea className={textareaCls} rows={2} maxLength={500} placeholder={t('organizations.addressPlaceholder')} value={data.address_en} onChange={(e) => set({ address_en: e.target.value })} />
                </OrgField>
                <OrgField label={t('organizations.addressAm')} error={errors.address_am}>
                    <textarea className={textareaCls} rows={2} maxLength={500} value={data.address_am} onChange={(e) => set({ address_am: e.target.value })} />
                </OrgField>
            </div>

            <div className="rounded-card border border-(--app-border) p-4">
                <p className="text-sm font-medium text-(--app-foreground)">{t('organizations.gpsPoint')}</p>
                <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{t('organizations.gpsPointHelp')}</p>
                <div className="mt-3">
                    <LocationPicker
                        config={map}
                        lat={data.latitude}
                        lng={data.longitude}
                        radius={data.geofence_radius_m}
                        onChange={(next) => set({ latitude: next.lat, longitude: next.lng, geofence_radius_m: next.radius })}
                    />
                </div>
                <div className="mt-3 grid gap-3 sm:grid-cols-3">
                    <OrgField label={t('organizations.latitude')} error={errors.latitude}>
                        <input className={inputCls} inputMode="decimal" maxLength={20} placeholder="9.0108" value={data.latitude} onChange={(e) => set({ latitude: e.target.value })} />
                    </OrgField>
                    <OrgField label={t('organizations.longitude')} error={errors.longitude}>
                        <input className={inputCls} inputMode="decimal" maxLength={20} placeholder="38.7613" value={data.longitude} onChange={(e) => set({ longitude: e.target.value })} />
                    </OrgField>
                    <OrgField label={t('organizations.checkInRadius')} error={errors.geofence_radius_m} help={t('organizations.checkInRadiusHelp').replace('{max}', String(map.maxRadius))}>
                        <input className={inputCls} inputMode="numeric" maxLength={6} placeholder="200" value={data.geofence_radius_m} onChange={(e) => set({ geofence_radius_m: e.target.value })} />
                    </OrgField>
                </div>
            </div>
        </FormSection>
    );
}

export function ContactSection({ data, errors, set, step }: SectionProps & { step: number }) {
    const { t } = useLocale();

    return (
        <FormSection id="organization-contact" step={step} title={t('organizations.sectionContact')} description={t('organizations.sectionContactHelp')}>
            <OrgField label={t('organizations.publicContactPhone')} error={errors.public_contact_phone} help={t('organizations.publicContactPhoneHelp')}>
                <input className={inputCls} type="tel" inputMode="tel" autoComplete="off" maxLength={32} placeholder="011 123 4567" value={data.public_contact_phone} onChange={(e) => set({ public_contact_phone: e.target.value })} />
            </OrgField>
            <OrgField label={t('organizations.officialEmail')} error={errors.official_email}>
                <input className={inputCls} type="email" inputMode="email" autoComplete="off" maxLength={255} placeholder="info@example.gov.et" value={data.official_email} onChange={(e) => set({ official_email: e.target.value })} />
            </OrgField>
            <OrgField label={t('organizations.website')} error={errors.website}>
                <input className={inputCls} type="text" inputMode="url" autoComplete="url" maxLength={255} placeholder="https://example.gov.et" value={data.website} onChange={(e) => set({ website: e.target.value })} />
            </OrgField>
            <OrgField label={t('organizations.poBox')} error={errors.po_box}>
                <input className={inputCls} maxLength={32} placeholder="1234" value={data.po_box} onChange={(e) => set({ po_box: e.target.value })} />
            </OrgField>
        </FormSection>
    );
}

/** Jump links to the numbered sections of a long organization form. */
export function SectionNav({ sections }: { sections: Array<{ id: string; label: string }> }) {
    const { t } = useLocale();

    return (
        <nav aria-label={t('organizations.formSections')} className="mb-6 flex flex-wrap gap-2">
            {sections.map((section, index) => (
                <a
                    key={section.id}
                    href={`#${section.id}`}
                    className="inline-flex items-center gap-1.5 rounded-full border border-(--app-border) px-3 py-1 text-xs font-medium text-(--app-muted-foreground) hover:border-primary hover:text-primary"
                >
                    <span className="flex h-4 w-4 items-center justify-center rounded-full bg-(--app-surface-muted) text-[10px]">{index + 1}</span>
                    {section.label}
                </a>
            ))}
        </nav>
    );
}
