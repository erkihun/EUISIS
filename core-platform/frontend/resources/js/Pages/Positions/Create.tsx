import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import CodeRuleField from '@/Components/code-rules/CodeRuleField';
import { localizedName } from '@/utils/localizedName';

import { buttonClassName, cx, inputClassName, FormLabel } from '@/Components/ui';
type OccupationOption = { id: string; isco_code: string; name_en: string | null; name_am: string | null };
type OrgRef = { id: string; code: string; name_en: string; name_am: string | null };
type UnitOption = {
    id: string;
    name_en: string;
    name_am: string | null;
    code: string;
    organization_unit_type_id: string | null;
    /** Present only when the unit belongs functionally to another organization. */
    owner_organization?: OrgRef | null;
    /** Present only when the unit operates inside a host organization. */
    host_organization?: OrgRef | null;
};

interface Props {
    organizations: Array<{ id: string; name_en: string; name_am: string | null }>;
    organizationUnits: UnitOption[];
    occupations: OccupationOption[];
    gradeLevels: string[];
    selectedOrganizationId: string | null;
    selectedOrganizationUnitId: string | null;
}

export default function PositionsCreate({ organizations, organizationUnits, occupations, gradeLevels, selectedOrganizationId, selectedOrganizationUnitId }: Props) {
    const { t, locale } = useLocale();
    const form = useForm({
        job_position_code: '',
        old_code: '',
        title_en: '',
        title_am: '',
        bpr_name: '',
        organization_id: selectedOrganizationId ?? '',
        organization_unit_id: selectedOrganizationUnitId ?? '',
        occupation_id: '',
        grade_level: '',
        is_active: true,
    });

    const inputCls = cx(inputClassName, 'w-full');
    const errorCls = 'mt-1 text-xs text-red-600 dark:text-red-400';

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route('positions.store'));
    }

    const cancelHref = selectedOrganizationId
        ? route('positions.index') +
          '?organization_id=' + selectedOrganizationId +
          (selectedOrganizationUnitId ? '&organization_unit_id=' + selectedOrganizationUnitId : '')
        : route('positions.index');

    const selectedOrg = organizations.find((o) => o.id === form.data.organization_id);
    const selectedUnit = organizationUnits.find((unit) => unit.id === form.data.organization_unit_id);

    // If an organization is selected but has no units, block form submission
    if (selectedOrganizationId && organizationUnits.length === 0) {
        return (
            <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('positions.createPosition')} />}>
                <Head title={t('positions.createPosition')} />
                <div className="rounded-panel border border-amber-200 bg-amber-50 p-8 text-center dark:border-amber-800 dark:bg-amber-950/30">
                    <p className="text-base font-semibold text-amber-800 dark:text-amber-300">
                        {t('positions.noOrganizationUnits')}
                    </p>
                    <p className="mt-2 text-sm text-amber-700 dark:text-amber-400">
                        {t('positions.noOrganizationUnitsHint')}
                    </p>
                    <Link
                        href={cancelHref}
                        className="mt-4 inline-block rounded-lg border border-(--app-border-strong) px-4 py-2 text-sm font-medium text-(--app-foreground)"
                    >
                        {t('common.back')}
                    </Link>
                </div>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('positions.createPosition')} />}>
            <Head title={t('positions.createPosition')} />
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
                            error={form.errors.job_position_code}
                        />
                    </div>

                    <div className="space-y-1.5">
                        <FormLabel htmlFor="old_code">{t('positions.oldCode')}</FormLabel>
                        <input id="old_code" className={inputCls} value={form.data.old_code} onChange={(e) => form.setData('old_code', e.target.value)} />
                        {form.errors.old_code && <p className={errorCls}>{form.errors.old_code}</p>}
                    </div>

                    {/* Organization — read-only when pre-selected from tree */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="organization_id">{t('positions.organization')}</FormLabel>
                        {selectedOrganizationId ? (
                            <>
                                <input type="hidden" name="organization_id" value={form.data.organization_id} />
                                <div className={`${inputCls} cursor-not-allowed bg-(--app-surface-muted) text-(--app-muted-foreground)`}>
                                    {selectedOrg
                                        ? localizedName(selectedOrg.name_en, selectedOrg.name_am, locale)
                                        : '—'}
                                </div>
                            </>
                        ) : (
                            <select
                                id="organization_id"
                                className={inputCls}
                                value={form.data.organization_id}
                                onChange={(e) => form.setData('organization_id', e.target.value)}
                            >
                                <option value="">{t('positions.organization')}</option>
                                {organizations.map((org) => (
                                    <option key={org.id} value={org.id}>
                                        {localizedName(org.name_en, org.name_am, locale)}
                                    </option>
                                ))}
                            </select>
                        )}
                        {form.errors.organization_id && <p className={errorCls}>{form.errors.organization_id}</p>}
                    </div>

                    {/* Organization Unit (required) */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="organization_unit_id">{t('positions.organizationUnit')}</FormLabel>
                        {selectedOrganizationUnitId ? (
                            <>
                                <input type="hidden" name="organization_unit_id" value={form.data.organization_unit_id} />
                                <div className={`${inputCls} cursor-not-allowed bg-(--app-surface-muted) text-(--app-muted-foreground)`}>
                                    {(() => {
                                        const u = organizationUnits.find((u) => u.id === form.data.organization_unit_id);
                                        return u
                                            ? `${u.code} — ${localizedName(u.name_en, u.name_am, locale)}`
                                            : '—';
                                    })()}
                                </div>
                            </>
                        ) : (
                            <select
                                id="organization_unit_id"
                                className={inputCls}
                                value={form.data.organization_unit_id}
                                onChange={(e) => form.setData('organization_unit_id', e.target.value)}
                                required
                            >
                                <option value="">{t('positions.selectOrganizationUnit')}</option>
                                {organizationUnits.map((unit) => (
                                    <option key={unit.id} value={unit.id}>
                                        {unit.code} — {localizedName(unit.name_en, unit.name_am, locale)}
                                    </option>
                                ))}
                            </select>
                        )}
                        {form.errors.organization_unit_id && <p className={errorCls}>{form.errors.organization_unit_id}</p>}
                    </div>

                    {/* Owner / Host — read-only, shown only when the selected unit is hosted */}
                    {selectedUnit?.host_organization && (
                        <div className="md:col-span-2 space-y-3 rounded-card border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30">
                            <div className="grid gap-3 md:grid-cols-2">
                                <div className="space-y-1">
                                    <FormLabel>{t('positions.ownerOrganization')}</FormLabel>
                                    <div className={`${inputCls} cursor-not-allowed bg-[color-mix(in_srgb,var(--app-surface)_60%,transparent)]`}>
                                        <span className="font-mono text-xs text-(--app-muted-foreground)">{selectedUnit.owner_organization?.code}</span>
                                        {' — '}
                                        {selectedUnit.owner_organization
                                            ? localizedName(selectedUnit.owner_organization.name_en, selectedUnit.owner_organization.name_am, locale)
                                            : '—'}
                                    </div>
                                </div>
                                <div className="space-y-1">
                                    <FormLabel>{t('positions.hostOrganization')}</FormLabel>
                                    <div className={`${inputCls} cursor-not-allowed bg-[color-mix(in_srgb,var(--app-surface)_60%,transparent)]`}>
                                        <span className="font-mono text-xs text-(--app-muted-foreground)">{selectedUnit.host_organization.code}</span>
                                        {' — '}
                                        {localizedName(selectedUnit.host_organization.name_en, selectedUnit.host_organization.name_am, locale)}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Occupation (required) */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="occupation_id">{t('positions.occupation')}</FormLabel>
                        <select
                            id="occupation_id"
                            className={inputCls}
                            value={form.data.occupation_id}
                            onChange={(e) => form.setData('occupation_id', e.target.value)}
                            required
                        >
                            <option value="">{t('positions.selectOccupation')}</option>
                            {occupations.map((occ) => (
                                <option key={occ.id} value={occ.id}>
                                    {occ.isco_code}
                                    {localizedName(occ.name_en, occ.name_am, locale)
                                        ? ` — ${localizedName(occ.name_en, occ.name_am, locale)}`
                                        : ''}
                                </option>
                            ))}
                        </select>
                        {form.errors.occupation_id && <p className={errorCls}>{form.errors.occupation_id}</p>}
                    </div>

                    {/* English Title */}
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="title_en">{t('positions.englishTitle')}</FormLabel>
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
                        <FormLabel htmlFor="title_am">{t('positions.amharicTitle')}</FormLabel>
                        <input
                            id="title_am"
                            className={inputCls}
                            value={form.data.title_am}
                            placeholder={t('positions.amharicTitle')}
                            onChange={(e) => form.setData('title_am', e.target.value)}
                        />
                    </div>

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
                        {form.errors.grade_level && <p className={errorCls}>{form.errors.grade_level}</p>}
                    </div>
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

                <div className="flex gap-3">
                    <button
                        className={buttonClassName({ variant: 'primary' })}
                        type="submit"
                        disabled={form.processing}
                    >
                        {t('common.save')}
                    </button>
                    <Link
                        href={cancelHref}
                        className="rounded-lg border border-(--app-border-strong) px-4 py-2 text-sm font-medium text-(--app-foreground)"
                    >
                        {t('common.cancel')}
                    </Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
