import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import OfficialAssessmentSheet, { type SheetColumn } from '@/Components/assessmentForms/OfficialAssessmentSheet';
import { RecordStatus } from '@/Components/assessmentOversight/RecordStatus';
import { Details, Field, Section, formatScore, inputCls, nameOf, pageCls, primaryBtn, secondaryBtn, titleOf } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Alert } from '@euisis/ui';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Option = { id: string; label_en: string; label_am: string | null; description_en: string | null; description_am: string | null; score: string | number };
type Props = {
    record: {
        id: string; status: string; employee_snapshot: { name: string; number: string; gender: string; organization: string; unit: string; position: string; grade: string };
        period_start: string; period_end: string; percentage: string | number | null; contribution: string | number | null;
        reviewer: { name: string } | null; reviewed_at: string | null; acknowledged_at: string | null; unassessed_reason: string | null;
    };
    version: { name_en: string; name_am: string | null; version_no: number; code: string | null; sections: { id: string; title_en: string; title_am: string | null; criteria: { id: string; code: string | null; title_en: string; title_am: string | null; max_score: string | null; is_required: boolean; options: Option[] }[] }[] };
    /** Official printed form: one score column per evaluator; null where the viewer may not see it. */
    sheet: { columns: SheetColumn[] };
    response: null | { answers: Record<string, string>; submitted_at: string | null };
    can: { submit: boolean; review: boolean; acknowledge: boolean; unassessed: boolean };
};

