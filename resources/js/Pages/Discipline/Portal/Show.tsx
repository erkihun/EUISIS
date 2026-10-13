import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { secondaryBtn } from '@/Components/dailyActivity/helpers';
import { EvidenceForm, FinalStatementForm, ResponseForm } from '@/Components/discipline/forms';
import {
    CaseStatusBadge, ChargeList, DeadlineList, Detail, EvidenceList, Section, penaltyLabel, useDates,
    type Abilities, type CaseDetail,
} from '@/Components/discipline/shared';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

/**
 * The accused's own case: the charge and evidence served on them, their
 * defence, the hearing, and the decision once it has been delivered. The
 * committee's deliberation and votes are not part of this view.
 */
export default function MyDisciplinaryCase({ disciplinaryCase: c, abilities: can, objectionGrounds }: { disciplinaryCase: CaseDetail; abilities: Abilities; objectionGrounds: string[] }): JSX.Element {
    const { t } = useLocale();
    const { date, dateTime } = useDates();
    const decision = c.decisions[c.decisions.length - 1];

    return (
        <AuthenticatedLayout header={<PageHeader title={`${t('discipline.myTitle')} · ${c.case_number ?? ''}`} actions={<Link href={route('employee.discipline.index')} className={secondaryBtn}>{t('discipline.myTitle')}</Link>} />}>
            <Head title={c.case_number ?? t('discipline.myTitle')} />
            <div className="grid gap-3 lg:grid-cols-3">
                <div className="space-y-3 lg:col-span-2">
                    <Section title={t('discipline.sections.overview')} actions={<CaseStatusBadge status={c.status} />}>
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <Detail label={t('discipline.trackLabel')}>{t(`discipline.tracks.${c.track}`)}</Detail>
                            <Detail label={t('discipline.chargedAt')}>{dateTime(c.charged_at)}</Detail>
                            <Detail label={t('discipline.summons.hearingAt')}>{dateTime(c.summons.hearing_at)}</Detail>
                            <Detail label={t('discipline.summons.hearingLocation')}>{c.summons.hearing_location ?? '—'}</Detail>
                        </dl>
                        {c.in_absentia && <p className="mt-2 text-sm text-amber-800 dark:text-amber-300">{t('discipline.summons.inAbsentia')}</p>}
                    </Section>

                    <Section title={t('discipline.sections.charges')}><ChargeList charges={c.charges} /></Section>

                    <Section title={t('discipline.portal.respondTitle')}>
                        {c.responses.map((r) => (
                            <p key={r.id} className="mb-1 text-xs text-gray-500 dark:text-slate-400">#{r.version} · {dateTime(r.received_at)} · {r.acknowledged_name}</p>
                        ))}
                        {can.recordResponse && (
                            <>
                                <p className="mb-3 text-sm text-gray-600 dark:text-slate-300">{t('discipline.portal.respondHelp')}</p>
                                <ResponseForm action={route('employee.discipline.response', c.id)} charges={c.charges} objectionGrounds={objectionGrounds} />
                            </>
                        )}
                    </Section>

                    <Section title={t('discipline.sections.evidence')}>
                        <EvidenceList caseId={c.id} evidence={c.evidence} routeName="employee.discipline.evidence.download" />
                        {can.addEvidence && (
                            <div className="mt-4">
                                <h3 className="mb-2 text-xs font-medium text-gray-600 dark:text-slate-400">{t('discipline.portal.evidenceTitle')}</h3>
                                <EvidenceForm action={route('employee.discipline.evidence.store', c.id)} sides={null} charges={c.charges} />
                            </div>
                        )}
                    </Section>

                    {(c.final_statement.at || can.finalStatement) && (
                        <Section title={t('discipline.portal.statementTitle')}>
                            {c.final_statement.at
                                ? <p className="whitespace-pre-line text-sm">{c.final_statement.declined ? t('discipline.actions.declined') : c.final_statement.text}</p>
                                : <FinalStatementForm action={route('employee.discipline.final-statement', c.id)} />}
                        </Section>
                    )}

                    <Section title={t('discipline.sections.decision')}>
                        {decision ? (
                            <div className="text-sm">
                                <p className="font-medium">{decision.finding ? t(`discipline.findings.${decision.finding}`) : '—'} — {penaltyLabel(t, decision.penalty, decision.penalty_amount, decision.penalty_unit)}</p>
                                <p className="mt-1 whitespace-pre-line text-gray-700 dark:text-slate-300">{decision.reason}</p>
                                <p className="mt-1 text-xs text-gray-500">{t('discipline.decision.deliveredAt')}: {dateTime(decision.delivered_at)}{decision.effective_on ? ` · ${t('discipline.decision.effectiveOn')}: ${date(decision.effective_on)}` : ''}</p>
                                <p className="mt-2 text-xs text-gray-500">{t('discipline.decision.appealNote')}</p>
                            </div>
                        ) : <p className="text-sm text-gray-500 dark:text-slate-400">{t('discipline.portal.decisionPending')}</p>}
                    </Section>
                </div>
                <div className="space-y-3">
                    <Section title={t('discipline.sections.deadlines')}><DeadlineList deadlines={c.deadlines} /></Section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
