import { useMemo, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { Button, FormField, Select } from '@euisis/ui';

export type Option = { value: string; label: string };
export type OrganizationGrant = { organization_id: string; scope_type: 'self' | 'subtree' };

type Values = {
    organization_access: string;
    service_provider_id: string;
    organization_scopes: OrganizationGrant[];
};

/**
 * Where a partner application may read (organization bound, F2) and which
 * service provider it acts for (F16). Shared by the create and edit forms.
 *
 * `unreviewed` is only ever shown, never offered: it marks a legacy
 * application nobody has decided on yet, which reads no organization data.
 */
export default function OrganizationAccessFields({ values, onChange, organizations, providers, errors, disabled }: {
    values: Values;
    onChange: (values: Values) => void;
    organizations: Option[];
    providers: Option[];
    errors: Record<string, string>;
    disabled: boolean;
}) {
    const { t } = useLocale();
    const [pick, setPick] = useState('');
    const labels = useMemo(() => new Map(organizations.map((option) => [option.value, option.label])), [organizations]);
    const granted = new Set(values.organization_scopes.map((grant) => grant.organization_id));
    const grantError = errors.organization_scopes ?? Object.entries(errors).find(([key]) => key.startsWith('organization_scopes.'))?.[1];
    const accessOptions = ['scoped', 'city', ...(values.organization_access === 'unreviewed' ? ['unreviewed'] : [])];

    function addGrant() {
        if (pick === '' || granted.has(pick)) return;
        onChange({ ...values, organization_scopes: [...values.organization_scopes, { organization_id: pick, scope_type: 'subtree' }] });
        setPick('');
    }

    return (
        <fieldset disabled={disabled} className="grid min-w-0 gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <FormField label={t('apiManagement.organizationAccess')} description={t(`apiManagement.organizationAccessHint_${values.organization_access}`)} error={errors.organization_access}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={values.organization_access}
                            onChange={(event) => onChange({ ...values, organization_access: event.target.value })}>
                            {accessOptions.map((access) => <option key={access} value={access}>{t(`apiManagement.organizationAccess_${access}`)}</option>)}
                        </Select>
                    )}
                </FormField>
                <FormField label={t('apiManagement.serviceProviderBinding')} description={t('apiManagement.serviceProviderBindingHint')} error={errors.service_provider_id}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={values.service_provider_id}
                            onChange={(event) => onChange({ ...values, service_provider_id: event.target.value })}>
                            <option value="">{t('apiManagement.noProviderBinding')}</option>
                            {providers.map((provider) => <option key={provider.value} value={provider.value}>{provider.label}</option>)}
                        </Select>
                    )}
                </FormField>
            </div>

            {values.organization_access === 'scoped' && (
                <div className="min-w-0">
                    <p className="text-xs font-semibold text-(--app-muted-foreground)">{t('apiManagement.organizationGrants')}</p>
                    {values.organization_scopes.length === 0 ? (
                        <p className="mt-1 text-sm text-amber-700 dark:text-amber-400">{t('apiManagement.noOrganizationGrants')}</p>
                    ) : (
                        <ul className="mt-2 divide-y divide-(--app-border) rounded-control border border-(--app-border)">
                            {values.organization_scopes.map((grant, index) => (
                                <li key={grant.organization_id} className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                                    <span className="min-w-0 flex-1 truncate text-(--app-foreground)">{labels.get(grant.organization_id) ?? grant.organization_id}</span>
                                    <Select aria-label={t('apiManagement.grantType')} className="w-44" value={grant.scope_type}
                                        onChange={(event) => onChange({ ...values, organization_scopes: values.organization_scopes.map((row, i) => i === index ? { ...row, scope_type: event.target.value as OrganizationGrant['scope_type'] } : row) })}>
                                        <option value="subtree">{t('apiManagement.grantType_subtree')}</option>
                                        <option value="self">{t('apiManagement.grantType_self')}</option>
                                    </Select>
                                    <Button variant="outline" size="sm" onClick={() => onChange({ ...values, organization_scopes: values.organization_scopes.filter((_, i) => i !== index) })}>
                                        {t('common.remove')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="mt-2 flex flex-col gap-2 sm:flex-row">
                        <Select aria-label={t('apiManagement.addOrganizationGrant')} className="min-w-0 sm:flex-1" value={pick} onChange={(event) => setPick(event.target.value)}>
                            <option value="">{t('apiManagement.chooseOrganization')}</option>
                            {organizations.filter((option) => !granted.has(option.value)).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </Select>
                        <Button variant="outline" onClick={addGrant} disabled={pick === ''}>{t('apiManagement.addOrganizationGrant')}</Button>
                    </div>
                    {grantError && <p className="mt-1 text-xs text-red-600">{grantError}</p>}
                </div>
            )}
        </fieldset>
    );
}
