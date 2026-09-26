import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Bar, Empty, Pill, ScoreTraceView, Table, fill, formatScore, nameOf, smallBtn, tdCls, thCls, titleOf, useEnumLabel, type Bilingual, type ItemTrace, type ScoreTrace } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';

export type Actual = { id: string; period_start: string; period_end: string; value: string | null; numerator: string | null; denominator: string | null; milestone_key: string | null; source: string; verified: boolean; comment: string | null };

export type AgreementItem = {
    id: string;
    objective: { code: string; title_en: string; title_am: string | null } | null;
    kpi: { id: string; code: string; name_en: string; name_am: string | null; direction: string; unit: string | null; milestones: { key: string; label_en: string; label_am?: string | null }[] | null };
    expected_output: string; weight: string; target_value: string | null; target_numerator: string | null; target_denominator: string | null;
    data_source: string; is_mandatory: boolean; is_additional: boolean; source: 'POSITION_PLAN' | 'ADDITIONAL';
    progress: ItemTrace | null; actuals: Actual[];
};

export type Review = {
    status: string; employee_self_assessment: string | null; achievements: string | null; challenges: string | null; contributions: string | null; development_needs: string | null;
    manager_comment: string | null; manager_private_note: string | null; improvement_actions: string | null; at_risk_item_ids: string[]; return_reason: string | null;
};

export type Result = {
    id: string; status: string; revision: number; results_score: string | null; competency_score: string | null; results_weight: string; competency_weight: string;
    calculated_score: string | null; adjusted_score: string | null; calibrated_score: string | null; final_score: string | null; rating_en: string | null; rating_am: string | null;
    released_at: string | null; trace: ScoreTrace | null;
    adjustments: { id: string; adjustment_type: string; original_score: string | null; adjusted_score: string | null; reason: string; status: string; created_at: string }[];
};

export type AgreementView = {
    id: string; status: string; version: number; is_temporary: boolean; effective_from: string | null; effective_to: string | null; return_reason: string | null;
    employee: { id: string; name: string | null; name_en: string | null; number: string | null };
    organization: Bilingual & { id?: string }; unit: Bilingual; position: { title_en: string | null; title_am: string | null } | null; manager: string | null;
    cycle: { id: string; name_en: string | null; name_am: string | null; status: string | null };
    plan: { id: string; title: string; version: number } | null;
    items: AgreementItem[];
    evidence: { id: string; item_id: string | null; type: string; title: string; description: string | null; has_file: boolean; original_name: string | null; verified: boolean; submitted_at: string | null }[];
    checkins: { id: string; date: string; progress_status: string; employee_summary: string | null; manager_comment: string | null; manager_private_note: string | null; blockers: string | null; support_required: string | null; learning_needs: string | null; next_actions: string | null }[];
    reviews: Partial<Record<'MID_YEAR' | 'YEAR_END', Review>>;
    competencies: { competency_id: string; code: string | null; name_en: string | null; name_am: string | null; weight: string; self_rating: number | null; manager_rating: number | null }[];
    /** Highest level of the active competency scale; ratings run 1..this. */
    competency_scale_max: number;
    result: Result | null;
    result_hidden: boolean;
    improvement_plans: { id: string; identified_gap: string; required_improvement: string; support_action: string | null; start_date: string; end_date: string; status: string }[];
    development_plans: { id: string; development_objective: string; training: string | null; coaching: string | null; expected_outcome: string | null; due_date: string | null; status: string }[];
};

/** 1..max, the levels of the competency scale (5 when the server sends none). */
export function ratingLevels(max: number | null | undefined): number[] {
    const top = Number.isInteger(max) && (max as number) > 0 ? (max as number) : 5;
    return Array.from({ length: top }, (_, index) => index + 1);
}

export function targetText(item: Pick<AgreementItem, 'target_value' | 'target_numerator' | 'target_denominator'>, unit?: string | null): string {
    const value = item.target_numerator !== null ? `${formatScore(item.target_numerator)} / ${formatScore(item.target_denominator)}` : formatScore(item.target_value);
    return unit ? `${value} ${unit}` : value;
}

