import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import OfficialAssessmentSheet from '@/Components/assessmentForms/OfficialAssessmentSheet';
import { Section, pageCls, secondaryBtn } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

type OptionView = { id: string; label_en: string | null; label_am: string | null; description_en: string | null; description_am: string | null; score: string };
type CriterionView = { id: string; code: string | null; title_en: string; title_am: string | null; description_en: string | null; description_am: string | null; max_score: string | null; is_required: boolean; comment_mode: string; evidence_mode: string; options: OptionView[] };
type SectionView = { id: string; code: string | null; title_en: string; title_am: string | null; description_en: string | null; description_am: string | null; computed_max: string; criteria: CriterionView[] };

type Props = {
    form: { id: string; code: string; name_en: string; name_am: string | null };
    version: { id: string; version_no: number; status: string; name_en: string; name_am: string | null; instructions_en: string | null; instructions_am: string | null; computed_max: string | null; sections: SectionView[]; evaluators: { evaluator_type: string; required_count: number }[] };
};

/**
 * How an evaluator will see a form version. Nothing is saved and no
 * assessment is created; the total is a preview of the server's scoring.
 */
export default function AssessmentFormPreview({ form, version }: Props) {
    const { t, locale } = useLocale();
    const [picked, setPicked] = useState<Record<string, string>>({});
    const [view, setView] = useState<'evaluator' | 'official'>('evaluator');
    // One blank score column per expected evaluator, as on the paper form.
    const evaluatorColumns = Math.max(1, version.evaluators.reduce((sum, scheme) => sum + scheme.required_count, 0));
    const toggle = (active: boolean) => `${secondaryBtn} ${active ? 'ring-2 ring-[color:var(--color-primary)]' : ''}`;
    const pick = (en: string | null | undefined, am: string | null | undefined) => (locale === 'am' && am) || en || am || '';
    const total = version.sections.reduce((sum, section) => sum + section.criteria.reduce((subtotal, criterion) => subtotal + Number(criterion.options.find((option) => option.id === picked[criterion.id])?.score ?? 0), 0), 0);
    const max = Number(version.computed_max ?? 0);

    return (
        <AuthenticatedLayout header={<PageHeader title={`${t('assessments.actions.preview')} · ${pick(version.name_en, version.name_am)}`}
            description={`${form.code} · ${t('assessments.version')} ${version.version_no} · ${t(`assessments.versionStatuses.${version.status}`)}`}
            actions={<div className="flex flex-wrap gap-2 print:hidden">
                <button type="button" className={toggle(view === 'evaluator')} aria-pressed={view === 'evaluator'} onClick={() => setView('evaluator')}>{t('assessments.sheet.viewEvaluator')}</button>
                <button type="button" className={toggle(view === 'official')} aria-pressed={view === 'official'} onClick={() => setView('official')}>{t('assessments.sheet.viewOfficial')}</button>
                <button type="button" className={secondaryBtn} onClick={() => window.print()}>{t('assessments.sheet.print')}</button>
                <Link href={route('assessment-forms.show', { form: form.id, version: version.id })} className={secondaryBtn}>{t('assessments.actions.back')}</Link>
            </div>} />}>
            <Head title={t('assessments.actions.preview')} />
            <style>{'@media print { @page { size: A4 landscape; margin: 10mm; } body * { visibility: hidden; } .official-print, .official-print * { visibility: visible; } .official-print { display: block !important; position: absolute; left: 0; top: 0; width: 100%; } }'}</style>
            <div className={view === 'official' ? 'official-print overflow-x-auto rounded-panel border border-gray-200 print:rounded-none print:border-0 dark:border-slate-700' : 'official-print hidden'}>
                <OfficialAssessmentSheet code={form.code} title={{ en: version.name_en, am: version.name_am }} sections={version.sections}
                    columns={Array.from({ length: evaluatorColumns }, () => null)} />
            </div>
            <div className={`${pageCls} max-w-4xl ${view === 'official' ? 'hidden' : ''}`}>
                <p role="note" className="rounded-panel border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">{t('assessments.previewNote')}</p>
                {pick(version.instructions_en, version.instructions_am) && <p className="whitespace-pre-line text-sm text-gray-700 dark:text-slate-300">{pick(version.instructions_en, version.instructions_am)}</p>}

                {version.sections.map((section, si) => (
                    <Section key={section.id} title={`${si + 1}. ${pick(section.title_en, section.title_am)}`} description={pick(section.description_en, section.description_am) || undefined}
                        actions={<span className="text-xs text-gray-500 dark:text-slate-400">{t('assessments.sectionMax')}: {Number(section.computed_max)}</span>}>
                        <div className="space-y-5">
                            {section.criteria.map((criterion, ci) => (
                                <fieldset key={criterion.id}>
                                    <legend className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                                        {si + 1}.{ci + 1} {pick(criterion.title_en, criterion.title_am)}{criterion.is_required && <span className="text-red-600"> *</span>}
                                    </legend>
                                    {pick(criterion.description_en, criterion.description_am) && <p className="mt-1 text-sm text-gray-600 dark:text-slate-400">{pick(criterion.description_en, criterion.description_am)}</p>}
                                    <div className="mt-2 space-y-1.5">
                                        {criterion.options.map((option) => (
                                            <label key={option.id} className="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 px-3 py-2 text-sm hover:bg-gray-50 dark:border-slate-700 dark:hover:bg-slate-800/50">
                                                <input type="radio" name={criterion.id} className="mt-0.5" checked={picked[criterion.id] === option.id} onChange={() => setPicked({ ...picked, [criterion.id]: option.id })} />
                                                <span className="min-w-0 flex-1">
                                                    {pick(option.label_en, option.label_am) && <span className="block font-medium">{pick(option.label_en, option.label_am)}</span>}
                                                    <span className="block text-gray-700 dark:text-slate-300">{pick(option.description_en, option.description_am)}</span>
                                                </span>
                                                <span className="shrink-0 tabular-nums text-gray-500">{Number(option.score)}</span>
                                            </label>
                                        ))}
                                    </div>
                                    {criterion.comment_mode !== 'disabled' && <textarea rows={2} aria-label={t('assessments.fields.comment')} placeholder={`${t('assessments.fields.comment')}${criterion.comment_mode === 'required' ? ' *' : ''}`} className="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950" />}
                                    {criterion.evidence_mode !== 'disabled' && <p className="mt-1 text-xs text-gray-500">{t('assessments.evidencePreview')}{criterion.evidence_mode === 'required' ? ' *' : ''}</p>}
                                </fieldset>
                            ))}
                        </div>
                    </Section>
                ))}

                <div className="sticky bottom-3 rounded-panel border border-gray-200 bg-white/95 px-4 py-3 text-sm shadow-lg backdrop-blur dark:border-slate-700 dark:bg-slate-950/95">
                    {t('assessments.previewTotal')}: <span className="font-semibold tabular-nums">{Math.round(total * 10000) / 10000} / {max}</span>
                    {max > 0 && <span className="ms-2 text-gray-500">({Math.round((total / max) * 10000) / 100}%)</span>}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
