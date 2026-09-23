import { useId, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

type Values = {
    name: string; code: string; owner_institution: string; contact_person: string;
    contact_email: string; callback_url: string; rate_limit_per_minute: number;
    status: string; allowed_ips: string[];
};

export default function ApplicationFields({ values, onChange, errors, disabled }: {
    values: Values; onChange: (values: Values) => void; errors: Record<string, string>; disabled: boolean;
}) {
    const { t } = useLocale();
    const id = useId();
    const [ipText, setIpText] = useState(values.allowed_ips.join(', '));
    const cls = 'w-full min-w-0 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
    const fields = [
        ['name', 'name', 'text', 255], ['code', 'code', 'text', 64],
        ['owner_institution', 'ownerInstitution', 'text', 255], ['contact_person', 'contactPerson', 'text', 255],
        ['contact_email', 'contactEmail', 'email', 255], ['callback_url', 'callbackUrl', 'url', 2048],
    ] as const;
    return <fieldset disabled={disabled} className="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {fields.map(([key, label, type, max]) => <div key={key} className="min-w-0">
            <label htmlFor={`${id}-${key}`} className="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">{t(`apiManagement.${label}`)}</label>
            <input id={`${id}-${key}`} className={cls} type={type} maxLength={max} required={key === 'name' || key === 'code'} value={values[key]} onChange={e => onChange({ ...values, [key]: e.target.value })} aria-invalid={Boolean(errors[key])} aria-describedby={errors[key] ? `${id}-${key}-error` : undefined} />
            {errors[key] && <p id={`${id}-${key}-error`} className="mt-1 text-xs text-red-600 dark:text-red-400">{errors[key]}</p>}
        </div>)}
        <label className="space-y-1 text-sm font-medium text-gray-700 dark:text-slate-300">{t('apiManagement.rateLimit')}
            <input className={cls} type="number" required min={1} max={10000} value={values.rate_limit_per_minute} onChange={e => onChange({ ...values, rate_limit_per_minute: Number(e.target.value) })} />
        </label>
        <label className="space-y-1 text-sm font-medium text-gray-700 dark:text-slate-300">{t('common.status')}
            <select className={cls} value={values.status} onChange={e => onChange({ ...values, status: e.target.value })}>
                {['active', 'suspended', 'revoked'].map(status => <option key={status} value={status}>{t(`common.${status}`)}</option>)}
            </select>
        </label>
        <label className="space-y-1 text-sm font-medium text-gray-700 dark:text-slate-300 sm:col-span-2 xl:col-span-3">{t('apiManagement.ipAllowlist')}
            <input className={cls} value={ipText} placeholder={t('apiManagement.ipAllowlistHint')} onChange={e => {
                setIpText(e.target.value);
                onChange({ ...values, allowed_ips: e.target.value.split(',').map(ip => ip.trim()).filter(Boolean) });
            }} />
            <span className="block text-xs font-normal text-gray-500 dark:text-slate-400">{t('apiManagement.anyIp')}</span>
        </label>
    </fieldset>;
}
