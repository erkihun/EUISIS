import LookupPicker from '@/Components/assessmentForms/LookupPicker';
import { ChevronDown, ChevronUp, ComponentIcon, Plus, TrashIcon } from '@/Components/Icons';
import { Field, compactInputCls, inputCls, smallBtn } from '@/Components/performance/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { StatusBadge } from '@euisis/ui';
import { useState, type ReactNode } from 'react';

/*
 * "Sections and criteria" of a draft form version (docs/assessment-form-builder.md).
 * Sections and criteria collapse to a one-line summary (title, counts, maximum,
 * problems) so long forms stay readable; totals are a guide, the server
 * recomputes and validates them before publishing.
 */

export type Option = { label_en: string; label_am: string; description_en: string; description_am: string; score: string };
export type Criterion = {
    uid?: string; competency_id: string | null; competency_label?: string | null; code: string; title_en: string; title_am: string; description_en: string; description_am: string;
    max_score: string; weight: string; is_required: boolean; comment_mode: string; evidence_mode: string; options: Option[];
};
export type SectionDraft = { uid?: string; code: string; title_en: string; title_am: string; description_en: string; description_am: string; weight: string; max_score: string; is_required: boolean; criteria: Criterion[] };

/** Client-only key so collapse state follows an item when it moves. Stripped before saving. */
export const newUid = (): string => (globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`);

export const blankOption = (score: string): Option => ({ label_en: '', label_am: '', description_en: '', description_am: '', score });
export const blankCriterion = (): Criterion => ({ uid: newUid(), competency_id: null, code: '', title_en: '', title_am: '', description_en: '', description_am: '', max_score: '', weight: '', is_required: true, comment_mode: 'optional', evidence_mode: 'disabled', options: [blankOption('2'), blankOption('1')] });
export const blankSection = (): SectionDraft => ({ uid: newUid(), code: '', title_en: '', title_am: '', description_en: '', description_am: '', weight: '', max_score: '', is_required: true, criteria: [blankCriterion()] });

/** Compact control that fills its grid cell. */
const fieldCls = `${compactInputCls} w-full`;

const num = (value: unknown): number => (value === null || value === undefined || value === '' ? NaN : Number(value));
export const fmt = (value: number) => (Math.round(value * 10000) / 10000).toString();

/** The highest score a criterion permits: its configured maximum, else its best option. */
export function criterionMax(c: Criterion): number {
    const configured = num(c.max_score);
    if (!Number.isNaN(configured)) return configured;
    return bestOption(c);
}
const bestOption = (c: Criterion) => c.options.reduce((best, o) => Math.max(best, Number.isNaN(num(o.score)) ? 0 : num(o.score)), 0);
export const sectionMax = (s: SectionDraft) => s.criteria.reduce((sum, c) => sum + criterionMax(c), 0);

export function move<T>(list: T[], index: number, delta: number): T[] {
    const target = index + delta;
    if (target < 0 || target >= list.length) return list;
    const next = [...list];
    [next[index], next[target]] = [next[target], next[index]];
    return next;
}

/** Remove the client-only keys before the draft is sent. */
export const withoutUids = (sections: SectionDraft[]) => sections.map(({ uid: _s, ...s }) => ({ ...s, criteria: s.criteria.map(({ uid: _c, competency_label: _l, ...c }) => c) }));

const cloneCriterion = (c: Criterion): Criterion => ({ ...c, uid: newUid(), code: c.code ? `${c.code}-copy` : '', options: c.options.map((o) => ({ ...o })) });

type Options = { input_modes: string[] };
type Errors = Record<string, string | undefined>;

export default function SectionsEditor({ sections, editable, scoringMethod, configuredTotal, options, errors, onChange }: {
    sections: SectionDraft[]; editable: boolean; scoringMethod: string; configuredTotal: string; options: Options; errors: Errors;
    onChange: (sections: SectionDraft[]) => void;
}) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const criteriaTotal = sections.reduce((n, s) => n + s.criteria.length, 0);
    // Short forms open fully; long ones open as an outline. Anything with a problem opens.
    const [open, setOpen] = useState<Set<string>>(() => {
        const start = new Set<string>();
        sections.forEach((s, si) => {
            const broken = Object.keys(errors).some((k) => k.startsWith(`sections.${si}.`));
            if (sections.length <= 3 || broken) start.add(s.uid ?? '');
            s.criteria.forEach((c, ci) => {
                if (criteriaTotal <= 4 || Object.keys(errors).some((k) => k.startsWith(`sections.${si}.criteria.${ci}.`))) start.add(c.uid ?? '');
            });
        });
        return start;
    });
    const isOpen = (uid?: string) => open.has(uid ?? '');
    const toggle = (uid?: string, force?: boolean) => setOpen((prev) => {
        const next = new Set(prev);
        const key = uid ?? '';
        (force ?? !next.has(key)) ? next.add(key) : next.delete(key);
        return next;
    });
    const allUids = sections.flatMap((s) => [s.uid ?? '', ...s.criteria.map((c) => c.uid ?? '')]);
    const formTotal = sections.reduce((sum, s) => sum + sectionMax(s), 0);
    const totalMismatch = configuredTotal !== '' && num(configuredTotal) !== formTotal;
    const title = (value: { title_en: string; title_am: string }, fallback: string) => ((locale === 'am' && value.title_am) || value.title_en || value.title_am || '').trim() || fallback;

    const setSection = (si: number, patch: Partial<SectionDraft>) => onChange(sections.map((s, n) => (n === si ? { ...s, ...patch } : s)));
    const setCriteria = (si: number, criteria: Criterion[]) => setSection(si, { criteria });

    async function confirmed(message: string): Promise<boolean> {
        const result = await confirm({ title: t('assessments.actions.remove'), description: message, confirmLabel: t('assessments.actions.remove'), cancelLabel: t('assessments.actions.cancel') });
        return result.confirmed;
    }

    function jumpTo(uid?: string) {
        toggle(uid, true);
        requestAnimationFrame(() => document.getElementById(`section-${uid}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    }

    return (
        <div className="space-y-4">
            {sections.length > 0 && (
                <nav aria-label={t('assessments.builder.outline')} className="rounded-card border border-gray-200 bg-gray-50 p-3 dark:border-slate-700 dark:bg-slate-800/40">
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('assessments.builder.outline')}</p>
                        <div className="flex flex-wrap items-center gap-3 text-xs">
                            <span className="text-gray-600 dark:text-slate-300">{t('assessments.formTotal')}: <b className="tabular-nums">{fmt(formTotal)}</b>{configuredTotal !== '' && <> / {t('assessments.configured')} <b className="tabular-nums">{configuredTotal}</b></>}</span>
                            {totalMismatch && <StatusBadge tone="danger">{t('assessments.builder.totalMismatch')}</StatusBadge>}
                            <button type="button" className="font-medium text-[color:var(--color-primary)] hover:underline" onClick={() => setOpen(new Set(allUids))}>{t('assessments.builder.expandAll')}</button>
                            <button type="button" className="font-medium text-[color:var(--color-primary)] hover:underline" onClick={() => setOpen(new Set())}>{t('assessments.builder.collapseAll')}</button>
                        </div>
                    </div>
                    <ol className="flex flex-wrap gap-2">
                        {sections.map((s, si) => {
                            const broken = Object.keys(errors).some((k) => k.startsWith(`sections.${si}.`));
                            return (
                                <li key={s.uid}>
                                    <button type="button" onClick={() => jumpTo(s.uid)}
                                        className={`inline-flex min-h-9 items-center gap-2 rounded-control border bg-white px-3 py-1.5 text-xs hover:border-[color:var(--color-primary)] dark:bg-slate-900 ${broken ? 'border-red-300 dark:border-red-800' : 'border-gray-200 dark:border-slate-700'}`}>
                                        <span className="font-semibold">{si + 1}.</span>
                                        <span className="max-w-[14rem] truncate">{title(s, t('assessments.builder.untitledSection'))}</span>
                                        <span className="text-gray-500 dark:text-slate-400">· {t('assessments.builder.criteriaCount').replace(':count', String(s.criteria.length))} · {fmt(sectionMax(s))}</span>
                                    </button>
                                </li>
                            );
                        })}
                    </ol>
                </nav>
            )}

            {sections.length === 0 && <p className="text-sm text-gray-500 dark:text-slate-400">{t('assessments.noSections')}</p>}

            {sections.map((section, si) => {
                const computed = sectionMax(section);
                const mismatch = section.max_score !== '' && num(section.max_score) !== computed;
                const sectionErrors = Object.keys(errors).filter((k) => k.startsWith(`sections.${si}.`));
                const id = (field: string) => `s${si}-${field}`;
                const err = (field: string) => errors[`sections.${si}.${field}`];
                const expanded = isOpen(section.uid);
                return (
                    <section key={section.uid} id={`section-${section.uid}`} aria-label={`${t('assessments.section')} ${si + 1}`}
                        className={`scroll-mt-24 rounded-card border bg-white dark:bg-slate-900 ${sectionErrors.length ? 'border-red-300 dark:border-red-800' : 'border-gray-200 dark:border-slate-700'}`}>
                        <header className="flex flex-wrap items-center gap-2 px-4 py-3">
                            <button type="button" onClick={() => toggle(section.uid)} aria-expanded={expanded} aria-controls={`section-body-${section.uid}`}
                                className="flex min-h-9 min-w-0 flex-1 items-center gap-2 text-left">
                                {expanded ? <ChevronUp className="h-4 w-4 shrink-0 text-gray-500" aria-hidden /> : <ChevronDown className="h-4 w-4 shrink-0 text-gray-500" aria-hidden />}
                                <span className="shrink-0 text-sm font-semibold text-gray-500 dark:text-slate-400">{t('assessments.section')} {si + 1}</span>
                                <span className="min-w-0 truncate text-sm font-semibold text-gray-900 dark:text-slate-100">{title(section, t('assessments.builder.untitledSection'))}</span>
                            </button>
                            <div className="flex flex-wrap items-center gap-1.5">
                                <StatusBadge tone="neutral">{t('assessments.builder.criteriaCount').replace(':count', String(section.criteria.length))}</StatusBadge>
                                <StatusBadge tone={mismatch ? 'danger' : 'info'}>{t('assessments.sectionMax')} {fmt(computed)}{section.max_score !== '' ? ` / ${section.max_score}` : ''}</StatusBadge>
                                {!section.is_required && <StatusBadge tone="neutral">{t('assessments.builder.optional')}</StatusBadge>}
                                {sectionErrors.length > 0 && <StatusBadge tone="danger">{t('assessments.builder.hasProblems')}</StatusBadge>}
                            </div>
                            {editable && (
                                <ItemActions label={title(section, `${t('assessments.section')} ${si + 1}`)} first={si === 0} last={si === sections.length - 1}
                                    onMove={(delta) => onChange(move(sections, si, delta))}
                                    onDuplicate={() => { const copy: SectionDraft = { ...section, uid: newUid(), code: section.code ? `${section.code}-copy` : '', criteria: section.criteria.map(cloneCriterion) }; onChange([...sections.slice(0, si + 1), copy, ...sections.slice(si + 1)]); toggle(copy.uid, true); }}
                                    onRemove={async () => { if (await confirmed(t('assessments.builder.removeSectionConfirm').replace(':count', String(section.criteria.length)))) onChange(sections.filter((_, n) => n !== si)); }} />
                            )}
                        </header>

                        {expanded && (
                            <div id={`section-body-${section.uid}`} className="space-y-4 border-t border-gray-100 px-4 pb-4 pt-4 dark:border-slate-800">
                                <fieldset disabled={!editable} className="grid min-w-0 items-end gap-3 md:grid-cols-4">
                                    <Field label={t('assessments.fields.titleEn')} htmlFor={id('title-en')} error={err('title_en')} className="md:col-span-2"><input id={id('title-en')} className={inputCls} value={section.title_en} onChange={(e) => setSection(si, { title_en: e.target.value })} /></Field>
                                    <Field label={t('assessments.fields.titleAm')} htmlFor={id('title-am')} className="md:col-span-2"><input id={id('title-am')} className={inputCls} value={section.title_am} onChange={(e) => setSection(si, { title_am: e.target.value })} /></Field>
                                    <Field label={t('assessments.fields.descriptionEn')} htmlFor={id('desc-en')} className="md:col-span-2"><textarea id={id('desc-en')} rows={2} className={inputCls} value={section.description_en} onChange={(e) => setSection(si, { description_en: e.target.value })} /></Field>
                                    <Field label={t('assessments.fields.descriptionAm')} htmlFor={id('desc-am')} className="md:col-span-2"><textarea id={id('desc-am')} rows={2} className={inputCls} value={section.description_am} onChange={(e) => setSection(si, { description_am: e.target.value })} /></Field>
                                    <Field label={t('assessments.fields.code')} htmlFor={id('code')} error={err('code')}><input id={id('code')} className={inputCls} value={section.code} maxLength={40} onChange={(e) => setSection(si, { code: e.target.value })} /></Field>
                                    <Field label={t('assessments.fields.sectionMax')} htmlFor={id('max')} error={err('max_score')} help={mismatch ? `${t('assessments.computed')}: ${fmt(computed)}` : undefined}>
                                        <input id={id('max')} type="number" min={0} step="any" className={inputCls} value={section.max_score} placeholder={fmt(computed)} onChange={(e) => setSection(si, { max_score: e.target.value })} />
                                    </Field>
                                    {scoringMethod === 'weighted_score' && <Field label={t('assessments.fields.weight')} htmlFor={id('weight')} error={err('weight')}><input id={id('weight')} type="number" min={0} max={100} step="any" className={inputCls} value={section.weight} onChange={(e) => setSection(si, { weight: e.target.value })} /></Field>}
                                    <label className="flex h-[42px] items-center gap-2 text-sm text-gray-700 dark:text-slate-300"><input type="checkbox" checked={section.is_required} onChange={(e) => setSection(si, { is_required: e.target.checked })} /> {t('assessments.fields.required')}</label>
                                </fieldset>

                                <div>
                                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                        <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('assessments.builder.criteria')}</h4>
                                        {err('criteria') && <p className="text-xs text-red-600">{err('criteria')}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        {section.criteria.map((criterion, ci) => (
                                            <CriterionEditor key={criterion.uid} si={si} ci={ci} criterion={criterion} count={section.criteria.length} editable={editable}
                                                scoringMethod={scoringMethod} options={options} errors={errors} expanded={isOpen(criterion.uid)} onToggle={() => toggle(criterion.uid)}
                                                title={title(criterion, t('assessments.builder.untitledCriterion'))}
                                                onChange={(patch) => setCriteria(si, section.criteria.map((c, n) => (n === ci ? { ...c, ...patch } : c)))}
                                                onMove={(delta) => setCriteria(si, move(section.criteria, ci, delta))}
                                                onDuplicate={() => { const copy = cloneCriterion(criterion); setCriteria(si, [...section.criteria.slice(0, ci + 1), copy, ...section.criteria.slice(ci + 1)]); toggle(copy.uid, true); }}
                                                onRemove={async () => { if (await confirmed(t('assessments.builder.removeCriterionConfirm'))) setCriteria(si, section.criteria.filter((_, n) => n !== ci)); }}
                                                onCopyRatings={section.criteria.length > 1 ? () => setCriteria(si, section.criteria.map((c) => ({ ...c, options: criterion.options.map((o) => ({ ...o })) }))) : undefined} />
                                        ))}
                                    </div>
                                    {editable && (
                                        <button type="button" className={`${smallBtn} mt-3`} onClick={() => { const c = blankCriterion(); setCriteria(si, [...section.criteria, c]); toggle(c.uid, true); }}>
                                            <Plus className="h-3.5 w-3.5" aria-hidden />{t('assessments.actions.addCriterion')}
                                        </button>
                                    )}
                                </div>
                            </div>
                        )}
                    </section>
                );
            })}

            {editable && (
                <button type="button" className={smallBtn} onClick={() => { const s = blankSection(); onChange([...sections, s]); setOpen((prev) => new Set([...prev, s.uid ?? '', s.criteria[0].uid ?? ''])); }}>
                    <Plus className="h-3.5 w-3.5" aria-hidden />{t('assessments.actions.addSection')}
                </button>
            )}
        </div>
    );
}

