import type { InertiaFormProps } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

type EntitlementRuleFormData = {
    service_type_id: string;
    name: string;
    rule_definition: {
        quota_limit: string;
        period_days: string;
        notes: string;
    };
    is_active: boolean;
};

export default function EntitlementRuleForm({
    form,
    serviceTypes,
    submitLabel,
    cancelHref,
    onSubmit,
}: {
    form: InertiaFormProps<EntitlementRuleFormData>;
    serviceTypes: Array<{ id: string; name_en: string; name_am?: string | null }>;
    submitLabel: string;
    cancelHref: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
    const { t, locale } = useLocale();
    const inputClassName =
        'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    function renderError(field: 'service_type_id' | 'name' | 'rule_definition.quota_limit' | 'rule_definition.period_days' | 'rule_definition.notes' | 'is_active') {
        const message = form.errors[field];

        if (! message) {
            return null;
        }

        return <p className="text-sm text-red-600 dark:text-red-400">{message}</p>;
    }

    return (
        <form
            className="space-y-6 rounded-panel border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
            onSubmit={onSubmit}
        >
            <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                    <label className="text-sm font-medium text-gray-700 dark:text-slate-300">
                        {t('entitlementRules.serviceType')}
                    </label>
                    <select
                        className={inputClassName}
                        value={form.data.service_type_id}
                        onChange={(event) => form.setData('service_type_id', event.target.value)}
                    >
                        <option value="">{t('common.select')}</option>
                        {serviceTypes.map((serviceType) => (
                            <option key={serviceType.id} value={serviceType.id}>
                                {locale === 'am' ? (serviceType.name_am ?? serviceType.name_en) : serviceType.name_en}
                            </option>
                        ))}
                    </select>
                    {renderError('service_type_id')}
                </div>
                <div className="space-y-2">
                    <label className="text-sm font-medium text-gray-700 dark:text-slate-300">
                        {t('common.status')}
                    </label>
                    <label className="flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 dark:border-slate-700 dark:text-slate-300">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) => form.setData('is_active', event.target.checked)}
                        />
                        <span>{form.data.is_active ? t('common.active') : t('common.inactive')}</span>
                    </label>
                    {renderError('is_active')}
                </div>
                <div className="space-y-2 md:col-span-2">
                    <label className="text-sm font-medium text-gray-700 dark:text-slate-300">
                        {t('entitlementRules.ruleName')}
                    </label>
                    <input
                        className={inputClassName}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                    {renderError('name')}
                </div>
                <div className="space-y-2">
                    <label className="text-sm font-medium text-gray-700 dark:text-slate-300">
                        {t('entitlementRules.quotaLimit')}
                    </label>
                    <input
                        type="number"
                        min="0"
                        className={inputClassName}
                        value={form.data.rule_definition.quota_limit}
                        onChange={(event) => form.setData('rule_definition', {
                            ...form.data.rule_definition,
                            quota_limit: event.target.value,
                        })}
                    />
                    {renderError('rule_definition.quota_limit')}
                </div>
                <div className="space-y-2">
                    <label className="text-sm font-medium text-gray-700 dark:text-slate-300">
                        {t('entitlementRules.periodDays')}
                    </label>
                    <input
                        type="number"
                        min="1"
                        className={inputClassName}
                        value={form.data.rule_definition.period_days}
                        onChange={(event) => form.setData('rule_definition', {
                            ...form.data.rule_definition,
                            period_days: event.target.value,
                        })}
                    />
                    {renderError('rule_definition.period_days')}
                </div>
            </div>

            <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700 dark:text-slate-300">
                    {t('entitlementRules.notes')}
                </label>
                <textarea
                    className={`${inputClassName} min-h-32`}
                    value={form.data.rule_definition.notes}
                    onChange={(event) => form.setData('rule_definition', {
                        ...form.data.rule_definition,
                        notes: event.target.value,
                    })}
                />
                {renderError('rule_definition.notes')}
            </div>

            <div className="flex gap-3">
                <button
                    type="submit"
                    className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={form.processing}
                >
                    {submitLabel}
                </button>
                <Link
                    href={cancelHref}
                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-slate-700 dark:text-slate-200"
                >
                    {t('common.cancel')}
                </Link>
            </div>
        </form>
    );
}
