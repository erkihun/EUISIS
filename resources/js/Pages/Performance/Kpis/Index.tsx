import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { AppFilterBar, Field, Pill, Section, Table, TablePanel, filterInputCls, inputCls, linkBtn, nameOf, pageCls, primaryBtn, secondaryBtn, tdCls, thCls, useEnumLabel, type Paginator } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Milestone = { key: string; label_en: string; label_am?: string | null; percent: number | string; requires_verification?: boolean };

type Kpi = {
    id: string; code: string; name_en: string; name_am: string | null; description_en: string | null; description_am: string | null;
    organization_id: string | null; unit_of_measure: string | null; system_source_key: string | null; calculation_formula: string | null;
    baseline: string | null; allow_overachievement: boolean; achievement_cap: string | null; target_tolerance: string | null; zero_score_deviation: string | null;
    milestones: Milestone[] | null; is_active: boolean;
    measurement_type: string; direction: string; aggregation_method: string; data_source_type: string; frequency: string;
};

type Props = {
    kpis: Paginator<Kpi>;
    filters: { search: string; active?: string };
    options: { measurement_types: string[]; directions: string[]; aggregations: string[]; sources: string[]; frequencies: string[]; system_sources: Record<string, { label_en: string; label_am: string | null; level: string }> };
    organizations: { id: string; name_en: string; name_am: string | null }[];
    can: { create: boolean; update: boolean; global: boolean };
};

const blank = (orgId: string) => ({
    code: '', name_en: '', name_am: '', description_en: '', description_am: '', organization_id: orgId,
    measurement_type: 'COUNT', unit_of_measure: '', direction: 'HIGHER_IS_BETTER', aggregation_method: 'SUM', data_source_type: 'MANUAL',
    system_source_key: '', calculation_formula: '', baseline: '', frequency: 'MONTHLY', allow_overachievement: false,
    achievement_cap: '', target_tolerance: '', zero_score_deviation: '', milestones_text: '', is_active: true,
});

function milestonesToText(list: Milestone[] | null): string {
    return (list ?? []).map((m) => [m.key, m.label_en, m.percent, m.requires_verification ? 'yes' : 'no'].join(' | ')).join('\n');
}

function textToMilestones(text: string): Milestone[] {
    return text.split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
        const [key = '', label = '', percent = '0', verify = 'no'] = line.split('|').map((p) => p.trim());
        return { key, label_en: label, percent: Number(percent), requires_verification: /^(y|yes|true|1)$/i.test(verify) };
    });
}

