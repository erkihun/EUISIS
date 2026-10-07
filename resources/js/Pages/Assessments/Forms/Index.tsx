import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AppFilterBar, Field, Section, Table, TablePanel, filterInputCls, inputCls, linkBtn, nameOf, pageCls, primaryBtn, secondaryBtn, tdCls, thCls, type Bilingual, type Paginator } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Row = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    type: (Bilingual & { code: string }) | null;
    organization: Bilingual;
    status: string;
    current_version: number | null;
    overall_contribution_weight: string | null;
    effective_from: string | null;
    has_draft: boolean;
    versions_count: number;
    target_rules_count: number;
};

type Option = { id: string; code?: string; name_en: string; name_am: string | null };

type Props = {
    forms: Paginator<Row>;
    filters: { search?: string; type?: string; status?: string };
    types: Option[];
    organizations: Option[];
    can: { create: boolean; createCityWide: boolean };
};

/**
 * Performance › Assessment Forms. Forms are built here and versioned; the
 * content of any paper form is entered as configuration, never code.
 */
export default function AssessmentFormsIndex({ forms, filters, types, organizations, can }: Props) {
    const { t, locale } = useLocale();
    const [creating, setCreating] = useState(false);
    const form = useForm({
        code: '', name_en: '', name_am: '', description_en: '', assessment_type_id: types[0]?.id ?? '',
        organization_id: can.createCityWide ? '' : (organizations[0]?.id ?? ''),
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => ({ ...data, organization_id: data.organization_id || null }));
        form.post(route('assessment-forms.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('assessments.title')} description={t('assessments.description')}
            actions={can.create && !creating ? <button type="button" className={primaryBtn} onClick={() => setCreating(true)}>{t('assessments.newForm')}</button> : undefined} />}>
            <Head title={t('assessments.title')} />
            <div className={pageCls}>
                {creating && (
                    <Section title={t('assessments.newForm')} description={t('assessments.newFormHelp')}>
                        <form onSubmit={submit} className="grid gap-3 md:grid-cols-2">
                            <Field label={t('assessments.fields.code')} htmlFor="af-code" error={form.errors.code}>
                                <input id="af-code" className={inputCls} value={form.data.code} maxLength={50} required onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />
                            </Field>
                            <Field label={t('assessments.fields.type')} htmlFor="af-type" error={form.errors.assessment_type_id}>
                                <select id="af-type" className={inputCls} value={form.data.assessment_type_id} onChange={(e) => form.setData('assessment_type_id', e.target.value)}>
                                    {types.map((type) => <option key={type.id} value={type.id}>{nameOf(type, locale)}</option>)}
                                </select>
                            </Field>
                            <Field label={t('assessments.fields.nameEn')} htmlFor="af-name-en" error={form.errors.name_en}>
                                <input id="af-name-en" className={inputCls} value={form.data.name_en} required onChange={(e) => form.setData('name_en', e.target.value)} />
                            </Field>
                            <Field label={t('assessments.fields.nameAm')} htmlFor="af-name-am" error={form.errors.name_am}>
                                <input id="af-name-am" className={inputCls} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />
                            </Field>
                            <Field label={t('assessments.fields.owner')} htmlFor="af-org" error={form.errors.organization_id} help={t('assessments.fields.ownerHelp')}>
                                <select id="af-org" className={inputCls} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}>
                                    {can.createCityWide && <option value="">{t('assessments.cityWide')}</option>}
                                    {organizations.map((org) => <option key={org.id} value={org.id}>{nameOf(org, locale)}</option>)}
                                </select>
                            </Field>
                            <Field label={t('assessments.fields.descriptionEn')} htmlFor="af-desc" error={form.errors.description_en}>
                                <input id="af-desc" className={inputCls} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />
                            </Field>
                            <div className="flex gap-2 md:col-span-2">
                                <button type="submit" className={primaryBtn} disabled={form.processing}>{t('assessments.actions.create')}</button>
                                <button type="button" className={secondaryBtn} onClick={() => setCreating(false)}>{t('assessments.actions.cancel')}</button>
                            </div>
                        </form>
                    </Section>
                )}

                <AppFilterBar routeName="assessment-forms.index" filters={filters as Record<string, string>}>
                    <input name="search" aria-label={t('assessments.search')} placeholder={t('assessments.search')} className={filterInputCls} defaultValue={filters.search ?? ''} />
                    <select name="type" aria-label={t('assessments.fields.type')} className={filterInputCls} defaultValue={filters.type ?? ''}>
                        <option value="">{t('assessments.allTypes')}</option>
                        {types.map((type) => <option key={type.id} value={type.id}>{nameOf(type, locale)}</option>)}
                    </select>
                    <select name="status" aria-label={t('assessments.fields.status')} className={filterInputCls} defaultValue={filters.status ?? ''}>
                        <option value="">{t('assessments.allStatuses')}</option>
                        <option value="active">{t('assessments.formStatuses.active')}</option>
                        <option value="archived">{t('assessments.formStatuses.archived')}</option>
                    </select>
                </AppFilterBar>

                <TablePanel page={forms} empty={t('assessments.empty')}>
                    <Table head={<>
                        <th className={thCls}>{t('assessments.fields.code')}</th>
                        <th className={thCls}>{t('assessments.fields.name')}</th>
                        <th className={thCls}>{t('assessments.fields.type')}</th>
                        <th className={thCls}>{t('assessments.fields.currentVersion')}</th>
                        <th className={thCls}>{t('assessments.fields.targets')}</th>
                        <th className={thCls}>{t('assessments.fields.contribution')}</th>
                        <th className={thCls}>{t('assessments.fields.status')}</th>
                        <th className={thCls}>{t('assessments.fields.effectiveFrom')}</th>
                        <th className={thCls}><span className="sr-only">{t('assessments.actions.open')}</span></th>
                    </>}>
                        {forms.data.map((row) => (
                            <tr key={row.id} className="border-t border-gray-100 dark:border-slate-800">
                                <td className={`${tdCls} font-mono text-xs`}>{row.code}</td>
                                <td className={tdCls}>
                                    <p className="font-medium text-gray-900 dark:text-slate-100">{nameOf(row, locale)}</p>
                                    <p className="text-xs text-gray-500 dark:text-slate-400">{row.organization ? nameOf(row.organization, locale) : t('assessments.cityWide')}</p>
                                </td>
                                <td className={tdCls}>{row.type ? nameOf(row.type, locale) : '—'}</td>
                                <td className={tdCls}>
                                    {row.current_version ? `v${row.current_version}` : <span className="text-gray-500">{t('assessments.notPublished')}</span>}
                                    {row.has_draft && <span className="ms-2 rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">{t('assessments.versionStatuses.draft')}</span>}
                                </td>
                                <td className={tdCls}>{row.target_rules_count}</td>
                                <td className={tdCls}>{row.overall_contribution_weight !== null ? `${Number(row.overall_contribution_weight)}%` : '—'}</td>
                                <td className={tdCls}>{t(`assessments.formStatuses.${row.status}`)}</td>
                                <td className={tdCls}><LocalizedDateDisplay value={row.effective_from} /></td>
                                <td className={`${tdCls} text-right`}><Link href={route('assessment-forms.show', row.id)} className={linkBtn}>{t('assessments.actions.open')}</Link></td>
                            </tr>
                        ))}
                    </Table>
                </TablePanel>
            </div>
        </AuthenticatedLayout>
    );
}