function CriterionEditor({ si, ci, criterion, count, editable, scoringMethod, options, errors, expanded, title, onToggle, onChange, onMove, onDuplicate, onRemove, onCopyRatings }: {
    si: number; ci: number; criterion: Criterion; count: number; editable: boolean; scoringMethod: string; options: Options; errors: Errors; expanded: boolean; title: string;
    onToggle: () => void; onChange: (patch: Partial<Criterion>) => void; onMove: (delta: number) => void; onDuplicate: () => void; onRemove: () => void; onCopyRatings?: () => void;
}) {
    const { t } = useLocale();
    const id = (field: string) => `s${si}c${ci}-${field}`;
    const prefix = `sections.${si}.criteria.${ci}.`;
    const err = (field: string) => errors[`${prefix}${field}`];
    const problems = Object.keys(errors).filter((k) => k.startsWith(prefix));
    const best = bestOption(criterion);
    const maxMismatch = criterion.max_score !== '' && num(criterion.max_score) !== best;
    const setOption = (oi: number, patch: Partial<Option>) => onChange({ options: criterion.options.map((o, n) => (n === oi ? { ...o, ...patch } : o)) });
    const optionErrors = Object.entries(errors).filter(([k]) => k.startsWith(`${prefix}options`)).map(([, v]) => v).filter(Boolean) as string[];

    return (
        <div className={`rounded-card border ${problems.length ? 'border-red-300 dark:border-red-800' : 'border-gray-200 dark:border-slate-700'} bg-gray-50/60 dark:bg-slate-800/40`}>
            <div className="flex flex-wrap items-center gap-2 px-3 py-2">
                <button type="button" onClick={onToggle} aria-expanded={expanded} className="flex min-h-9 min-w-0 flex-1 items-center gap-2 text-left">
                    {expanded ? <ChevronUp className="h-4 w-4 shrink-0 text-gray-500" aria-hidden /> : <ChevronDown className="h-4 w-4 shrink-0 text-gray-500" aria-hidden />}
                    <span className="shrink-0 text-xs font-semibold tabular-nums text-gray-500 dark:text-slate-400">{si + 1}.{ci + 1}</span>
                    <span className="min-w-0 truncate text-sm font-medium text-gray-900 dark:text-slate-100">{title}</span>
                    {criterion.is_required && <span className="text-red-600" aria-label={t('assessments.fields.required')}>*</span>}
                </button>
                <div className="flex flex-wrap items-center gap-1.5">
                    <StatusBadge tone="neutral">{t('assessments.builder.ratingsCount').replace(':count', String(criterion.options.length))}</StatusBadge>
                    <StatusBadge tone={maxMismatch ? 'danger' : 'info'}>{t('assessments.builder.max')} {fmt(criterionMax(criterion))}</StatusBadge>
                    {criterion.competency_id && <StatusBadge tone="info">{t('assessments.builder.linked')}</StatusBadge>}
                    {problems.length > 0 && <StatusBadge tone="danger">{t('assessments.builder.hasProblems')}</StatusBadge>}
                </div>
                {editable && <ItemActions label={title} first={ci === 0} last={ci === count - 1} onMove={onMove} onDuplicate={onDuplicate} onRemove={onRemove} />}
            </div>

            {expanded && (
                <fieldset disabled={!editable} className="min-w-0 space-y-3 border-t border-gray-200 px-3 pb-3 pt-3 dark:border-slate-700">
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field label={t('assessments.fields.titleEn')} htmlFor={id('title-en')} error={err('title_en')}><input id={id('title-en')} className={fieldCls} value={criterion.title_en} onChange={(e) => onChange({ title_en: e.target.value })} /></Field>
                        <Field label={t('assessments.fields.titleAm')} htmlFor={id('title-am')}><input id={id('title-am')} className={fieldCls} value={criterion.title_am} onChange={(e) => onChange({ title_am: e.target.value })} /></Field>
                        <Field label={t('assessments.fields.descriptionEn')} htmlFor={id('desc-en')}><textarea id={id('desc-en')} rows={2} className={fieldCls} value={criterion.description_en} onChange={(e) => onChange({ description_en: e.target.value })} /></Field>
                        <Field label={t('assessments.fields.descriptionAm')} htmlFor={id('desc-am')}><textarea id={id('desc-am')} rows={2} className={fieldCls} value={criterion.description_am} onChange={(e) => onChange({ description_am: e.target.value })} /></Field>
                    </div>
                    <div className="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-[repeat(auto-fit,minmax(9rem,1fr))]">
                        <Field label={t('assessments.fields.code')} htmlFor={id('code')} error={err('code')}><input id={id('code')} className={fieldCls} value={criterion.code} maxLength={40} onChange={(e) => onChange({ code: e.target.value })} /></Field>
                        <Field label={t('assessments.fields.maxScore')} htmlFor={id('max')} error={err('max_score')} help={maxMismatch ? `${t('assessments.bestOption')}: ${fmt(best)}` : undefined}>
                            <input id={id('max')} type="number" min={0} step="any" className={fieldCls} value={criterion.max_score} placeholder={fmt(best)} onChange={(e) => onChange({ max_score: e.target.value })} />
                        </Field>
                        {scoringMethod === 'weighted_score' && <Field label={t('assessments.fields.weight')} htmlFor={id('weight')} error={err('weight')}><input id={id('weight')} type="number" min={0} max={100} step="any" className={fieldCls} value={criterion.weight} onChange={(e) => onChange({ weight: e.target.value })} /></Field>}
                        <Field label={t('assessments.fields.comment')} htmlFor={id('comment')}>
                            <select id={id('comment')} className={fieldCls} value={criterion.comment_mode} onChange={(e) => onChange({ comment_mode: e.target.value })}>
                                {options.input_modes.map((m) => <option key={m} value={m}>{t(`assessments.inputModes.${m}`)}</option>)}
                            </select>
                        </Field>
                        <Field label={t('assessments.fields.evidence')} htmlFor={id('evidence')}>
                            <select id={id('evidence')} className={fieldCls} value={criterion.evidence_mode} onChange={(e) => onChange({ evidence_mode: e.target.value })}>
                                {options.input_modes.map((m) => <option key={m} value={m}>{t(`assessments.inputModes.${m}`)}</option>)}
                            </select>
                        </Field>
                        <label className="flex h-[38px] items-center gap-2 text-sm text-gray-700 dark:text-slate-300"><input type="checkbox" checked={criterion.is_required} onChange={(e) => onChange({ is_required: e.target.checked })} /> {t('assessments.fields.required')}</label>
                    </div>
                    <div className="md:max-w-xl">
                        <LookupPicker type="competency" label={t('assessments.fields.competency')} value={criterion.competency_id} valueLabel={criterion.competency_label ?? null} editable={editable}
                            onPick={(picked) => onChange({ competency_id: picked?.id ?? null, competency_label: picked?.label ?? null })} />
                    </div>

                    <div>
                        <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                            <h5 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('assessments.builder.ratings')}</h5>
                            {editable && (
                                <div className="flex flex-wrap gap-3 text-xs">
                                    <button type="button" className="font-medium text-[color:var(--color-primary)] hover:underline" onClick={() => onChange({ options: [...criterion.options].sort((a, b) => (num(b.score) || 0) - (num(a.score) || 0)) })}>{t('assessments.builder.sortRatings')}</button>
                                    {onCopyRatings && <button type="button" className="font-medium text-[color:var(--color-primary)] hover:underline" onClick={onCopyRatings}>{t('assessments.builder.copyRatings')}</button>}
                                </div>
                            )}
                        </div>
                        {optionErrors.length > 0 && <p className="mb-1 text-xs text-red-600">{optionErrors[0]}</p>}
                        <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                            <table className="min-w-[720px] w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs text-gray-500 dark:bg-slate-950 dark:text-slate-400">
                                    <tr>
                                        <th className="px-2 py-2 font-medium">{t('assessments.fields.optionLabelEn')}</th>
                                        <th className="px-2 py-2 font-medium">{t('assessments.fields.optionLabelAm')}</th>
                                        <th className="px-2 py-2 font-medium">{t('assessments.fields.optionDescriptionEn')}</th>
                                        <th className="px-2 py-2 font-medium">{t('assessments.fields.optionDescriptionAm')}</th>
                                        <th className="w-24 px-2 py-2 font-medium">{t('assessments.fields.score')}</th>
                                        <th className="w-24 px-2 py-2"><span className="sr-only">{t('assessments.actions.remove')}</span></th>
                                    </tr>
                                </thead>
                                <tbody className="[&>tr]:border-t [&>tr]:border-gray-100 dark:[&>tr]:border-slate-800">
                                    {criterion.options.map((option, oi) => (
                                        <tr key={oi} className="align-top">
                                            <td className="px-2 py-1.5"><input aria-label={`${t('assessments.fields.optionLabelEn')} ${oi + 1}`} className={fieldCls} value={option.label_en} onChange={(e) => setOption(oi, { label_en: e.target.value })} /></td>
                                            <td className="px-2 py-1.5"><input aria-label={`${t('assessments.fields.optionLabelAm')} ${oi + 1}`} className={fieldCls} value={option.label_am} onChange={(e) => setOption(oi, { label_am: e.target.value })} /></td>
                                            <td className="px-2 py-1.5"><textarea rows={1} aria-label={`${t('assessments.fields.optionDescriptionEn')} ${oi + 1}`} className={fieldCls} value={option.description_en} onChange={(e) => setOption(oi, { description_en: e.target.value })} /></td>
                                            <td className="px-2 py-1.5"><textarea rows={1} aria-label={`${t('assessments.fields.optionDescriptionAm')} ${oi + 1}`} className={fieldCls} value={option.description_am} onChange={(e) => setOption(oi, { description_am: e.target.value })} /></td>
                                            <td className="px-2 py-1.5"><input type="number" min={0} step="any" aria-label={`${t('assessments.fields.score')} ${oi + 1}`} className={`${fieldCls} ${num(option.score) === best && best > 0 ? 'font-semibold' : ''}`} value={option.score} onChange={(e) => setOption(oi, { score: e.target.value })} /></td>
                                            <td className="px-2 py-1.5">
                                                {editable && (
                                                    <span className="flex items-center justify-end gap-0.5">
                                                        <IconButton label={`${t('assessments.actions.moveUp')}: ${oi + 1}`} disabled={oi === 0} onClick={() => onChange({ options: move(criterion.options, oi, -1) })}><ChevronUp className="h-4 w-4" /></IconButton>
                                                        <IconButton label={`${t('assessments.actions.moveDown')}: ${oi + 1}`} disabled={oi === criterion.options.length - 1} onClick={() => onChange({ options: move(criterion.options, oi, 1) })}><ChevronDown className="h-4 w-4" /></IconButton>
                                                        <IconButton label={`${t('assessments.actions.remove')}: ${oi + 1}`} danger disabled={criterion.options.length <= 2} onClick={() => onChange({ options: criterion.options.filter((_, n) => n !== oi) })}><TrashIcon className="h-4 w-4" /></IconButton>
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {editable && criterion.options.length < 20 && (
                            <button type="button" className={`${smallBtn} mt-2`} onClick={() => onChange({ options: [...criterion.options, blankOption('')] })}><Plus className="h-3.5 w-3.5" aria-hidden />{t('assessments.actions.addOption')}</button>
                        )}
                    </div>
                </fieldset>
            )}
        </div>
    );
}

/** Move up / down, duplicate and remove, as labelled icon buttons. */
function ItemActions({ label, first, last, onMove, onDuplicate, onRemove }: { label: string; first: boolean; last: boolean; onMove: (delta: number) => void; onDuplicate: () => void; onRemove: () => void }) {
    const { t } = useLocale();
    return (
        <span className="flex items-center gap-0.5">
            <IconButton label={`${t('assessments.actions.moveUp')}: ${label}`} disabled={first} onClick={() => onMove(-1)}><ChevronUp className="h-4 w-4" /></IconButton>
            <IconButton label={`${t('assessments.actions.moveDown')}: ${label}`} disabled={last} onClick={() => onMove(1)}><ChevronDown className="h-4 w-4" /></IconButton>
            <IconButton label={`${t('assessments.builder.duplicate')}: ${label}`} onClick={onDuplicate}><ComponentIcon className="h-4 w-4" /></IconButton>
            <IconButton label={`${t('assessments.actions.remove')}: ${label}`} danger onClick={onRemove}><TrashIcon className="h-4 w-4" /></IconButton>
        </span>
    );
}

function IconButton({ label, danger = false, disabled = false, onClick, children }: { label: string; danger?: boolean; disabled?: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button type="button" aria-label={label} title={label} disabled={disabled} onClick={onClick}
            className={`inline-flex h-9 w-9 items-center justify-center rounded-control disabled:cursor-not-allowed disabled:opacity-30 ${danger ? 'text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100'}`}>
            {children}
        </button>
    );
}

