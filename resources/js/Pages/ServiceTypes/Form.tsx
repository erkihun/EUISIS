import type { InertiaFormProps } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import CodeRuleField from '@/Components/code-rules/CodeRuleField';

import { buttonClassName, cx, inputClassName as uiInputClassName } from '@/Components/ui';
type ServiceTypeFormData = {
    code: string;
    name_en: string;
    name_am: string;
    description: string;
    is_active: boolean;
};

export default function ServiceTypeForm({
    form,
    submitLabel,
    cancelHref,
    onSubmit,
    existingCode,
}: {
    form: InertiaFormProps<ServiceTypeFormData>;
    submitLabel: string;
    cancelHref: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    existingCode?: string;
}) {
    const { t } = useLocale();
    const inputClassName = cx(uiInputClassName, 'w-full');

    function renderError(field: keyof ServiceTypeFormData) {
        const message = form.errors[field];

        if (! message) {
            return null;
        }

        return <p className="text-sm text-red-600 dark:text-red-400">{message}</p>;
    }

    return (
        <form
            className="space-y-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-6"
            onSubmit={onSubmit}
        >
            <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                    <CodeRuleField
                        entityType="service_type"
                        value={form.data.code}
                        onChange={(v) => form.setData('code', v)}
                        fieldName="code"
                        label={t('serviceTypes.code')}
                        canManualOverride={false}
                        existingCode={existingCode}
                        preserveExistingCodeOnEdit={existingCode !== undefined}
                        error={form.errors.code}
                    />
                </div>
                <div className="space-y-2">
                    <label className="text-sm font-medium text-(--app-foreground)">
                        {t('common.status')}
                    </label>
                    <label className="flex items-center gap-2 rounded-lg border border-(--app-border-strong) px-3 py-2 text-sm text-(--app-foreground)">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) => form.setData('is_active', event.target.checked)}
                        />
                        <span>{form.data.is_active ? t('common.active') : t('common.inactive')}</span>
                    </label>
                    {renderError('is_active')}
                </div>
                <div className="space-y-2">
                    <label className="text-sm font-medium text-(--app-foreground)">
                        {t('serviceTypes.englishName')}
                    </label>
                    <input
                        className={inputClassName}
                        value={form.data.name_en}
                        onChange={(event) => form.setData('name_en', event.target.value)}
                    />
                    {renderError('name_en')}
                </div>
                <div className="space-y-2">
                    <label className="text-sm font-medium text-(--app-foreground)">
                        {t('serviceTypes.amharicName')}
                    </label>
                    <input
                        className={inputClassName}
                        value={form.data.name_am}
                        onChange={(event) => form.setData('name_am', event.target.value)}
                    />
                    {renderError('name_am')}
                </div>
            </div>

            <div className="space-y-2">
                <label className="text-sm font-medium text-(--app-foreground)">
                    {t('serviceTypes.description')}
                </label>
                <textarea
                    className={`${inputClassName} min-h-32`}
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                />
                {renderError('description')}
            </div>

            <div className="flex gap-3">
                <button
                    type="submit"
                    className={buttonClassName({ variant: 'primary' })}
                    disabled={form.processing}
                >
                    {submitLabel}
                </button>
                <Link
                    href={cancelHref}
                    className="rounded-lg border border-(--app-border-strong) px-4 py-2 text-sm font-medium text-(--app-foreground)"
                >
                    {t('common.cancel')}
                </Link>
            </div>
        </form>
    );
}