/** KPIs of an agreement with live progress (not the official score). `actions` renders per-item controls. */
export function ItemsTable({ items, actions, extra }: { items: AgreementItem[]; actions?: (item: AgreementItem) => ReactNode; extra?: (item: AgreementItem) => ReactNode }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const total = items.reduce((sum, item) => sum + Number(item.weight), 0);

    return (
        <>
            <Table head={<>
                <th className={thCls}>{t('performance.fields.kpi')}</th>
                <th className={thCls}>{t('performance.fields.target')}</th>
                <th className={thCls}>{t('performance.fields.weight')}</th>
                <th className={thCls}>{t('performance.fields.progress')}</th>
                {actions && <th className={thCls}><span className="sr-only">{t('performance.actions.edit')}</span></th>}
            </>}>
                {items.map((item) => (
                    <tr key={item.id}>
                        <td className={tdCls}>
                            <p className="font-medium">{item.kpi.code} — {nameOf(item.kpi, locale)}</p>
                            <p className="text-xs text-gray-600 dark:text-slate-300">{item.expected_output}</p>
                            <p className="text-xs text-gray-500 dark:text-slate-400">
                                {item.objective && `${item.objective.code} · `}{label('source', item.data_source)} · {item.source === 'POSITION_PLAN' ? t('performance.agreements.positionPlan') : t('performance.agreements.additional')}
                                {item.is_mandatory && ` · ${t('performance.fields.mandatory')}`}
                            </p>
                            {extra?.(item)}
                        </td>
                        <td className={`${tdCls} whitespace-nowrap tabular-nums`}>{targetText(item, item.kpi.unit)}</td>
                        <td className={`${tdCls} tabular-nums`}>{formatScore(item.weight)}</td>
                        <td className={`${tdCls} w-44`}>
                            {item.progress?.achievement == null ? <span className="text-xs text-gray-500">{t('performance.notReported')}</span> : (
                                <>
                                    <span className="text-xs tabular-nums">{formatScore(item.progress.actual)} → {formatScore(item.progress.achievement)}%</span>
                                    <Bar value={item.progress.achievement} />
                                </>
                            )}
                        </td>
                        {actions && <td className={`${tdCls} whitespace-nowrap`}><div className="flex flex-wrap gap-1">{actions(item)}</div></td>}
                    </tr>
                ))}
            </Table>
            <p className={`mt-2 text-right text-xs ${Math.abs(total - 100) < 0.0001 ? 'text-gray-500' : 'text-amber-700 dark:text-amber-400'}`}>{t('performance.agreements.totalWeight')}: {formatScore(total)}%</p>
        </>
    );
}

/** Actual rows of one item; `canVerify` shows the verify button on unverified rows. */
export function ActualsList({ actuals, canVerify }: { actuals: Actual[]; canVerify?: boolean }) {
    const { t } = useLocale();
    const label = useEnumLabel();
    if (!actuals.length) return null;
    return (
        <ul className="mt-2 space-y-1 border-l-2 border-gray-100 pl-2 text-xs dark:border-slate-800">
            {actuals.map((a) => (
                <li key={a.id} className="flex flex-wrap items-center gap-2">
                    <span className="whitespace-nowrap"><LocalizedDateDisplay value={a.period_start} /> – <LocalizedDateDisplay value={a.period_end} /></span>
                    <span className="tabular-nums">{a.milestone_key ?? (a.numerator !== null ? `${formatScore(a.numerator)} / ${formatScore(a.denominator)}` : formatScore(a.value))}</span>
                    <span className="text-gray-500">{label('source', a.source)}</span>
                    {a.verified ? <span className="text-emerald-700 dark:text-emerald-400">✓ {t('performance.actions.verify')}</span>
                        : canVerify && <button type="button" className={smallBtn} onClick={() => router.post(route('performance.actuals.verify', a.id), {}, { preserveScroll: true })}>{t('performance.actions.verify')}</button>}
                    {a.comment && <span className="text-gray-500">— {a.comment}</span>}
                </li>
            ))}
        </ul>
    );
}

export function EvidenceList({ evidence, canVerify }: { evidence: AgreementView['evidence']; canVerify?: boolean }) {
    const { t } = useLocale();
    const label = useEnumLabel();
    if (!evidence.length) return <Empty>—</Empty>;
    return (
        <ul className="divide-y divide-gray-100 text-sm dark:divide-slate-800">
            {evidence.map((e) => (
                <li key={e.id} className="flex flex-wrap items-start justify-between gap-2 py-2">
                    <div className="min-w-0">
                        <p className="font-medium">{e.title} <span className="text-xs font-normal text-gray-500">· {label('evidence', e.type)}</span></p>
                        {e.description && <p className="text-xs text-gray-600 dark:text-slate-300">{e.description}</p>}
                        <p className="text-xs text-gray-500"><LocalizedDateDisplay value={e.submitted_at} withTime /></p>
                    </div>
                    <div className="flex items-center gap-2">
                        {e.has_file && <a className={smallBtn} href={route('performance.evidence.download', e.id)}>{e.original_name ?? t('performance.fields.file')}</a>}
                        {e.verified ? <span className="text-xs text-emerald-700 dark:text-emerald-400">✓</span>
                            : canVerify && <button type="button" className={smallBtn} onClick={() => router.post(route('performance.evidence.verify', e.id), {}, { preserveScroll: true })}>{t('performance.actions.verify')}</button>}
                    </div>
                </li>
            ))}
        </ul>
    );
}