/** One assessment: the evaluator's ratings, then review and acknowledgement. Printable. */
export default function AssessmentRecordShow({ record, version, response, sheet, can }: Props) {
    const { t, locale } = useLocale();
    const form = useForm({ answers: response?.answers ?? {} as Record<string, string> });
    const signoff = useForm({});
    const absence = useForm({ reason: 'illness', note: '' });
    const title = `${record.employee_snapshot.name} · ${nameOf(version, locale)}`;
    const count = version.sections.reduce((sum, section) => sum + section.criteria.filter((c) => c.is_required).length, 0);
    const answered = version.sections.reduce((sum, section) => sum + section.criteria.filter((c) => c.is_required && c.options.some((o) => String(o.id) === String(form.data.answers[c.id] ?? ''))).length, 0);
    const labelOf = (option: Option) => (locale === 'am' && option.label_am) || option.label_en;
    const errorMessages = [...Object.values(form.errors), ...Object.values(signoff.errors), ...Object.values(absence.errors)];
    const r = (key: string) => t(`assessments.records.${key}`);
    const dateTime = (value: string | null) => (value ? <LocalizedDateDisplay value={value} withTime /> : '—');

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('assessment-records.submit', record.id), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={r('showDescription')}
            actions={<div className="flex gap-2 print:hidden">
                <Link href={route('assessment-records.index')} className={secondaryBtn}>{r('back')}</Link>
                <button type="button" className={secondaryBtn} onClick={() => window.print()}>{r('print')}</button>
            </div>} />}>
            <Head title={title} />
            <style>{'@media print { @page { size: A4 landscape; margin: 10mm; } body * { visibility: hidden; } #assessment-official-sheet, #assessment-official-sheet * { visibility: visible; } #assessment-official-sheet { display: block !important; position: absolute; left: 0; top: 0; width: 100%; } }'}</style>
            <div id="assessment-official-sheet" className="hidden">
                <OfficialAssessmentSheet code={version.code ?? ''} title={{ en: version.name_en, am: version.name_am }} sections={version.sections} columns={sheet.columns}
                    header={{ unit: record.employee_snapshot.unit || record.employee_snapshot.organization, employee: record.employee_snapshot.name, position: record.employee_snapshot.position, grade: record.employee_snapshot.grade, periodStart: record.period_start, periodEnd: record.period_end }}
                    signatures={{ assessed: { name: record.employee_snapshot.name, date: record.acknowledged_at }, supervisor: { name: record.reviewer?.name, date: record.reviewed_at } }}
                    footer={<>
                        {record.percentage !== null && <p className="font-semibold">{t('assessments.sheet.result')}: {formatScore(record.percentage)}%{record.contribution !== null && <> · {t('assessments.sheet.contribution')}: {formatScore(record.contribution)}</>}</p>}
                        {sheet.columns.some((column) => column === null) && <p className="text-[11px]">{t('assessments.sheet.othersHidden')}</p>}
                    </>} />
            </div>
            <div id="assessment-print" className={pageCls}>
                <Section title={nameOf(version, locale)} description={`${r('version')} ${version.version_no}`}>
                    <Details items={[
                        [r('employee'), record.employee_snapshot.name], [r('employeeNumber'), record.employee_snapshot.number],
                        [r('organization'), record.employee_snapshot.organization], [r('unit'), record.employee_snapshot.unit],
                        [r('position'), record.employee_snapshot.position], [r('grade'), record.employee_snapshot.grade],
                        [r('period'), <><LocalizedDateDisplay value={record.period_start} /> – <LocalizedDateDisplay value={record.period_end} /></>],
                        [r('status'), <RecordStatus value={record.status} />],
                    ]} />
                </Section>

                {errorMessages.length > 0 && <Alert tone="danger" className="print-hidden">{errorMessages.map((error, index) => <p key={index}>{String(error)}</p>)}</Alert>}
                {response?.submitted_at && <Alert tone="success">{r('submittedOn')} <LocalizedDateDisplay value={response.submitted_at} withTime /></Alert>}

                {(can.submit || response) && (
                    <form onSubmit={submit} className="space-y-4">
                        {version.sections.map((section, si) => (
                            <Section key={section.id} title={`${si + 1}. ${titleOf(section, locale)}`}>
                                <div className="space-y-5">
                                    {section.criteria.map((criterion, index) => (
                                        <fieldset key={criterion.id} disabled={!can.submit || form.processing} className="space-y-2">
                                            <legend className="mb-2 text-sm font-semibold text-gray-900 dark:text-slate-100">{si + 1}.{index + 1} {titleOf(criterion, locale)}{criterion.is_required && <span className="text-red-600"> *</span>}</legend>
                                            {criterion.options.map((option) => {
                                                const selected = String(form.data.answers[criterion.id] ?? '') === String(option.id);
                                                const description = (locale === 'am' && option.description_am) || option.description_en;
                                                return (
                                                    <label key={option.id} className={`flex min-h-11 cursor-pointer items-start gap-3 rounded-card border px-3.5 py-3 text-sm ${selected ? 'border-[color:var(--color-primary)] bg-[color:var(--color-primary-light)]/50 dark:bg-slate-800' : 'border-gray-200 hover:bg-gray-50 dark:border-slate-700 dark:hover:bg-slate-800/50'}`}>
                                                        <input type="radio" name={`criterion-${criterion.id}`} value={option.id} checked={selected} required={criterion.is_required}
                                                            onChange={() => form.setData('answers', { ...form.data.answers, [criterion.id]: String(option.id) })} className="mt-1 accent-[color:var(--color-primary)]" />
                                                        <span className="min-w-0 flex-1">
                                                            {labelOf(option) && <span className="block font-medium">{labelOf(option)}</span>}
                                                            {description && <span className="block text-gray-600 dark:text-slate-400">{description}</span>}
                                                        </span>
                                                        <span className="shrink-0 tabular-nums text-gray-500">{formatScore(option.score)}</span>
                                                    </label>
                                                );
                                            })}
                                        </fieldset>
                                    ))}
                                </div>
                            </Section>
                        ))}
                        {can.submit && (
                            <div className="sticky bottom-3 flex flex-wrap items-center justify-between gap-3 rounded-panel border border-gray-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur print-hidden dark:border-slate-700 dark:bg-slate-950/95">
                                <span className="text-sm text-gray-600 dark:text-slate-300">{r('answered').replace(':answered', String(answered)).replace(':count', String(count))}</span>
                                <button disabled={form.processing || answered !== count} className={primaryBtn}>{r('submitRatings')}</button>
                            </div>
                        )}
                    </form>
                )}

                <Section title={r('resultTitle')} description={r('resultHelp')}>
                    <Details items={[
                        [r('normalizedScore'), record.percentage == null ? '—' : `${formatScore(record.percentage)}%`],
                        [r('contributionPoints'), formatScore(record.contribution)],
                        [r('reviewer'), record.reviewer?.name ?? '—'], [r('reviewedAt'), dateTime(record.reviewed_at)],
                        [r('acknowledgedAt'), dateTime(record.acknowledged_at)],
                        [r('reasonNotAssessed'), record.unassessed_reason ? t(`assessments.records.${record.unassessed_reason === 'illness' ? 'illness' : 'otherReason'}`) : '—'],
                    ]} />
                    {(can.review || can.acknowledge) && (
                        <div className="mt-4 flex gap-3 print-hidden">
                            {can.review && <button type="button" disabled={signoff.processing} className={primaryBtn} onClick={() => signoff.post(route('assessment-records.review', record.id), { preserveScroll: true })}>{r('confirmReview')}</button>}
                            {can.acknowledge && <button type="button" disabled={signoff.processing} className={primaryBtn} onClick={() => signoff.post(route('assessment-records.acknowledge', record.id), { preserveScroll: true })}>{r('acknowledge')}</button>}
                        </div>
                    )}
                </Section>

                {can.unassessed && (
                    <Section className="print-hidden" title={r('nonAssessmentTitle')}>
                        <form className="grid gap-3 sm:max-w-xl" onSubmit={(e) => { e.preventDefault(); absence.post(route('assessment-records.unassessed', record.id), { preserveScroll: true }); }}>
                            <Field label={r('reason')} htmlFor="absence-reason">
                                <select id="absence-reason" className={inputCls} value={absence.data.reason} onChange={(e) => absence.setData('reason', e.target.value)}>
                                    <option value="illness">{r('illness')}</option>
                                    <option value="other">{r('otherReason')}</option>
                                </select>
                            </Field>
                            <Field label={r('explanation')} htmlFor="absence-note">
                                <textarea required id="absence-note" maxLength={2000} rows={3} className={inputCls} value={absence.data.note} onChange={(e) => absence.setData('note', e.target.value)} />
                            </Field>
                            <div><button disabled={absence.processing} className={secondaryBtn}>{r('markUnassessed')}</button></div>
                        </form>
                    </Section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
