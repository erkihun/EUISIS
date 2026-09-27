import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { FormField, Input, Select } from '@euisis/ui';

type Values = {
    name: string; code: string; owner_institution: string; contact_person: string;
    contact_email: string; callback_url: string; rate_limit_per_minute: number;
    status: string; allowed_ips: string[];
};

/** Application details shared by the create (index) and edit (show) forms. */
export default function ApplicationFields({ values, onChange, errors, disabled }: {
    values: Values; onChange: (values: Values) => void; errors: Record<string, string>; disabled: boolean;
}) {
    const { t } = useLocale();
    const [ipText, setIpText] = useState(values.allowed_ips.join(', '));
    const fields = [
        ['name', 'name', 'text', 255], ['code', 'code', 'text', 64],
        ['owner_institution', 'ownerInstitution', 'text', 255], ['contact_person', 'contactPerson', 'text', 255],
        ['contact_email', 'contactEmail', 'email', 255], ['callback_url', 'callbackUrl', 'url', 2048],
    ] as const;
    // allowed_ips errors arrive per entry (allowed_ips.0); show the first one under the field.
    const ipError = errors.allowed_ips ?? Object.entries(errors).find(([key]) => key.startsWith('allowed_ips.'))?.[1];

    return (
        <fieldset disabled={disabled} className="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {fields.map(([key, label, type, max]) => {
                const required = key === 'name' || key === 'code';
                return (
                    <FormField key={key} label={t(`apiManagement.${label}`)} required={required} error={errors[key]}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} type={type} maxLength={max} required={required}
                                className={key === 'code' ? 'font-mono' : undefined} value={values[key]} onChange={(event) => onChange({ ...values, [key]: event.target.value })} />
                        )}
                    </FormField>
                );
            })}
            <FormField label={t('apiManagement.rateLimit')} required description={t('apiManagement.rateLimitHint')} error={errors.rate_limit_per_minute}>
                {({ id, describedBy, invalid }) => (
                    <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} type="number" required min={1} max={10000} className="tabular-nums"
                        value={values.rate_limit_per_minute} onChange={(event) => onChange({ ...values, rate_limit_per_minute: Number(event.target.value) })} />
                )}
            </FormField>
            <FormField label={t('common.status')} error={errors.status}>
                {({ id, describedBy, invalid }) => (
                    <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={values.status} onChange={(event) => onChange({ ...values, status: event.target.value })}>
                        {['active', 'suspended', 'revoked'].map((status) => <option key={status} value={status}>{t(`common.${status}`)}</option>)}
                    </Select>
                )}
            </FormField>
            <FormField className="sm:col-span-2 xl:col-span-3" label={t('apiManagement.ipAllowlist')} description={t('apiManagement.anyIp')} error={ipError}>
                {({ id, describedBy, invalid }) => (
                    <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} className="font-mono" value={ipText} placeholder={t('apiManagement.ipAllowlistHint')}
                        onChange={(event) => {
                            setIpText(event.target.value);
                            onChange({ ...values, allowed_ips: event.target.value.split(',').map((ip) => ip.trim()).filter(Boolean) });
                        }} />
                )}
            </FormField>
        </fieldset>
    );
}
