import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
const inputCls = cx(inputClassName, 'w-full');
const labelCls = 'block text-xs font-medium text-(--app-muted-foreground)';

type OrgUnitType = {
    id: string;
    code: string;
    prefix: string | null;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    description_am: string | null;
    sort_order: number;
    is_active: boolean;
};

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

export default function OrganizationUnitTypesEdit({ type }: { type: OrgUnitType }) {
    const { t } = useLocale();

    const form = useForm({
        code: type.code,
        prefix: type.prefix ?? '',
        name_en: type.name_en,
        name_am: type.name_am ?? '',
        description_en: type.description_en ?? '',
        description_am: type.description_am ?? '',
        sort_order: type.sort_order,
        is_active: type.is_active,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.patch(route('organization-unit-types.update', type.id));
    }

    return (
        <AuthenticatedLayout contentWidth="form"
            header={
                <PageHeader
                    title={`${t('organizationUnitTypes.editOrganizationUnitType')}: ${type.name_en}`}
                    description={t('organizationUnitTypes.editDescription')}
                />
            }
        >
            <Head title={t('organizationUnitTypes.editOrganizationUnitType')} />

            <div>
                <form
                    onSubmit={submit}
                    className="rounded-panel border border-(--app-border) bg-(--app-surface) p-6"
                >
                    <div className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <Field label={t('organizationUnitTypes.code')} error={form.errors.code}>
                                <input
                                    className={inputCls}
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value)}
                                />
                            </Field>
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
                                className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary"
                                checked={form.data.is_active}
                                onChange={(e) => form.setData('is_active', e.target.checked)}
                            />
                            <label
                                htmlFor="is_active"
                                className="text-sm text-(--app-foreground)"
                            >
                                {t('organizationUnitTypes.isActive')}
                            </label>
                        </div>
                    </div>

                    <div className="mt-6 flex items-center justify-end gap-3 border-t border-(--app-border) pt-5">
                        <Link
                            href={route('organization-unit-types.index')}
                            className="rounded-lg px-4 py-2 text-sm font-medium text-(--app-foreground) hover:bg-(--app-surface-muted)"
                        >
                            {t('common.cancel')}
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {form.processing ? t('common.saving') : t('common.saveChanges')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
