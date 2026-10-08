import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Field, Problems, Section, compactInputCls, dangerLinkBtn, inputCls, linkBtn, nameOf, pageCls, primaryBtn, secondaryBtn, smallBtn, type Bilingual } from '@/Components/performance/ui';
import LookupPicker from '@/Components/assessmentForms/LookupPicker';
import SectionsEditor, { fmt, newUid, sectionMax, withoutUids, type SectionDraft } from '@/Components/assessmentForms/SectionsEditor';
import { useConfirm } from '@/hooks/useConfirm';
import { StatusBadge as UiStatusBadge } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';

/*
 * Assessment form builder (docs/assessment-form-builder.md).
 *
 * A draft version is edited as one document and saved whole; a published
 * version is shown read-only and changed only through a new version. The
 * totals shown here are a guide: the server recomputes and validates them
 * before a version can be published.
 */

type Rule = { target_type: string; target_id: string | null; target_value: string | null; target_label?: string | null; include_descendants: boolean; effect: string; priority: number; effective_from: string | null; effective_to: string | null };
type Evaluator = { evaluator_type: string; required_count: number; contribution_weight: string; selection_method: string; aggregation_method: string; is_anonymous: boolean; requires_review: boolean };

type Draft = {
    name_en: string; name_am: string; description_en: string; description_am: string; instructions_en: string; instructions_am: string;
    period_type: string; scoring_method: string; max_total_score: string; overall_contribution_weight: string; result_scale_id: string;
    acknowledgement_required: boolean; review_required: boolean; show_option_scores: boolean; effective_from: string; effective_to: string;
    sections: SectionDraft[]; target_rules: Rule[]; evaluators: Evaluator[];
};

type VersionView = Record<string, any> & { id: string; version_no: number; status: string; computed_max: string | null; sections: any[]; target_rules: any[]; evaluators: any[] };

type Props = {
    form: { id: string; code: string; name_en: string; name_am: string | null; status: string; type: (Bilingual & { id: string; code: string }) | null; organization: (Bilingual & { id: string }) | null; current_version_id: string | null };
    version: VersionView;
    versions: { id: string; version_no: number; status: string; published_at: string | null; effective_from: string | null; effective_to: string | null }[];
    problems: string[] | null;
    options: {
        scoring_methods: string[]; period_types: string[]; input_modes: string[]; target_types: string[]; evaluator_types: string[]; selection_methods: string[];
        result_scales: { id: string; code: string; name_en: string; name_am: string | null }[]; grade_levels: string[]; job_families: string[];
    };
    can: { edit: boolean; publish: boolean; newVersion: boolean; clone: boolean; archive: boolean; discard: boolean };
};

const text = (value: unknown): string => (value === null || value === undefined ? '' : String(value));
const num = (value: unknown): number => (value === null || value === undefined || value === '' ? NaN : Number(value));

