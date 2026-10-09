import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Field, Pill, ScoreTraceView, Section, employeeName, formatScore, inputCls, pageCls, primaryBtn, smallBtn, useEnumLabel, type ScoreTrace } from '@/Components/performance/ui';
import type { AppealRow } from '@/Pages/Performance/Appeals/Index';
import { useLocale } from '@/hooks/useLocale';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    appeal: AppealRow & { reason: string; has_attachment: boolean; attachment_name: string | null; decision_reason: string | null; decided_score: string | null; trace: ScoreTrace | null };
    decisions: string[];
    can: { decide: boolean };
};

/** An appeal with the frozen score trace it disputes; a score change creates a new result revision. */
export default function AppealShow({ appeal, decisions, can }: Props) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const decided = appeal.status === 'DECIDED';
    const form = useForm({ decision: decisions[0] ?? 'UPHELD', decision_reason: '', decided_score: '' });

    return (
        <AuthenticatedLayout header={<PageHeader title={`${appeal.appeal_no} · ${employeeName(appeal.employee, locale)}`} description={appeal.employee.number ?? undefined} backHref={route('performance.appeals.index')} />}>
            <Head title={appeal.appeal_no} />
            <div className={pageCls}>
                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <Pill group="appeal" value={appeal.status} />
                    {appeal.decision && <Pill group="decision" value={appeal.decision} />}
                    <LocalizedDateDisplay value={appeal.submitted_at} withTime />
                    <span>{t('performance.fields.score')}: <span className="tabular-nums">{formatScore(appeal.result?.final_score)}</span> {(locale === 'am' && appeal.result?.rating_am) || appeal.result?.rating_en}</span>
                </div>

                <Section title={t('performance.fields.reason')}>
                    <p className="whitespace-pre-line text-sm">{appeal.reason}</p>
                    {appeal.has_attachment && <a className={`${smallBtn} mt-3`} href={route('performance.appeals.attachment', appeal.id)}>{appeal.attachment_name ?? t('performance.fields.file')}</a>}
                </Section>

                {decided ? (
                    <Section title={t('performance.fields.decision')}>
                        <p className="text-sm">{label('decision', appeal.decision)}{appeal.decided_score !== null && ` · ${formatScore(appeal.decided_score)}`}</p>
                        {appeal.decision_reason && <p className="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-slate-300">{appeal.decision_reason}</p>}
                    </Section>
                ) : can.decide && (
                    <Section title={t('performance.actions.decide')}>
                        <form onSubmit={(e) => { e.preventDefault(); form.transform((d) => ({ ...d, decided_score: d.decided_score === '' ? null : d.decided_score })); form.post(route('performance.appeals.decide', appeal.id), { preserveScroll: true }); }} className="grid gap-3 sm:grid-cols-3">
                            <Field label={t('performance.fields.decision')} error={form.errors.decision}>
                                <select className={inputCls} value={form.data.decision} onChange={(e) => form.setData('decision', e.target.value)}>{decisions.map((d) => <option key={d} value={d}>{label('decision', d)}</option>)}</select>
                            </Field>
                            <Field label={t('performance.fields.score0to200')} error={form.errors.decided_score}><input className={inputCls} inputMode="decimal" value={form.data.decided_score} onChange={(e) => form.setData('decided_score', e.target.value)} disabled={form.data.decision === 'REJECTED'} /></Field>
                            <Field label={t('performance.fields.reason')} error={form.errors.decision_reason} className="sm:col-span-3"><textarea rows={3} className={inputCls} value={form.data.decision_reason} onChange={(e) => form.setData('decision_reason', e.target.value)} required /></Field>
                            <div className="flex justify-end sm:col-span-3"><button type="submit" className={primaryBtn} disabled={form.processing}>{t('performance.actions.decide')}</button></div>
                        </form>
                    </Section>
                )}

                <Section title={t('performance.result.howCalculated')}><ScoreTraceView trace={appeal.trace} /></Section>
            </div>
        </AuthenticatedLayout>
    );
}
