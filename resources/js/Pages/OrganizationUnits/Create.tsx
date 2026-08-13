import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { Head, Link, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import CodeRuleField from '@/Components/code-rules/CodeRuleField';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

interface UnitTypeOption {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
}

interface ParentUnitOption {
    id: string;
    name_en: string;
    name_am: string | null;
    code: string;
    depth: number;
}

interface SelectedOrg {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
}

interface Props {
    organizations: Array<{ id: string; name_en: string; name_am: string | null; code: string }>;
    selectedOrg: SelectedOrg | null;
    parentUnits: ParentUnitOption[];
    unitTypes: UnitTypeOption[];
    statusOptions: Array<{ value: string; label: string }>;
}

export default function OrganizationUnitsCreate({
    organizations,
    selectedOrg,
    parentUnits,
    unitTypes,
    statusOptions,
}: Props) {
    const { t, locale } = useLocale();
    const { data, setData, post, processing, errors } = useForm({
        organization_id: selectedOrg?.id ?? '',
        parent_unit_id: null as string | null,
        organization_unit_type_id: '',
        code: '',
        name_en: '',
        name_am: '',
        description_en: '',
        description_am: '',
        status: 'active',
        effective_from: '',
        effective_to: '',
        sort_order: 0,
    });

    const inputCls =
        'w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100';

    const noUnitTypes = unitTypes.length === 0;
    const noParentUnits = parentUnits.length === 0 && !!data.organization_id;

    const backUrl = data.organization_id
        ? route('organization-units.index', { organization_id: data.organization_id })
        : route('organization-units.index');

    const localizedName = (item: { name_en?: string | null; name_am?: string | null; code?: string | null }): string => {
        const primary = locale === 'am' ? item.name_am : item.name_en;
        const fallback = locale === 'am' ? item.name_en : item.name_am;

        return primary || fallback || item.code || '';
    };

    const localizedStatus = (value: string, fallback: string): string => {
        const key = `common.${value}`;
        const label = t(key);

        return label === key ? fallback : label;
    };

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('organizationUnits.createOrganizationUnit')}
                />
            }
        >
            <Head title={t('organizationUnits.createOrganizationUnit')} />

            <div className="w-full">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(route('organization-units.store'));
                    }}
                    className="w-full space-y-6 rounded-2xl border border-gray-200 bg-white p-4 sm:p-6 lg:p-8 dark:border-slate-800 dark:bg-slate-900"
                >
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {/* Organization */}
                        <div>
                            <InputLabel value={`${t('organizationUnits.organization')} *`} />
                            {selectedOrg ? (
                                <>
                                    <div className={`${inputCls} bg-gray-50 text-gray-600 dark:bg-slate-800 dark:text-slate-400`}>
                                        {localizedName(selectedOrg)}{' '}
                                        <span className="font-mono text-xs text-gray-400">({selectedOrg.code})</span>
                                    </div>
                                    <input type="hidden" name="organization_id" value={selectedOrg.id} />
                                </>
                            ) : (
                                <select
                                    className={inputCls}
                                    value={data.organization_id}
                                    onChange={(e) => {
                                        setData('organization_id', e.target.value);
                                        setData('parent_unit_id', null);
                                    }}
                                >
                                    <option value="">{t('organizationUnits.selectOrganization')}</option>
                                    {organizations.map((o) => (
                                        <option key={o.id} value={o.id}>
                                            {localizedName(o)}
                                        </option>
                                    ))}
                                </select>
                            )}
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
                                            {localizedName(ut)}
                                        </option>
                                    ))}
                                </select>
                            )}
                            <InputError message={errors.organization_unit_type_id} />
                        </div>

                        {/* Parent Unit - optional */}
                        <div>
                            <InputLabel value={t('organizationUnits.parentUnitOptional')} />
                            {data.organization_id ? (
                                <>
                                    <select
                                        className={inputCls}
                                        value={data.parent_unit_id ?? ''}
                                        onChange={(e) =>
                                            setData('parent_unit_id', e.target.value || null)
                                        }
                                    >
                                        <option value="">{t('organizationUnits.noParentUnit')}</option>
                                        {parentUnits.map((pu) => (
                                            <option key={pu.id} value={pu.id}>
                                                {'-'.repeat(pu.depth)} {localizedName(pu)} ({pu.code})
                                            </option>
                                        ))}
                                    </select>
                                    {noParentUnits && (
                                        <p className="mt-1 text-xs text-blue-600 dark:text-blue-400">
                                            {t('organizationUnits.noParentUnitsYetHint')}
                                        </p>
                                    )}
                                </>
                            ) : (
                                <p className="mt-1 text-xs text-gray-400 dark:text-slate-500">
                                    {t('organizationUnits.selectOrganization')}
                                </p>
                            )}
                            <InputError message={errors.parent_unit_id} />
                        </div>

                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {/* Code */}
                        <div>
                            <CodeRuleField
                                entityType="organization_unit"
                                context={{
                                    organization_id: data.organization_id || undefined,
                                    organization_unit_type_id: data.organization_unit_type_id || undefined,
                                }}
                                value={data.code}
                                onChange={(v) => setData('code', v)}
                                fieldName="code"
                                label={`${t('organizationUnits.unitCode')} (${t('organizationUnits.codeAutoGenerated')})`}
                                canManualOverride={false}
                                error={errors.code}
                            />
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
                                onChange={(e) => setData('status', e.target.value)}
                            >
                                {statusOptions.map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {localizedStatus(opt.value, opt.label)}
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

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        {/* Description EN */}
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

                        {/* Description AM */}
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
                            {processing ? t('common.saving') : t('organizationUnits.createOrganizationUnit')}
                        </PrimaryButton>
                        <Link
                            href={backUrl}
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
