import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import JobMasterSelects, { type JobMasterGroup } from '@/Components/positions/JobMasterSelects';
import { toDateInput } from '@/lib/dateUtils';
import CodeRuleField from '@/Components/code-rules/CodeRuleField';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { localizedName } from '@/utils/localizedName';

import { buttonClassName, cx, inputClassName, FormLabel } from '@/Components/ui';
type OccupationOption = { id: string; isco_code: string; name_en: string | null; name_am: string | null };
type UnitOption = { id: string; name_en: string; name_am: string | null; code: string; organization_unit_type_id: string | null };

interface Props {
    position: any;
    organizations: Array<{ id: string; name_en: string; name_am: string | null }>;
    organizationUnits: UnitOption[];
    occupations: OccupationOption[];
    gradeLevels: string[];
    /** Core only (ADR 0010): the other job masters. */
    jobMasters?: JobMasterGroup[];
}

export default function PositionsEdit({ position, organizations, organizationUnits, occupations, gradeLevels, jobMasters }: Props) {
    const { t, locale } = useLocale();
    const form = useForm({
        job_position_code: position.job_position_code ?? '',
        old_code: position.old_code ?? '',
        title_en: position.title_en ?? '',
        title_am: position.title_am ?? '',
        bpr_name: position.bpr_name ?? '',
        organization_id: position.organization_id ?? '',
        organization_unit_id: position.organization_unit_id ?? '',
        occupation_id: position.occupation_id ?? '',
        description_en: position.description_en ?? '',
        description_am: position.description_am ?? '',
        grade_level: position.grade_level ?? '',
        job_level_id: position.job_level_id ?? '',
        profession_id: position.profession_id ?? '',
        employment_type_id: position.employment_type_id ?? '',
        position_category_id: position.position_category_id ?? '',
        job_family: position.job_family ?? '',
        is_active: position.is_active ?? true,
        is_unit_head: position.is_unit_head ?? false,
        effective_from: toDateInput(position.effective_from),
        effective_to: toDateInput(position.effective_to),
    });

    const inputCls = cx(inputClassName, 'w-full');
    const errorCls = 'mt-1 text-xs text-red-600 dark:text-red-400';
    const selectedUnit = organizationUnits.find((unit) => unit.id === form.data.organization_unit_id);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(route('positions.update', position.id));
    }

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('positions.editPosition')} />}>
            <Head title={t('positions.editPosition')} />
            <form
                className="space-y-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-6"
                onSubmit={submit}
            >
                <div className="grid gap-4 md:grid-cols-2">
                    {/* Code */}
                    <div className="space-y-1.5">
                        <CodeRuleField
                            entityType="position"
                            context={{
                                organization_id: form.data.organization_id || undefined,
                                organization_unit_id: form.data.organization_unit_id || undefined,
                                organization_unit_type_id: selectedUnit?.organization_unit_type_id || undefined,
                            }}
                            value={form.data.job_position_code}
                            onChange={(v) => form.setData('job_position_code', v)}
                            fieldName="job_position_code"
                            label={t('positions.jobPositionCode')}
                            canManualOverride={false}
                            existingCode={position.job_position_code ?? ''}
                            preserveExistingCodeOnEdit
                            error={form.errors.job_position_code}
                        />
                    </div>

                    <div className="space-y-1.5">
                        <FormLabel htmlFor="old_code">{t('positions.oldCode')}</FormLabel>
                        <input id="old_code" className={inputCls} value={form.data.old_code} onChange={(e) => form.setData('old_code', e.target.value)} />
                        {form.errors.old_code && <p className={errorCls}>{form.errors.old_code}</p>}
                    </div>

                    {/* Organization */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="organization_id">{t('positions.organization')}</FormLabel>
                        <select
                            id="organization_id"
                            className={inputCls}
                            value={form.data.organization_id}
                            onChange={(e) => form.setData('organization_id', e.target.value)}
                        >
                            <option value="">{t('positions.organization')}</option>
                            {organizations.map((org) => (
                                <option key={org.id} value={org.id}>{localizedName(org.name_en, org.name_am, locale)}</option>
                            ))}
                        </select>
                        {form.errors.organization_id && <p className={errorCls}>{form.errors.organization_id}</p>}
                    </div>

                    {/* Organization Unit */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="organization_unit_id" required>{t('positions.organizationUnit')}</FormLabel>
                        <select
                            id="organization_unit_id"
                            className={inputCls}
                            value={form.data.organization_unit_id}
                            onChange={(e) => form.setData('organization_unit_id', e.target.value)}
                        >
                            <option value="">{t('positions.selectOrganizationUnit')}</option>
                            {organizationUnits.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {unit.code} — {localizedName(unit.name_en, unit.name_am, locale)}
                                </option>
                            ))}
                        </select>
                        {form.errors.organization_unit_id && <p className={errorCls}>{form.errors.organization_unit_id}</p>}
                    </div>

                    {/* Occupation */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="occupation_id" required>{t('positions.occupation')}</FormLabel>
                        <select
                            id="occupation_id"
                            className={inputCls}
                            value={form.data.occupation_id}
                            onChange={(e) => form.setData('occupation_id', e.target.value)}
                        >
                            <option value="">{t('positions.selectOccupation')}</option>
                            {occupations.map((occ) => (
                                <option key={occ.id} value={occ.id}>
                                    {occ.isco_code}{localizedName(occ.name_en, occ.name_am, locale) ? ` — ${localizedName(occ.name_en, occ.name_am, locale)}` : ''}
                                </option>
                            ))}
                        </select>
                        {form.errors.occupation_id && <p className={errorCls}>{form.errors.occupation_id}</p>}
                    </div>

                    {/* English Title */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="title_en" required>{t('positions.englishTitle')}</FormLabel>
                        <input
                            id="title_en"
                            className={inputCls}
                            value={form.data.title_en}
                            placeholder={t('positions.englishTitle')}
                            onChange={(e) => form.setData('title_en', e.target.value)}
                        />
                        {form.errors.title_en && <p className={errorCls}>{form.errors.title_en}</p>}
                    </div>

                    {/* Amharic Title */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="title_am" required>{t('positions.amharicTitle')}</FormLabel>
                        <input
                            id="title_am"
                            className={inputCls}
                            value={form.data.title_am}
                            placeholder={t('positions.amharicTitle')}
                            onChange={(e) => form.setData('title_am', e.target.value)}
                        />
                    </div>

                    {/* Grade Level */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="bpr_name">{t('positions.bprName')}</FormLabel>
                        <input id="bpr_name" className={inputCls} value={form.data.bpr_name} onChange={(e) => form.setData('bpr_name', e.target.value)} />
                        {form.errors.bpr_name && <p className={errorCls}>{form.errors.bpr_name}</p>}
                    </div>

                    {/* Grade Level */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="grade_level">{t('positions.gradeLevel')}</FormLabel>
                        <select
                            id="grade_level"
                            className={inputCls}
                            value={form.data.grade_level}
                            onChange={(e) => form.setData('grade_level', e.target.value)}
                        >
                            <option value="">{t('positions.selectGradeLevel')}</option>
                            {gradeLevels.map((gl) => (
                                <option key={gl} value={gl}>{gl}</option>
                            ))}
                        </select>
                    </div>

                    {jobMasters && <JobMasterSelects groups={jobMasters} errors={form.errors}
                        values={{ job_level_id: form.data.job_level_id, profession_id: form.data.profession_id, employment_type_id: form.data.employment_type_id, position_category_id: form.data.position_category_id }}
                        onChange={(field, value) => form.setData(field, value)} />}

                    {/* Effective From */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="effective_from">{t('common.effectiveFrom')}</FormLabel>
                        <LocalizedDatePicker
                            id="effective_from"
                            className={inputCls}
                            value={form.data.effective_from}
                            onChange={(iso) => form.setData('effective_from', iso)}
                        />
                    </div>

                    {/* Effective To */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="effective_to">{t('common.effectiveTo')}</FormLabel>
                        <LocalizedDatePicker
                            id="effective_to"
                            className={inputCls}
                            value={form.data.effective_to}
                            onChange={(iso) => form.setData('effective_to', iso)}
                        />
                    </div>
                </div>

                {/* Description */}
                <div className="space-y-1.5">
                    <FormLabel htmlFor="description_en">{t('positions.positionDescription')}</FormLabel>
                    <textarea
                        id="description_en"
                        className={`${inputCls} min-h-28`}
                        value={form.data.description_en}
                        placeholder={t('positions.positionDescription')}
                        onChange={(e) => form.setData('description_en', e.target.value)}
                    />
                </div>

                {/* Active */}
                <label className="flex items-center gap-2 text-sm text-(--app-foreground)">
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(e) => form.setData('is_active', e.target.checked)}
                    />
                    {t('positions.activePosition')}
                </label>

                {/* Head of unit */}
                <div>
                    <label className="flex items-center gap-2 text-sm text-(--app-foreground)">
                        <input
                            type="checkbox"
                            checked={form.data.is_unit_head}
                            onChange={(e) => form.setData('is_unit_head', e.target.checked)}
                        />
                        {t('positions.unitHead')}
                    </label>
                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('positions.unitHeadHelp')}</p>
                    {form.errors.is_unit_head && <p className={errorCls}>{form.errors.is_unit_head}</p>}
                </div>

                <div className="flex gap-3">
                    <button
                        className={buttonClassName({ variant: 'primary' })}
                        type="submit"
                        disabled={form.processing}
                    >
                        {t('common.save')}
                    </button>
                    <Link
                        href={route('positions.show', position.id)}
                        className="rounded-lg border border-(--app-border-strong) px-4 py-2 text-sm font-medium text-(--app-foreground)"
                    >
                        {t('common.cancel')}
                    </Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
