import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import CodeRuleField from '@/Components/code-rules/CodeRuleField';

type OrgType = {
    id: string;
    code: string;
    prefix: string | null;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    description_am: string | null;
    is_active: boolean;
    sort_order: number;
    level_order: number;
    category: string | null;
    parent_allowed_types: string[] | null;
};

const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500';
const labelCls = 'block text-xs font-medium text-gray-600 dark:text-slate-400';

const CATEGORIES = [
    { value: 'root', labelKey: 'categoryRoot' },
    { value: 'functional', labelKey: 'categoryFunctional' },
    { value: 'geographic', labelKey: 'categoryGeographic' },
    { value: 'service_provider', labelKey: 'categoryServiceProvider' },
    { value: 'independent', labelKey: 'categoryIndependent' },
    { value: 'other', labelKey: 'categoryOther' },
] as const;

function Field({
    label,
    help,
    error,
    children,
}: {
    label: string;
    help?: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className={labelCls}>{label}</label>
            <div className="mt-1">{children}</div>
            {help && <p className="mt-1 text-xs text-gray-500 dark:text-slate-500">{help}</p>}
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

export default function EditOrganizationType({
    type,
    allTypes,
}: {
    type: OrgType;
    allTypes: { code: string; name_en: string }[];
}) {
    const { t } = useLocale();

    const form = useForm<{
        code: string;
        prefix: string;
        name_en: string;
        name_am: string;
        description_en: string;
        description_am: string;
        sort_order: number;
        level_order: number;
        category: string;
        parent_allowed_types: string[];
        is_active: boolean;
    }>({
        code: type.code,
        prefix: type.prefix ?? '',
        name_en: type.name_en,
        name_am: type.name_am ?? '',
        description_en: type.description_en ?? '',
        description_am: type.description_am ?? '',
        sort_order: type.sort_order,
        level_order: type.level_order ?? 1,
        category: type.category ?? '',
        parent_allowed_types: type.parent_allowed_types ?? [],
        is_active: type.is_active,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.patch(route('organization-types.update', type.id));
    }

    function toggleParentType(code: string) {
        const current = form.data.parent_allowed_types;
        form.setData(
            'parent_allowed_types',
            current.includes(code) ? current.filter((c) => c !== code) : [...current, code],
        );
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('organizationTypes.editTitle')}
                    description={`${t('organizationTypes.editPrefix')} ${type.name_en}`}
                />
            }
        >
            <Head title={t('organizationTypes.editTitle')} />

            <div className="mx-auto max-w-2xl">
                <form
                    onSubmit={submit}
                    className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
                >
                    <div className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <CodeRuleField
                                entityType="organization_type"
                                value={form.data.code}
                                onChange={(v) => form.setData('code', v)}
                                fieldName="code"
                                label={t('organizationTypes.code')}
                                canManualOverride={false}
                                existingCode={type.code}
                                preserveExistingCodeOnEdit
                                error={form.errors.code}
                            />
                            <Field
                                label={t('organizationTypes.prefix')}
                                help={t('organizationTypes.prefixHelp')}
                                error={form.errors.prefix}
                            >
                                <input
                                    className={inputCls}
                                    placeholder={t('organizationTypes.prefixPlaceholder')}
                                    value={form.data.prefix}
                                    onChange={(e) => form.setData('prefix', e.target.value.toUpperCase())}
                                />
                            </Field>
                            <Field
                                label={t('organizationTypes.levelOrder')}
                                help={t('organizationTypes.levelOrderHelp')}
                                error={form.errors.level_order}
                            >
                                <input
                                    type="number"
                                    min={1}
                                    className={inputCls}
                                    value={form.data.level_order}
                                    onChange={(e) =>
                                        form.setData('level_order', parseInt(e.target.value, 10) || 1)
                                    }
                                />
                            </Field>
                            <Field
                                label={t('organizationTypes.category')}
                                error={form.errors.category}
                            >
                                <select
                                    className={inputCls}
                                    value={form.data.category}
                                    onChange={(e) => form.setData('category', e.target.value)}
                                >
                                    <option value="">{t('organizationTypes.categoryPlaceholder')}</option>
                                    {CATEGORIES.map((c) => (
                                        <option key={c.value} value={c.value}>
                                            {t(`organizationTypes.${c.labelKey}`)}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label={t('organizationTypes.sortOrder')} error={form.errors.sort_order}>
                                <input
                                    type="number"
                                    className={inputCls}
                                    value={form.data.sort_order}
                                    onChange={(e) =>
                                        form.setData('sort_order', parseInt(e.target.value, 10) || 0)
                                    }
                                />
                            </Field>
                        </div>

                        <Field label={t('organizationTypes.nameEn')} error={form.errors.name_en}>
                            <input
                                className={inputCls}
                                value={form.data.name_en}
                                onChange={(e) => form.setData('name_en', e.target.value)}
                            />
                        </Field>

                        <Field label={t('organizationTypes.nameAm')} error={form.errors.name_am}>
                            <input
                                className={inputCls}
                                placeholder={t('organizations.fullNameAmPlaceholder')}
                                value={form.data.name_am}
                                onChange={(e) => form.setData('name_am', e.target.value)}
                            />
                        </Field>

                        {allTypes.length > 0 && (
                            <Field
                                label={t('organizationTypes.parentAllowedTypes')}
                                help={t('organizationTypes.parentAllowedTypesHelp')}
                                error={form.errors.parent_allowed_types}
                            >
                                <div className="mt-1 max-h-48 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-slate-700">
                                    {allTypes.map((ot) => (
                                        <label
                                            key={ot.code}
                                            className="flex cursor-pointer items-center gap-2 rounded px-2 py-1 hover:bg-gray-50 dark:hover:bg-slate-800"
                                        >
                                            <input
                                                type="checkbox"
                                                className="h-3.5 w-3.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-slate-600"
                                                checked={form.data.parent_allowed_types.includes(ot.code)}
                                                onChange={() => toggleParentType(ot.code)}
                                            />
                                            <span className="font-mono text-xs text-gray-500 dark:text-slate-400">
                                                {ot.code}
                                            </span>
                                            <span className="text-xs text-gray-700 dark:text-slate-300">
                                                {ot.name_en}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            </Field>
                        )}

                        <Field label={t('organizationTypes.descriptionEn')} error={form.errors.description_en}>
                            <textarea
                                className={inputCls}
                                rows={2}
                                value={form.data.description_en}
                                onChange={(e) => form.setData('description_en', e.target.value)}
                            />
                        </Field>

                        <Field label={t('organizationTypes.descriptionAm')} error={form.errors.description_am}>
                            <textarea
                                className={inputCls}
                                rows={2}
                                placeholder={t('organizationTypes.descriptionAmPlaceholder')}
                                value={form.data.description_am}
                                onChange={(e) => form.setData('description_am', e.target.value)}
                            />
                        </Field>

                        <div className="flex items-center gap-2">
                            <input
                                id="is_active"
                                type="checkbox"
                                className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-slate-600"
                                checked={form.data.is_active}
                                onChange={(e) => form.setData('is_active', e.target.checked)}
                            />
                            <label
                                htmlFor="is_active"
                                className="text-sm text-gray-700 dark:text-slate-300"
                            >
                                {t('organizationTypes.isActive')}
                            </label>
                        </div>
                    </div>

                    <div className="mt-6 flex items-center justify-end gap-3 border-t border-gray-100 pt-5 dark:border-slate-800">
                        <Link
                            href={route('organization-types.index')}
                            className="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            {t('common.cancel')}
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-900"
                        >
                            {form.processing ? t('common.saving') : t('organizationTypes.saveChanges')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
