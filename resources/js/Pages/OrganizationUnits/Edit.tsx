import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { Head, Link, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { OrganizationUnit, OrganizationUnitStatus } from '@/types/organizationUnit';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

interface UnitTypeOption {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
}

interface ParentOption {
    id: string;
    name_en: string;
    name_am: string | null;
    code: string;
    depth: number;
}

interface Props {
    unit: OrganizationUnit;
    unitTypes: UnitTypeOption[];
    statusOptions: Array<{ value: string; label: string }>;
    parentOptions: ParentOption[];
}

export default function OrganizationUnitsEdit({
    unit,
    unitTypes,
    statusOptions,
    parentOptions,
}: Props) {
    const { t } = useLocale();
    const { data, setData, patch, processing, errors } = useForm({
        organization_id: unit.organization_id,
        parent_unit_id: unit.parent_unit_id,
        organization_unit_type_id: unit.organization_unit_type_id ?? '',
        code: unit.code,
        name_en: unit.name_en,
        name_am: unit.name_am ?? '',
        description_en: unit.description_en ?? '',
        description_am: unit.description_am ?? '',
        status: unit.status,
        effective_from: unit.effective_from ?? '',
        effective_to: unit.effective_to ?? '',
        sort_order: unit.sort_order,
    });

    const inputCls =
        'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100';

    const noUnitTypes = unitTypes.length === 0;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={`${t('organizationUnits.editOrganizationUnit')}: ${unit.name_en}`}
                />
            }
        >
            <Head title={t('organizationUnits.editOrganizationUnit')} />

            <div className="w-full">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        patch(route('organization-units.update', unit.id));
                    }}
                    className="w-full space-y-6 rounded-2xl border border-gray-200 bg-white p-4 sm:p-6 lg:p-8 dark:border-slate-800 dark:bg-slate-900"
                >
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {/* Organization — locked */}
                        <div>
                            <InputLabel value={`${t('organizationUnits.organization')} *`} />
                            <div className={`${inputCls} bg-gray-50 text-gray-600 dark:bg-slate-800 dark:text-slate-400`}>
                                {unit.organization?.name_en ?? unit.organization_id}
                                {unit.organization?.code && (
                                    <span className="ml-1 font-mono text-xs text-gray-400">
                                        ({unit.organization.code})
                                    </span>
                                )}
                            </div>
                            <input type="hidden" value={data.organization_id} readOnly />
                            <InputError message={errors.organization_id} />
                        </div>

                        {/* Unit Type */}
                        <div>
                            <InputLabel value={`${t('organizationUnits.unitType')} *`} />
                            {noUnitTypes ? (
                                <p className="mt-1 rounded-md border border-yellow-300 bg-yellow-50 px-3 py-2 text-sm text-yellow-800 dark:border-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-300">
                                    {t('organizationUnitTypes.createUnitTypesFirst')}
                                </p>
                            ) : (
                                <select
                                    className={inputCls}
                                    value={data.organization_unit_type_id}
                                    onChange={(e) => setData('organization_unit_type_id', e.target.value)}
                                >
                                    <option value="">{t('organizationUnitTypes.selectUnitType')}</option>
                                    {unitTypes.map((ut) => (
                                        <option key={ut.id} value={ut.id}>
                                            {ut.name_en}
                                        </option>
                                    ))}
                                </select>
                            )}
                            <InputError message={errors.organization_unit_type_id} />
                        </div>

                        {/* Parent Unit — optional, filtered to same org */}
                        <div>
                            <InputLabel value={t('organizationUnits.parentUnitOptional')} />
                            <select
                                className={inputCls}
                                value={data.parent_unit_id ?? ''}
                                onChange={(e) =>
                                    setData('parent_unit_id', e.target.value || null)
                                }
                            >
                                <option value="">{t('organizationUnits.noParentUnit')}</option>
                                {parentOptions
                                    .filter((opt) => opt.id !== unit.id)
                                    .map((opt) => (
                                        <option key={opt.id} value={opt.id}>
                                            {'—'.repeat(opt.depth)} {opt.name_en} ({opt.code})
                                        </option>
                                    ))}
                            </select>
                            <InputError message={errors.parent_unit_id} />
                        </div>

                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {/* Code */}
                        <div>
                            <InputLabel value={t('organizationUnits.unitCode')} />
                            <TextInput
                                className="w-full"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                            />
                            <InputError message={errors.code} />
                        </div>

                        {/* Name EN */}
                        <div>
                            <InputLabel value={`${t('organizationUnits.nameEn')} *`} />
                            <TextInput
                                className="w-full"
                                value={data.name_en}
                                onChange={(e) => setData('name_en', e.target.value)}
                                required
                            />
                            <InputError message={errors.name_en} />
                        </div>

                        {/* Name AM */}
                        <div>
                            <InputLabel value={t('organizationUnits.nameAm')} />
                            <TextInput
                                className="w-full"
                                value={data.name_am}
                                onChange={(e) => setData('name_am', e.target.value)}
                            />
                            <InputError message={errors.name_am} />
                        </div>

                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        {/* Status */}
                        <div>
                            <InputLabel value={`${t('common.status')} *`} />
                            <select
                                className={inputCls}
                                value={data.status}
                                onChange={(e) => setData('status', e.target.value as OrganizationUnitStatus)}
                            >
                                {statusOptions.map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.status} />
                        </div>

                        {/* Sort Order */}
                        <div>
                            <InputLabel value={t('organizationUnits.sortOrder')} />
                            <TextInput
                                type="number"
                                className="w-full"
                                value={String(data.sort_order)}
                                onChange={(e) => setData('sort_order', Number(e.target.value))}
                            />
                            <InputError message={errors.sort_order} />
                        </div>

                        {/* Effective From */}
                        <div>
                            <InputLabel value={t('organizationUnits.effectiveFrom')} />
                            <LocalizedDatePicker
                                className="w-full"
                                value={data.effective_from}
                                onChange={(iso) => setData('effective_from', iso)}
                            />
                            <InputError message={errors.effective_from} />
                        </div>

                        {/* Effective To */}
                        <div>
                            <InputLabel value={t('organizationUnits.effectiveTo')} />
                            <LocalizedDatePicker
                                className="w-full"
                                value={data.effective_to}
                                onChange={(iso) => setData('effective_to', iso)}
                            />
                            <InputError message={errors.effective_to} />
                        </div>
                    </div>

                    {/* Descriptions */}
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <InputLabel value={t('organizationUnits.descriptionEn')} />
                            <textarea
                                className={inputCls}
                                rows={4}
                                value={data.description_en}
                                onChange={(e) => setData('description_en', e.target.value)}
                            />
                            <InputError message={errors.description_en} />
                        </div>

                        <div>
                            <InputLabel value={t('organizationUnits.descriptionAm')} />
                            <textarea
                                className={inputCls}
                                rows={4}
                                value={data.description_am}
                                onChange={(e) => setData('description_am', e.target.value)}
                            />
                            <InputError message={errors.description_am} />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center justify-end gap-3 border-t border-gray-100 pt-5 dark:border-slate-800">
                        <PrimaryButton type="submit" disabled={processing}>
                            {processing ? t('common.saving') : t('common.saveChanges')}
                        </PrimaryButton>
                        <Link
                            href={route('organization-units.show', unit.id)}
                            className="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400"
                        >
                            {t('common.cancel')}
                        </Link>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