export function CheckinList({ checkins, children }: { checkins: AgreementView['checkins']; children?: (checkin: AgreementView['checkins'][number]) => ReactNode }) {
    const { t } = useLocale();
    if (!checkins.length) return <Empty>—</Empty>;
    const row = (caption: string, text: string | null) => text ? <p><span className="text-gray-500">{caption}:</span> {text}</p> : null;
    return (
        <ul className="space-y-3 text-sm">
            {checkins.map((c) => (
                <li key={c.id} className="rounded-lg border border-gray-200 p-3 dark:border-slate-800">
                    <div className="mb-1 flex items-center gap-2"><LocalizedDateDisplay value={c.date} /><Pill group="health" value={c.progress_status} /></div>
                    <div className="space-y-0.5 text-xs">
                        {row(t('performance.fields.managerComment'), c.manager_comment)}
                        {row(t('performance.fields.privateNote'), c.manager_private_note)}
                        {row(t('performance.fields.employee'), c.employee_summary)}
                        {row(t('performance.fields.blockers'), c.blockers)}
                        {row(t('performance.fields.supportRequired'), c.support_required)}
                        {row(t('performance.fields.learningNeeds'), c.learning_needs)}
                        {row(t('performance.fields.nextActions'), c.next_actions)}
                    </div>
                    {children?.(c)}
                </li>
            ))}
        </ul>
    );
}

export function ReviewSummary({ review }: { review: Review | undefined }) {
    const { t } = useLocale();
    if (!review) return <p className="text-sm text-gray-500"><Pill group="review" value="DRAFT" /></p>;
    const row = (caption: string, text: string | null) => text ? <div><dt className="text-xs text-gray-500">{caption}</dt><dd className="whitespace-pre-line text-sm">{text}</dd></div> : null;
    return (
        <div className="space-y-2">
            <Pill group="review" value={review.status} />
            {review.return_reason && review.status === 'RETURNED' && <p className="text-xs text-red-700 dark:text-red-400">{review.return_reason}</p>}
            <dl className="space-y-2">
                {row(t('performance.fields.selfAssessment'), review.employee_self_assessment)}
                {row(t('performance.fields.achievements'), review.achievements)}
                {row(t('performance.fields.challenges'), review.challenges)}
                {row(t('performance.fields.contributions'), review.contributions)}
                {row(t('performance.fields.developmentNeeds'), review.development_needs)}
                {row(t('performance.fields.managerComment'), review.manager_comment)}
                {row(t('performance.fields.privateNote'), review.manager_private_note)}
                {row(t('performance.fields.improvementActions'), review.improvement_actions)}
            </dl>
        </div>
    );
}

export function ResultPanel({ result, hidden, children }: { result: Result | null; hidden: boolean; children?: ReactNode }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    if (!result) return <Empty>{hidden ? t('performance.result.hidden') : t('performance.notCalculated')}</Empty>;
    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <span className="text-3xl font-semibold tabular-nums text-gray-900 dark:text-slate-100">{formatScore(result.final_score)}</span>
                <span className="text-sm">{(locale === 'am' && result.rating_am) || result.rating_en}</span>
                <Pill group="result" value={result.status} />
                {result.revision > 1 && <span className="text-xs text-gray-500">{fill(t('performance.result.revision'), { n: result.revision })}</span>}
            </div>
            <dl className="flex flex-wrap gap-4 text-xs text-gray-600 dark:text-slate-400">
                <div>{t('performance.result.calculated')}: <span className="tabular-nums">{formatScore(result.calculated_score)}</span></div>
                {result.adjusted_score !== null && <div>{t('performance.result.adjusted')}: <span className="tabular-nums">{formatScore(result.adjusted_score)}</span></div>}
                {result.calibrated_score !== null && <div>{t('performance.result.calibrated')}: <span className="tabular-nums">{formatScore(result.calibrated_score)}</span></div>}
                {result.released_at && <div><LocalizedDateDisplay value={result.released_at} withTime /></div>}
            </dl>
            {children}
            <details open>
                <summary className="cursor-pointer text-sm font-medium">{t('performance.result.howCalculated')}</summary>
                <div className="mt-3"><ScoreTraceView trace={result.trace} /></div>
            </details>
            {result.adjustments.length > 0 && (
                <div>
                    <h3 className="text-xs font-semibold text-gray-700 dark:text-slate-300">{t('performance.result.adjustments')}</h3>
                    <ul className="mt-1 space-y-1 text-xs">
                        {result.adjustments.map((a) => (
                            <li key={a.id}>{label('adjustmentType', a.adjustment_type)}: {formatScore(a.original_score)} → {formatScore(a.adjusted_score)} · {a.reason} · <Pill group="approval" value={a.status} /></li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

export function DevelopmentPlans({ plans }: { plans: AgreementView['development_plans'] }) {
    const label = useEnumLabel();
    if (!plans.length) return <p className="text-sm text-gray-500">—</p>;
    return (
        <ul className="space-y-2 text-sm">
            {plans.map((p) => (
                <li key={p.id} className="rounded-lg bg-gray-50 p-2 dark:bg-slate-800/60">
                    <p>{p.development_objective}</p>
                    <p className="text-xs text-gray-500">{[p.training, p.coaching, p.expected_outcome].filter(Boolean).join(' · ')} {p.due_date && <>· <LocalizedDateDisplay value={p.due_date} /></>} · {label('development', p.status)}</p>
                </li>
            ))}
        </ul>
    );
}

export function agreementHeading(agreement: AgreementView, locale: string): string {
    return [titleOf(agreement.position, locale), nameOf(agreement.unit, locale) || nameOf(agreement.organization, locale), nameOf(agreement.cycle, locale)].filter(Boolean).join(' · ');
}