/** KPI library: definition, direction, aggregation and data source of every measure. */
export default function KpisIndex({ kpis, filters, options, organizations, can }: Props) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const [editing, setEditing] = useState<Kpi | 'new' | null>(null);
    const defaultOrg = can.global ? '' : (organizations[0]?.id ?? '');
    const form = useForm(blank(defaultOrg));
    const errors = form.errors as Record<string, string | undefined>;

    function startEdit(kpi: Kpi | 'new') {
        form.clearErrors();
        if (kpi === 'new') {
            form.setData(blank(defaultOrg));
        } else {
            form.setData({
                code: kpi.code, name_en: kpi.name_en, name_am: kpi.name_am ?? '', description_en: kpi.description_en ?? '', description_am: kpi.description_am ?? '',
                organization_id: kpi.organization_id ?? '', measurement_type: kpi.measurement_type, unit_of_measure: kpi.unit_of_measure ?? '', direction: kpi.direction,
                aggregation_method: kpi.aggregation_method, data_source_type: kpi.data_source_type, system_source_key: kpi.system_source_key ?? '',
                calculation_formula: kpi.calculation_formula ?? '', baseline: kpi.baseline ?? '', frequency: kpi.frequency, allow_overachievement: kpi.allow_overachievement,
                achievement_cap: kpi.achievement_cap ?? '', target_tolerance: kpi.target_tolerance ?? '', zero_score_deviation: kpi.zero_score_deviation ?? '',
                milestones_text: milestonesToText(kpi.milestones), is_active: kpi.is_active,
            });
        }
        setEditing(kpi);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform(({ milestones_text, ...data }) => ({
            ...Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])),
            milestones: data.direction === 'MILESTONE' ? textToMilestones(milestones_text) : null,
        }));
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing && editing !== 'new') form.put(route('performance.kpis.update', editing.id), done);
        else form.post(route('performance.kpis.store'), done);
    }

    const select = (key: 'measurement_type' | 'direction' | 'aggregation_method' | 'data_source_type' | 'frequency', group: string, values: string[], text: string) => (
        <Field label={text} htmlFor={`k-${key}`} error={errors[key]}>
            <select id={`k-${key}`} className={inputCls} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)}>
                {values.map((v) => <option key={v} value={v}>{label(group, v)}</option>)}
            </select>
        </Field>
    );
    const text = (key: 'code' | 'name_en' | 'name_am' | 'unit_of_measure' | 'baseline' | 'achievement_cap' | 'target_tolerance' | 'zero_score_deviation' | 'calculation_formula', caption: string, props: { required?: boolean; numeric?: boolean; disabled?: boolean } = {}) => (
        <Field label={caption} htmlFor={`k-${key}`} error={errors[key]}>
            <input id={`k-${key}`} className={inputCls} value={form.data[key]} required={props.required} disabled={props.disabled} inputMode={props.numeric ? 'decimal' : undefined} onChange={(e) => form.setData(key, e.target.value)} />
        </Field>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={t('performance.kpis.title')} description={t('performance.kpis.description')}
            actions={can.create && <button type="button" className={primaryBtn} onClick={() => startEdit('new')}>{t('performance.kpis.create')}</button>} />}>
            <Head title={t('performance.kpis.title')} />
            <div className={pageCls}>
                {editing && (
                    <Section title={editing === 'new' ? t('performance.kpis.create') : `${t('performance.actions.edit')}: ${editing.code}`}>
                        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {text('code', t('performance.fields.code'), { required: true })}
                            {text('name_en', t('performance.fields.nameEn'), { required: true })}
                            {text('name_am', t('performance.fields.nameAm'))}
                            <Field label={t('performance.fields.organization')} htmlFor="k-org" error={errors.organization_id}>
                                <select id="k-org" className={inputCls} value={form.data.organization_id} disabled={editing !== 'new'} onChange={(e) => form.setData('organization_id', e.target.value)}>
                                    {(can.global || editing !== 'new') && <option value="">{t('performance.kpis.globalOwner')}</option>}
                                    {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                                </select>
                            </Field>
                            {select('measurement_type', 'measurement', options.measurement_types, t('performance.fields.measurement'))}
                            {text('unit_of_measure', t('performance.fields.unitOfMeasure'))}
                            {select('direction', 'direction', options.directions, t('performance.fields.direction'))}
                            {select('aggregation_method', 'aggregation', options.aggregations, t('performance.fields.aggregation'))}
                            {select('data_source_type', 'source', options.sources, t('performance.fields.source'))}
                            {form.data.data_source_type === 'SYSTEM_TRANSACTION' && (
                                <Field label={t('performance.fields.systemSource')} htmlFor="k-sys" error={errors.system_source_key}>
                                    <select id="k-sys" className={inputCls} value={form.data.system_source_key} onChange={(e) => form.setData('system_source_key', e.target.value)} required>
                                        <option value="">—</option>
                                        {Object.entries(options.system_sources).map(([key, source]) => <option key={key} value={key}>{(locale === 'am' && source.label_am) || source.label_en}</option>)}
                                    </select>
                                </Field>
                            )}
                            {select('frequency', 'frequency', options.frequencies, t('performance.fields.frequency'))}
                            {text('baseline', t('performance.fields.baseline'), { numeric: true })}
                            {text('achievement_cap', t('performance.fields.cap'), { numeric: true })}
                            {form.data.direction === 'TARGET_IS_BEST' && text('target_tolerance', t('performance.fields.tolerance'), { numeric: true })}
                            {form.data.direction === 'TARGET_IS_BEST' && text('zero_score_deviation', t('performance.fields.zeroScore'), { numeric: true })}
                            <div className="sm:col-span-2">{text('calculation_formula', t('performance.fields.formula'))}</div>
                            <Field label={t('performance.fields.description')} htmlFor="k-desc" className="sm:col-span-2" error={errors.description_en}>
                                <textarea id="k-desc" rows={2} className={inputCls} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />
                            </Field>
                            {form.data.direction === 'MILESTONE' && (
                                <Field label={t('performance.fields.milestone')} htmlFor="k-ms" className="sm:col-span-2 lg:col-span-4" help={t('performance.kpis.milestonesHelp')} error={errors.milestones ?? Object.entries(errors).find(([k]) => k.startsWith('milestones.'))?.[1]}>
                                    <textarea id="k-ms" rows={4} className={`${inputCls} font-mono`} value={form.data.milestones_text} onChange={(e) => form.setData('milestones_text', e.target.value)} />
                                </Field>
                            )}
                            <div className="flex flex-wrap items-center gap-4 sm:col-span-2 lg:col-span-4">
                                <label className="flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" checked={form.data.allow_overachievement} onChange={(e) => form.setData('allow_overachievement', e.target.checked)} />{t('performance.fields.allowOver')}</label>
                                <label className="flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />{t('performance.fields.active')}</label>
                                <div className="ml-auto flex gap-2">
                                    <button type="button" className={secondaryBtn} onClick={() => setEditing(null)}>{t('performance.actions.cancel')}</button>
                                    <button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.actions.save')}</button>
                                </div>
                            </div>
                        </form>
                    </Section>
                )}

                <AppFilterBar routeName="performance.kpis.index" filters={{ search: filters.search, active: filters.active ?? '' }}>
                    <input name="search" defaultValue={filters.search} placeholder={t('performance.actions.search')} aria-label={t('performance.actions.search')} className={`${filterInputCls} min-w-[15rem] flex-1`} />
                    <select name="active" defaultValue={filters.active ?? ''} aria-label={t('performance.fields.status')} className={filterInputCls}>
                        <option value="">{t('performance.fields.status')}: —</option>
                        <option value="1">{t('performance.fields.active')}</option>
                        <option value="0">{t('performance.fields.inactive')}</option>
                    </select>
                </AppFilterBar>

                <TablePanel page={kpis} empty={t('performance.kpis.empty')}>
                        <Table head={<>
                            <th className={thCls}>{t('performance.fields.kpi')}</th>
                            <th className={thCls}>{t('performance.fields.direction')}</th>
                            <th className={thCls}>{t('performance.fields.aggregation')}</th>
                            <th className={thCls}>{t('performance.fields.source')}</th>
                            <th className={thCls}>{t('performance.fields.frequency')}</th>
                            <th className={thCls}>{t('performance.fields.status')}</th>
                            {can.update && <th className={thCls}><span className="sr-only">{t('performance.actions.edit')}</span></th>}
                        </>}>
                            {kpis.data.map((kpi) => (
                                <tr key={kpi.id}>
                                    <td className={tdCls}>
                                        <p className="font-medium text-gray-900 dark:text-slate-100"><span className="font-mono text-xs text-gray-500 dark:text-slate-400">{kpi.code}</span> {nameOf(kpi, locale)}</p>
                                        <p className="text-xs text-gray-500 dark:text-slate-400">{label('measurement', kpi.measurement_type)}{kpi.unit_of_measure ? ` · ${kpi.unit_of_measure}` : ''}{kpi.organization_id === null ? ` · ${t('performance.kpis.globalOwner')}` : ''}</p>
                                    </td>
                                    <td className={tdCls}>{label('direction', kpi.direction)}</td>
                                    <td className={tdCls}>{label('aggregation', kpi.aggregation_method)}</td>
                                    <td className={tdCls}>{label('source', kpi.data_source_type)}</td>
                                    <td className={tdCls}>{label('frequency', kpi.frequency)}</td>
                                    <td className={tdCls}><Pill group="kpiStatus" value={kpi.is_active ? 'ACTIVE' : 'INACTIVE'} tone={kpi.is_active ? 'success' : 'neutral'} /></td>
                                    {can.update && <td className={`${tdCls} text-right`}><button type="button" className={linkBtn} onClick={() => startEdit(kpi)}>{t('performance.actions.edit')}</button></td>}
                                </tr>
                            ))}
                        </Table>
                </TablePanel>
            </div>
        </AuthenticatedLayout>
    );
}