function toDraft(v: VersionView): Draft {
    return {
        name_en: text(v.name_en), name_am: text(v.name_am), description_en: text(v.description_en), description_am: text(v.description_am),
        instructions_en: text(v.instructions_en), instructions_am: text(v.instructions_am), period_type: text(v.period_type), scoring_method: text(v.scoring_method) || 'percent_of_max',
        max_total_score: text(v.max_total_score), overall_contribution_weight: text(v.overall_contribution_weight), result_scale_id: text(v.result_scale_id),
        acknowledgement_required: Boolean(v.acknowledgement_required), review_required: Boolean(v.review_required), show_option_scores: v.show_option_scores === undefined || v.show_option_scores === null ? true : Boolean(v.show_option_scores), effective_from: text(v.effective_from), effective_to: text(v.effective_to),
        sections: v.sections.map((s) => ({
            uid: newUid(), code: text(s.code), title_en: text(s.title_en), title_am: text(s.title_am), description_en: text(s.description_en), description_am: text(s.description_am),
            weight: text(s.weight), max_score: text(s.max_score), is_required: Boolean(s.is_required),
            criteria: s.criteria.map((c: any) => ({
                uid: newUid(), competency_id: c.competency_id ?? null, competency_label: c.competency ? `${c.competency.code} ${c.competency.name_en}` : null,
                code: text(c.code), title_en: text(c.title_en), title_am: text(c.title_am), description_en: text(c.description_en), description_am: text(c.description_am),
                max_score: text(c.max_score), weight: text(c.weight), is_required: Boolean(c.is_required), comment_mode: c.comment_mode ?? 'optional', evidence_mode: c.evidence_mode ?? 'disabled',
                options: c.options.map((o: any) => ({ label_en: text(o.label_en), label_am: text(o.label_am), description_en: text(o.description_en), description_am: text(o.description_am), score: text(o.score) })),
            })),
        })),
        target_rules: v.target_rules.map((r) => ({ ...r, target_label: r.target_label ? r.target_label.label_en : null })),
        evaluators: v.evaluators.map((e) => ({ ...e, contribution_weight: text(e.contribution_weight) })),
    };
}

