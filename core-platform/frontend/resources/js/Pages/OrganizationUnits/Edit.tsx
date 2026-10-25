import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { OrganizationUnit, OrganizationUnitStatus } from '@/types/organizationUnit';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

import { actionLinkClassName, cx, inputClassName, Button, FieldError, FormLabel, Input } from '@/Components/ui';
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
        is_public_feedback_selectable: unit.is_public_feedback_selectable ?? false,
        effective_from: unit.effective_from ?? '',
        effective_to: unit.effective_to ?? '',
        sort_order: unit.sort_order,
    });

    const inputCls = cx(inputClassName, 'w-full');

    const noUnitTypes = unitTypes.length === 0;

    return (
        <AuthenticatedLayout contentWidth="form"
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
                    className="w-full space-y-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-4 sm:p-6 lg:p-8"
                >
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {/* Organization — locked */}
                        <div>
                            <FormLabel>{`${t('organizationUnits.organization')} *`}</FormLabel>
                            <div className={`${inputCls} bg-(--app-surface-muted) text-(--app-muted-foreground)`}>
                                {unit.organization?.name_en ?? unit.organization_id}
                                {unit.organization?.code && (
                                    <span className="ml-1 font-mono text-xs text-(--app-muted-foreground)">
                                        ({unit.organization.code})
                                    </span>
                                )}
                            </div>
                            <input type="hidden" value={data.organization_id} readOnly />
                            <FieldError>{errors.organization_id}</FieldError>
                        </div>

                        {/* Unit Type */}
                        <div>
                            <FormLabel>{`${t('organizationUnits.unitType')} *`}</FormLabel>
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
                            <FieldError>{errors.organization_unit_type_id}</FieldError>
                        </div>

                        {/* Parent Unit — optional, filtered to same org */}
                        <div>
                            <FormLabel>{t('organizationUnits.parentUnitOptional')}</FormLabel>
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
                            <FieldError>{errors.parent_unit_id}</FieldError>
                        </div>

                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {/* Code */}
                        <div>
                            <FormLabel>{t('organizationUnits.unitCode')}</FormLabel>
                            <Input
                                className="w-full"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                            />
                            <FieldError>{errors.code}</FieldError>
                        </div>

                        {/* Name EN */}
                        <div>
                            <FormLabel>{`${t('organizationUnits.nameEn')} *`}</FormLabel>
                            <Input
                                className="w-full"
                                value={data.name_en}
                                onChange={(e) => setData('name_en', e.target.value)}
                                required
                            />
                            <FieldError>{errors.name_en}</FieldError>
                        </div>

                        {/* Name AM */}
                        <div>
                            <FormLabel>{t('organizationUnits.nameAm')}</FormLabel>
                            <Input
                                className="w-full"
                                value={data.name_am}
                                onChange={(e) => setData('name_am', e.target.value)}
                            />
                            <FieldError>{errors.name_am}</FieldError>
                        </div>

                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        {/* Status */}
                        <div>
                            <FormLabel>{`${t('common.status')} *`}</FormLabel>
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
                            <FieldError>{errors.status}</FieldError>
                        </div>

                        {/* Sort Order */}
                        <div>
                            <FormLabel>{t('organizationUnits.sortOrder')}</FormLabel>
                            <Input
                                type="number"
                                className="w-full"
                                value={String(data.sort_order)}
                                onChange={(e) => setData('sort_order', Number(e.target.value))}
                            />
                            <FieldError>{errors.sort_order}</FieldError>
                        </div>

                        {/* Effective From */}
                        <div>
                            <FormLabel>{t('organizationUnits.effectiveFrom')}</FormLabel>
                            <LocalizedDatePicker
                                className="w-full"
                                value={data.effective_from}
                                onChange={(iso) => setData('effective_from', iso)}
                            />
                            <FieldError>{errors.effective_from}</FieldError>
                        </div>

                        {/* Effective To */}
                        <div>
                            <FormLabel>{t('organizationUnits.effectiveTo')}</FormLabel>
                            <LocalizedDatePicker
                                className="w-full"
                                value={data.effective_to}
                                onChange={(iso) => setData('effective_to', iso)}
                            />
                            <FieldError>{errors.effective_to}</FieldError>
                        </div>
                    </div>

                    <label className="flex min-h-12 items-center gap-3 rounded-control border border-(--app-border) px-3 text-sm text-(--app-foreground)">
                        <input type="checkbox" checked={data.is_public_feedback_selectable} onChange={(event) => setData('is_public_feedback_selectable', event.target.checked)} className="h-4 w-4" />
                        <span>
                            <span className="block font-medium">{t('organizationUnits.publicFeedbackSelectable')}</span>
                            <span className="block text-xs text-(--app-muted-foreground)">{t('organizationUnits.publicFeedbackSelectableHint')}</span>
                        </span>
                    </label>

                    {/* Descriptions */}
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <FormLabel>{t('organizationUnits.descriptionEn')}</FormLabel>
                            <textarea
                                className={inputCls}
                                rows={4}
                                value={data.description_en}
                                onChange={(e) => setData('description_en', e.target.value)}
                            />
                            <FieldError>{errors.description_en}</FieldError>
                        </div>

                        <div>
                            <FormLabel>{t('organizationUnits.descriptionAm')}</FormLabel>
                            <textarea
                                className={inputCls}
                                rows={4}
                                value={data.description_am}
                                onChange={(e) => setData('description_am', e.target.value)}
                            />
                            <FieldError>{errors.description_am}</FieldError>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center justify-end gap-3 border-t border-(--app-border) pt-5">
                        <Button type="submit" disabled={processing}>
                            {processing ? t('common.saving') : t('common.saveChanges')}
                        </Button>
                        <Link
                            href={route('organization-units.show', unit.id)}
                            className={actionLinkClassName({ tone: 'neutral', size: 'sm' })}
                        >
                            {t('common.cancel')}
                        </Link>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
