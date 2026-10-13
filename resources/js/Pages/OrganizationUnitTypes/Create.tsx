import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import CodeRuleField from '@/Components/code-rules/CodeRuleField';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
const inputCls = cx(inputClassName, 'w-full');
const labelCls = 'block text-xs font-medium text-[color:var(--app-muted-foreground)]';

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className={labelCls}>{label}</label>
            <div className="mt-1">{children}</div>
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

export default function OrganizationUnitTypesCreate() {
    const { t } = useLocale();

    const form = useForm({
        code: '',
        prefix: '',
        name_en: '',
        name_am: '',
        description_en: '',
        description_am: '',
        sort_order: 0,
        is_active: true,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(route('organization-unit-types.store'));
    }

    return (
        <AuthenticatedLayout contentWidth="form"
            header={
                <PageHeader
                    title={t('organizationUnitTypes.createOrganizationUnitType')}
                    description={t('organizationUnitTypes.createDescription')}
                />
            }
        >
            <Head title={t('organizationUnitTypes.createOrganizationUnitType')} />

            <div>
                <form
                    onSubmit={submit}
                    className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6"
                >
                    <div className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <CodeRuleField
                                entityType="organization_unit_type"
                                value={form.data.code}
                                onChange={(v) => form.setData('code', v)}
                                fieldName="code"
                                label={t('organizationUnitTypes.code')}
                                canManualOverride={false}
                                error={form.errors.code}
                            />
                            <Field label={t('organizationUnitTypes.prefix')} error={form.errors.prefix}>
                                <input
                                    className={inputCls}
                                    value={form.data.prefix}
                                    placeholder="e.g. DEPT"
                                    onChange={(e) => form.setData('prefix', e.target.value)}
                                />
                            </Field>
                        </div>

                        <Field label={t('organizationUnitTypes.sortOrder')} error={form.errors.sort_order}>
                            <input
                                type="number"
                                className={inputCls}
                                value={form.data.sort_order}
                                onChange={(e) =>
                                    form.setData('sort_order', parseInt(e.target.value, 10) || 0)
                                }
                            />
                        </Field>

                        <Field label={`${t('organizationUnitTypes.nameEn')} *`} error={form.errors.name_en}>
                            <input
                                className={inputCls}
                                value={form.data.name_en}
                                onChange={(e) => form.setData('name_en', e.target.value)}
                                required
                            />
                        </Field>

                        <Field label={t('organizationUnitTypes.nameAm')} error={form.errors.name_am}>
                            <input
                                className={inputCls}
                                value={form.data.name_am}
                                onChange={(e) => form.setData('name_am', e.target.value)}
                            />
                        </Field>

                        <Field label={t('organizationUnitTypes.descriptionEn')} error={form.errors.description_en}>
                            <textarea
                                className={inputCls}
                                rows={2}
                                value={form.data.description_en}
                                onChange={(e) => form.setData('description_en', e.target.value)}
                            />
                        </Field>

                        <Field label={t('organizationUnitTypes.descriptionAm')} error={form.errors.description_am}>
                            <textarea
                                className={inputCls}
                                rows={2}
                                value={form.data.description_am}
                                onChange={(e) => form.setData('description_am', e.target.value)}
                            />
                        </Field>

                        <div className="flex items-center gap-2">
                            <input
                                id="is_active"
                                type="checkbox"
                                className="h-4 w-4 rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]"
                                checked={form.data.is_active}
                                onChange={(e) => form.setData('is_active', e.target.checked)}
                            />
                            <label
                                htmlFor="is_active"
                                className="text-sm text-[color:var(--app-foreground)]"
                            >
                                {t('organizationUnitTypes.isActive')}
                            </label>
                        </div>
                    </div>

                    <div className="mt-6 flex items-center justify-end gap-3 border-t border-[color:var(--app-border)] pt-5">
                        <Link
                            href={route('organization-unit-types.index')}
                            className="rounded-lg px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
                        >
                            {t('common.cancel')}
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {form.processing ? t('common.saving') : t('organizationUnitTypes.createOrganizationUnitType')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