export default function AssessmentFormShow({ form: record, version, versions, problems, options, can }: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const initial = useMemo(() => toDraft(version), [version]);
    const form = useForm<Draft>(initial);
    const data = form.data;
    const editable = can.edit;
    const errors = form.errors as Record<string, string | undefined>;
    const [cloning, setCloning] = useState(false);
    const formTotal = data.sections.reduce((sum, s) => sum + sectionMax(s), 0);

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => form.setData(key, value as never);

    function save(e?: FormEvent) {
        e?.preventDefault();
        form.transform((d) => ({
            ...d,
            target_rules: d.target_rules.map(({ target_label: _label, ...rule }) => rule),
            sections: withoutUids(d.sections),
        }));
        form.put(route('assessment-forms.versions.save', version.id), { preserveScroll: true });
    }

    async function act(title: string, description: string, run: () => void) {
        const { confirmed } = await confirm({ title, description, confirmLabel: title, cancelLabel: t('assessments.actions.cancel') });
        if (confirmed) run();
    }

    // Client-only keys are regenerated on every load, so they never count as a change.
    const comparable = (d: Draft) => JSON.stringify({ ...d, sections: withoutUids(d.sections) });
    const dirty = comparable(data) !== comparable(initial);

    return (
        <AuthenticatedLayout header={<PageHeader
            title={`${record.code} · ${nameOf(record, locale)}`}
            description={[record.type ? nameOf(record.type, locale) : null, record.organization ? nameOf(record.organization, locale) : t('assessments.cityWide'), t(`assessments.formStatuses.${record.status}`)].filter(Boolean).join(' · ')}
            actions={<div className="flex flex-wrap gap-2">
                <Link href={route('assessment-forms.index')} className={secondaryBtn}>{t('assessments.actions.back')}</Link>
                {can.newVersion && <button type="button" className={secondaryBtn} onClick={() => act(t('assessments.actions.newVersion'), t('assessments.confirm.newVersion'), () => router.post(route('assessment-forms.versions.store', record.id)))}>{t('assessments.actions.newVersion')}</button>}
                {can.clone && <button type="button" className={secondaryBtn} onClick={() => setCloning(true)}>{t('assessments.actions.clone')}</button>}
                {can.archive && <button type="button" className={secondaryBtn} onClick={() => act(t('assessments.actions.archive'), t('assessments.confirm.archive'), () => router.post(route('assessment-forms.archive', record.id)))}>{t('assessments.actions.archive')}</button>}
            </div>}
        />}>
            <Head title={`${record.code} · ${t('assessments.title')}`} />
            <div className={pageCls}>
                {cloning && <CloneForm formId={record.id} onClose={() => setCloning(false)} />}

                <div className="flex flex-wrap items-center gap-3 rounded-panel border border-gray-200 bg-white px-4 py-3 text-sm dark:border-slate-800 dark:bg-slate-900">
                    <span className="font-semibold">{t('assessments.version')} {version.version_no}</span>
                    <StatusBadge status={version.status} />
                    {version.published_at && <span className="text-gray-500 dark:text-slate-400">{t('assessments.publishedOn')} <LocalizedDateDisplay value={version.published_at} /></span>}
                    {!editable && version.status === 'draft' && <span className="text-gray-500">{t('assessments.readOnly')}</span>}
                    {version.status !== 'draft' && <span className="text-gray-500 dark:text-slate-400">{t('assessments.immutableNote')}</span>}
                    <Link href={route('assessment-forms.versions.preview', version.id)} className={`${linkBtn} ms-auto`}>{t('assessments.actions.preview')}</Link>
                </div>

                {problems && problems.length > 0 && <Problems title={t('assessments.problemsTitle')} problems={problems} />}
                {problems && problems.length === 0 && <p role="status" className="rounded-panel border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300">{t('assessments.validNote')}</p>}
                {Object.keys(errors).length > 0 && <Problems title={t('assessments.problemsTitle')} problems={Object.values(errors).filter(Boolean) as string[]} />}

                <form onSubmit={save} className="space-y-4">
                    <fieldset disabled={!editable || form.processing} className="min-w-0 space-y-4">
                        <Section title={t('assessments.sections.general')}>
                            <div className="grid gap-3 md:grid-cols-2">
                                <Field label={t('assessments.fields.nameEn')} htmlFor="v-name-en" error={errors.name_en}><input id="v-name-en" className={inputCls} value={data.name_en} onChange={(e) => set('name_en', e.target.value)} /></Field>
                                <Field label={t('assessments.fields.nameAm')} htmlFor="v-name-am"><input id="v-name-am" className={inputCls} value={data.name_am} onChange={(e) => set('name_am', e.target.value)} /></Field>
                                <Field label={t('assessments.fields.descriptionEn')} htmlFor="v-desc-en"><textarea id="v-desc-en" rows={2} className={inputCls} value={data.description_en} onChange={(e) => set('description_en', e.target.value)} /></Field>
                                <Field label={t('assessments.fields.descriptionAm')} htmlFor="v-desc-am"><textarea id="v-desc-am" rows={2} className={inputCls} value={data.description_am} onChange={(e) => set('description_am', e.target.value)} /></Field>
                                <Field label={t('assessments.fields.instructionsEn')} htmlFor="v-ins-en"><textarea id="v-ins-en" rows={2} className={inputCls} value={data.instructions_en} onChange={(e) => set('instructions_en', e.target.value)} /></Field>
                                <Field label={t('assessments.fields.instructionsAm')} htmlFor="v-ins-am"><textarea id="v-ins-am" rows={2} className={inputCls} value={data.instructions_am} onChange={(e) => set('instructions_am', e.target.value)} /></Field>
                            </div>
                        </Section>

                        <Section title={t('assessments.sections.scoring')} description={t('assessments.scoringHelp')}>
                            <div className="grid gap-3 md:grid-cols-3">
                                <Field label={t('assessments.fields.scoringMethod')} htmlFor="v-method" error={errors.scoring_method}>
                                    <select id="v-method" className={inputCls} value={data.scoring_method} onChange={(e) => set('scoring_method', e.target.value)}>
                                        {options.scoring_methods.map((m) => <option key={m} value={m}>{t(`assessments.scoringMethods.${m}`)}</option>)}
                                    </select>
                                </Field>
                                <Field label={t('assessments.fields.maxTotal')} htmlFor="v-max" error={errors.max_total_score} help={`${t('assessments.computed')}: ${fmt(formTotal)}`}>
                                    <input id="v-max" type="number" min={0} step="any" className={inputCls} value={data.max_total_score} onChange={(e) => set('max_total_score', e.target.value)} />
                                </Field>
                                <Field label={t('assessments.fields.contribution')} htmlFor="v-contrib" error={errors.overall_contribution_weight} help={t('assessments.contributionHelp')}>
                                    <input id="v-contrib" type="number" min={0} max={100} step="any" className={inputCls} value={data.overall_contribution_weight} onChange={(e) => set('overall_contribution_weight', e.target.value)} />
                                </Field>
                                <Field label={t('assessments.fields.periodType')} htmlFor="v-period">
                                    <select id="v-period" className={inputCls} value={data.period_type} onChange={(e) => set('period_type', e.target.value)}>
                                        <option value="">—</option>
                                        {options.period_types.map((p) => <option key={p} value={p}>{t(`assessments.periodTypes.${p}`)}</option>)}
                                    </select>
                                </Field>
                                <Field label={t('assessments.fields.resultScale')} htmlFor="v-scale" help={t('assessments.resultScaleHelp')}>
                                    <select id="v-scale" className={inputCls} value={data.result_scale_id} onChange={(e) => set('result_scale_id', e.target.value)}>
                                        <option value="">—</option>
                                        {options.result_scales.map((s) => <option key={s.id} value={s.id}>{nameOf(s, locale)}</option>)}
                                    </select>
                                </Field>
                                <div className="space-y-2 pt-6 text-sm">
                                    <label className="flex items-center gap-2"><input type="checkbox" checked={data.acknowledgement_required} onChange={(e) => set('acknowledgement_required', e.target.checked)} /> {t('assessments.fields.acknowledgement')}</label>
                                    <label className="flex items-center gap-2"><input type="checkbox" checked={data.review_required} onChange={(e) => set('review_required', e.target.checked)} /> {t('assessments.fields.reviewRequired')}</label>
                                    <label className="flex items-center gap-2"><input type="checkbox" checked={data.show_option_scores} onChange={(e) => set('show_option_scores', e.target.checked)} /> {t('assessments.fields.showOptionScores')}</label>
                                </div>
                                <Field label={t('assessments.fields.effectiveFrom')} htmlFor="v-from"><LocalizedDatePicker id="v-from" value={data.effective_from} onChange={(value) => set('effective_from', value)} disabled={!editable} /></Field>
                                <Field label={t('assessments.fields.effectiveTo')} htmlFor="v-to" error={errors.effective_to}><LocalizedDatePicker id="v-to" value={data.effective_to} onChange={(value) => set('effective_to', value)} disabled={!editable} /></Field>
                            </div>
                        </Section>

                    </fieldset>

                    <Section title={t('assessments.sections.content')} description={t('assessments.builder.contentHelp')}>
                        <SectionsEditor sections={data.sections} editable={editable && !form.processing} scoringMethod={data.scoring_method} configuredTotal={data.max_total_score}
                            options={options} errors={errors} onChange={(sections) => set('sections', sections)} />
                    </Section>

                    <fieldset disabled={!editable || form.processing} className="min-w-0 space-y-4">
                        <Section title={t('assessments.sections.targets')} description={t('assessments.targetsHelp')}
                            actions={editable ? <button type="button" className={smallBtn} onClick={() => set('target_rules', [...data.target_rules, { target_type: 'position', target_id: null, target_value: null, include_descendants: true, effect: 'include', priority: 0, effective_from: null, effective_to: null }])}>{t('assessments.actions.addRule')}</button> : undefined}>
                            <TargetRules rules={data.target_rules} editable={editable} options={options} organizationId={record.organization?.id ?? null} errors={errors} onChange={(rules) => set('target_rules', rules)} />
                            <AssignmentPreview formId={record.id} />
                        </Section>

                        <Section title={t('assessments.sections.evaluators')} description={t('assessments.evaluatorsHelp')}
                            actions={editable && data.evaluators.length < options.evaluator_types.length ? <button type="button" className={smallBtn} onClick={() => set('evaluators', [...data.evaluators, { evaluator_type: options.evaluator_types.find((type) => !data.evaluators.some((e) => e.evaluator_type === type)) ?? 'self', required_count: 1, contribution_weight: '', selection_method: 'system', aggregation_method: 'average', is_anonymous: false, requires_review: false }])}>{t('assessments.actions.addEvaluator')}</button> : undefined}>
                            <Evaluators evaluators={data.evaluators} editable={editable} options={options} onChange={(list) => set('evaluators', list)} />
                        </Section>
                    </fieldset>

                    {editable && (
                        <div className="sticky bottom-0 z-10 flex flex-wrap items-center gap-2 rounded-panel border border-gray-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur dark:border-slate-700 dark:bg-slate-950/95">
                            <p className="me-auto text-sm text-gray-500 dark:text-slate-400">{dirty ? t('assessments.unsaved') : t('assessments.saved')}</p>
                            {can.discard && <button type="button" className={secondaryBtn} onClick={() => act(t('assessments.actions.discard'), t('assessments.confirm.discard'), () => router.delete(route('assessment-forms.versions.discard', version.id)))}>{t('assessments.actions.discard')}</button>}
                            <button type="button" className={secondaryBtn} disabled={dirty || form.processing} title={dirty ? t('assessments.saveFirst') : undefined} onClick={() => router.post(route('assessment-forms.versions.validate', version.id), {}, { preserveScroll: true })}>{t('assessments.actions.validate')}</button>
                            <button type="submit" className={secondaryBtn} disabled={form.processing || !dirty}>{t('assessments.actions.saveDraft')}</button>
                            {can.publish && <button type="button" className={primaryBtn} disabled={dirty || form.processing} title={dirty ? t('assessments.saveFirst') : undefined} onClick={() => act(t('assessments.actions.publish'), t('assessments.confirm.publish'), () => router.post(route('assessment-forms.versions.publish', version.id), {}, { preserveScroll: true }))}>{t('assessments.actions.publish')}</button>}
                        </div>
                    )}
                </form>

                <Section title={t('assessments.sections.versions')}>
                    <ul className="divide-y divide-gray-100 text-sm dark:divide-slate-800">
                        {versions.map((v) => (
                            <li key={v.id} className="flex flex-wrap items-center gap-3 py-2">
                                <span className="font-medium">v{v.version_no}</span>
                                <StatusBadge status={v.status} />
                                {v.published_at && <span className="text-xs text-gray-500"><LocalizedDateDisplay value={v.published_at} /></span>}
                                {v.id === record.current_version_id && <span className="text-xs font-medium text-emerald-700 dark:text-emerald-400">{t('assessments.current')}</span>}
                                <span className="ms-auto flex gap-3">
                                    {v.id !== version.id && <Link href={route('assessment-forms.show', { form: record.id, version: v.id })} className={linkBtn}>{t('assessments.actions.view')}</Link>}
                                    <Link href={route('assessment-forms.versions.preview', v.id)} className={linkBtn}>{t('assessments.actions.preview')}</Link>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Section>
            </div>
        </AuthenticatedLayout>
    );
}

function TargetRules({ rules, editable, options, organizationId, errors, onChange }: { rules: Rule[]; editable: boolean; options: Props['options']; organizationId: string | null; errors: Record<string, string | undefined>; onChange: (rules: Rule[]) => void }) {
    const { t } = useLocale();
    const update = (i: number, patch: Partial<Rule>) => onChange(rules.map((r, n) => (n === i ? { ...r, ...patch } : r)));
    const idTypes = ['position', 'occupation', 'organization', 'organization_unit'];

    if (rules.length === 0) return <p className="text-sm text-gray-500 dark:text-slate-400">{t('assessments.noRules')}</p>;

    return (
        <div className="space-y-2">
            {rules.map((rule, i) => (
                <div key={i} className="grid items-end gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 lg:grid-cols-[9rem_11rem_minmax(0,1fr)_6rem_auto] dark:bg-slate-800/50">
                    <Field label={t('assessments.fields.effect')} htmlFor={`r${i}-effect`}>
                        <select id={`r${i}-effect`} className={compactInputCls} value={rule.effect} onChange={(e) => update(i, { effect: e.target.value })}>
                            <option value="include">{t('assessments.effects.include')}</option>
                            <option value="exclude">{t('assessments.effects.exclude')}</option>
                        </select>
                    </Field>
                    <Field label={t('assessments.fields.targetType')} htmlFor={`r${i}-type`}>
                        <select id={`r${i}-type`} className={compactInputCls} value={rule.target_type} onChange={(e) => update(i, { target_type: e.target.value, target_id: null, target_value: null, target_label: null })}>
                            {options.target_types.map((type) => <option key={type} value={type}>{t(`assessments.targetTypes.${type}`)}</option>)}
                        </select>
                    </Field>
                    <div className="min-w-0">
                        {idTypes.includes(rule.target_type) && (
                            <LookupPicker type={rule.target_type} label={t('assessments.fields.target')} value={rule.target_id} valueLabel={rule.target_label ?? null} editable={editable} organizationId={organizationId}
                                onPick={(picked) => update(i, { target_id: picked?.id ?? null, target_label: picked?.label ?? null })} />
                        )}
                        {rule.target_type === 'grade_level' && (
                            <Field label={t('assessments.fields.target')} htmlFor={`r${i}-grade`}>
                                <select id={`r${i}-grade`} className={compactInputCls} value={rule.target_value ?? ''} onChange={(e) => update(i, { target_value: e.target.value || null })}>
                                    <option value="">—</option>
                                    {options.grade_levels.map((g) => <option key={g} value={g}>{g}</option>)}
                                </select>
                            </Field>
                        )}
                        {rule.target_type === 'job_family' && (
                            <Field label={t('assessments.fields.target')} htmlFor={`r${i}-family`}>
                                <input id={`r${i}-family`} list={`r${i}-families`} className={compactInputCls} value={rule.target_value ?? ''} onChange={(e) => update(i, { target_value: e.target.value || null })} />
                                <datalist id={`r${i}-families`}>{options.job_families.map((f) => <option key={f} value={f} />)}</datalist>
                            </Field>
                        )}
                        {rule.target_type === 'everyone' && <p className="pb-1.5 text-sm text-gray-600 dark:text-slate-300">{t('assessments.everyoneNote')}</p>}
                        {rule.target_type === 'organization_unit' && (
                            <label className="mt-1 flex items-center gap-2 text-xs"><input type="checkbox" checked={rule.include_descendants} onChange={(e) => update(i, { include_descendants: e.target.checked })} /> {t('assessments.fields.includeSubUnits')}</label>
                        )}
                        {errors[`target_rules.${i}.target_id`] && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{errors[`target_rules.${i}.target_id`]}</p>}
                    </div>
                    <Field label={t('assessments.fields.priority')} htmlFor={`r${i}-priority`}>
                        <input id={`r${i}-priority`} type="number" className={compactInputCls} value={rule.priority} onChange={(e) => update(i, { priority: Number(e.target.value) })} />
                    </Field>
                    {editable && <button type="button" className={`${dangerLinkBtn} pb-2`} onClick={() => onChange(rules.filter((_, n) => n !== i))}>{t('assessments.actions.remove')}</button>}
                </div>
            ))}
        </div>
    );
}

function Evaluators({ evaluators, editable, options, onChange }: { evaluators: Evaluator[]; editable: boolean; options: Props['options']; onChange: (list: Evaluator[]) => void }) {
    const { t } = useLocale();
    const update = (i: number, patch: Partial<Evaluator>) => onChange(evaluators.map((e, n) => (n === i ? { ...e, ...patch } : e)));
    const total = evaluators.reduce((sum, e) => sum + (Number.isNaN(num(e.contribution_weight)) ? 0 : num(e.contribution_weight)), 0);

    if (evaluators.length === 0) return <p className="text-sm text-gray-500 dark:text-slate-400">{t('assessments.noEvaluators')}</p>;

    return (
        <div className="space-y-2">
            {evaluators.map((evaluator, i) => (
                <div key={i} className="grid items-end gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-3 lg:grid-cols-[minmax(0,1fr)_6rem_7rem_minmax(0,1fr)_auto_auto] dark:bg-slate-800/50">
                    <Field label={t('assessments.fields.evaluatorType')} htmlFor={`e${i}-type`}>
                        <select id={`e${i}-type`} className={compactInputCls} value={evaluator.evaluator_type} onChange={(e) => update(i, { evaluator_type: e.target.value })}>
                            {options.evaluator_types.map((type) => <option key={type} value={type} disabled={type !== evaluator.evaluator_type && evaluators.some((x) => x.evaluator_type === type)}>{t(`assessments.evaluatorTypes.${type}`)}</option>)}
                        </select>
                    </Field>
                    <Field label={t('assessments.fields.count')} htmlFor={`e${i}-count`}><input id={`e${i}-count`} type="number" min={1} max={50} className={compactInputCls} value={evaluator.required_count} onChange={(e) => update(i, { required_count: Number(e.target.value) })} /></Field>
                    <Field label={t('assessments.fields.weightPercent')} htmlFor={`e${i}-weight`}><input id={`e${i}-weight`} type="number" min={0} max={100} step="any" className={compactInputCls} value={evaluator.contribution_weight} onChange={(e) => update(i, { contribution_weight: e.target.value })} /></Field>
                    <Field label={t('assessments.fields.selection')} htmlFor={`e${i}-selection`}>
                        <select id={`e${i}-selection`} className={compactInputCls} value={evaluator.selection_method} onChange={(e) => update(i, { selection_method: e.target.value })}>
                            {options.selection_methods.map((m) => <option key={m} value={m}>{t(`assessments.selectionMethods.${m}`)}</option>)}
                        </select>
                    </Field>
                    <span className="flex flex-col gap-1 text-xs">
                        <label className="flex items-center gap-2"><input type="checkbox" checked={evaluator.is_anonymous} onChange={(e) => update(i, { is_anonymous: e.target.checked })} /> {t('assessments.fields.anonymous')}</label>
                        <label className="flex items-center gap-2"><input type="checkbox" checked={evaluator.requires_review} onChange={(e) => update(i, { requires_review: e.target.checked })} /> {t('assessments.fields.requiresReview')}</label>
                    </span>
                    {editable && <button type="button" className={`${dangerLinkBtn} pb-2`} onClick={() => onChange(evaluators.filter((_, n) => n !== i))}>{t('assessments.actions.remove')}</button>}
                </div>
            ))}
            {evaluators.length > 1 && <p className={`text-xs ${total === 100 ? 'text-gray-500 dark:text-slate-400' : 'font-medium text-red-700 dark:text-red-400'}`}>{t('assessments.weightTotal')}: {fmt(total)}%</p>}
        </div>
    );
}

/** Who each published form of this type would reach, within the viewer's scope. Read-only. */
function AssignmentPreview({ formId }: { formId: string }) {
    const { t, locale } = useLocale();
    const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<{ total: number; unmatched: number; conflicts: number; forms: { code: string; name_en: string; name_am: string | null; version_no: number; employees: number }[] } | null>(null);

    async function run() {
        setLoading(true);
        const response = await fetch(route('assessment-forms.assignment-preview', { form: formId, date }), { headers: { Accept: 'application/json' } });
        setLoading(false);
        if (response.ok) setResult(await response.json());
    }

    return (
        <div className="mt-4 rounded-lg border border-dashed border-gray-300 p-3 dark:border-slate-700">
            <p className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('assessments.preview.title')}</p>
            <p className="mb-2 text-xs text-gray-500 dark:text-slate-400">{t('assessments.preview.help')}</p>
            <div className="flex flex-wrap items-end gap-2">
                <div className="w-48"><LocalizedDatePicker value={date} onChange={setDate} /></div>
                <button type="button" className={smallBtn} disabled={loading} onClick={() => void run()}>{loading ? t('assessments.preview.running') : t('assessments.preview.run')}</button>
            </div>
            {result && (
                <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    {result.forms.map((f) => <Stat key={`${f.code}-${f.version_no}`} label={`${f.code} v${f.version_no}`} value={f.employees} hint={(locale === 'am' && f.name_am) || f.name_en} />)}
                    <Stat label={t('assessments.preview.unmatched')} value={result.unmatched} tone={result.unmatched > 0 ? 'warn' : undefined} />
                    <Stat label={t('assessments.preview.conflicts')} value={result.conflicts} tone={result.conflicts > 0 ? 'bad' : undefined} />
                    <Stat label={t('assessments.preview.total')} value={result.total} />
                </dl>
            )}
        </div>
    );
}

function Stat({ label, value, hint, tone }: { label: string; value: number; hint?: ReactNode; tone?: 'warn' | 'bad' }) {
    const color = tone === 'bad' ? 'text-red-700 dark:text-red-400' : tone === 'warn' ? 'text-amber-700 dark:text-amber-400' : 'text-gray-900 dark:text-slate-100';
    return (
        <div className="rounded-lg bg-gray-50 px-3 py-2 dark:bg-slate-800/60">
            <dt className="text-xs text-gray-500 dark:text-slate-400">{label}</dt>
            <dd className={`text-lg font-semibold tabular-nums ${color}`}>{value.toLocaleString()}</dd>
            {hint && <dd className="truncate text-xs text-gray-500 dark:text-slate-400">{hint}</dd>}
        </div>
    );
}

function CloneForm({ formId, onClose }: { formId: string; onClose: () => void }) {
    const { t } = useLocale();
    const form = useForm({ code: '', name_en: '', name_am: '' });
    return (
        <Section title={t('assessments.actions.clone')} description={t('assessments.cloneHelp')}>
            <form onSubmit={(e) => { e.preventDefault(); form.post(route('assessment-forms.clone', formId)); }} className="grid gap-3 md:grid-cols-3">
                <Field label={t('assessments.fields.code')} htmlFor="clone-code" error={form.errors.code}><input id="clone-code" className={inputCls} value={form.data.code} required onChange={(e) => form.setData('code', e.target.value.toUpperCase())} /></Field>
                <Field label={t('assessments.fields.nameEn')} htmlFor="clone-en" error={form.errors.name_en}><input id="clone-en" className={inputCls} value={form.data.name_en} required onChange={(e) => form.setData('name_en', e.target.value)} /></Field>
                <Field label={t('assessments.fields.nameAm')} htmlFor="clone-am"><input id="clone-am" className={inputCls} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} /></Field>
                <div className="flex gap-2 md:col-span-3">
                    <button type="submit" className={primaryBtn} disabled={form.processing}>{t('assessments.actions.clone')}</button>
                    <button type="button" className={secondaryBtn} onClick={onClose}>{t('assessments.actions.cancel')}</button>
                </div>
            </form>
        </Section>
    );
}

const VERSION_TONES: Record<string, 'success' | 'warning' | 'neutral'> = { draft: 'warning', published: 'success', superseded: 'neutral', archived: 'neutral' };

function StatusBadge({ status }: { status: string }) {
    const { t } = useLocale();
    return <UiStatusBadge tone={VERSION_TONES[status] ?? 'neutral'}>{t(`assessments.versionStatuses.${status}`)}</UiStatusBadge>;
}
