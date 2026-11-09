import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { ParameterSchema } from '@/Components/organizationDesign/MethodologyForm';
import PlanForm, { type MethodologyOption, type PlanFormValues, type StudyOption } from '@/Components/organizationDesign/PlanForm';
import PlanLineForm, { type LineRecord, type Named, type ProposalOption } from '@/Components/organizationDesign/PlanLineForm';
import ValidationReport, { type Report } from '@/Components/organizationDesign/ValidationReport';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, Table, TBody, Td, Th, THead, Tr, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { PlanSummary } from './Index';
import AppActionMenu from '@/Components/ui/AppActionMenu';

type Line = LineRecord & {
    id: string; calculated_fte: string | null; calculated_at: string | null; headcount: number | null; methodology_version_no: number | null; calculation_trace: Record<string, unknown> | null;
    unit: Named | null; proposal: { id: string; name_en: string; name_am: string | null } | null; task: Named | null; activity: Named | null;
    occupation: (Named & { isco_code: string | null }) | null; profession: Named | null; methodology: { id: string; code: string; version_no: number } | null; adjusted_by: string | null; adjusted_at?: string | null;
};
type Requirement = {
    id: string; status: string; position_title_en: string; position_title_am: string | null; required_fte: string; approved_headcount: number | null;
    unit: Named | null; proposal: { id: string; name_en: string; name_am: string | null } | null; occupation: (Named & { isco_code: string | null }) | null; profession: Named | null;
    position: { id: string; job_position_code: string; title_en: string } | null; establishment: { id: string; establishment_number: string; approved_slots: number; status: string } | null;
};
type Props = {
    plan: PlanSummary & { notes: string | null; study_id: string | null; study: { id: string; reference_number: string; title_en: string } | null; submitted_at: string | null; reviewed_by: string | null; review_comment: string | null; approved_by: string | null; approved_at: string | null; implemented_at: string | null; methodology_in_force: { id: string; code: string; version_no: number; calculation_type: string; rounding_rule: string | null; parameters: Record<string, string>; inputs: ParameterSchema } };
    lines: Line[];
    requirements: Requirement[];
    totals: { calculated_fte: string; headcount: number; lines: number };
    validation: Report;
    history: { from: string | null; to: string; actor: string | null; reason: string | null; at: string }[];
    units: Named[];
    proposals: ProposalOption[];
    methodologies: MethodologyOption[];
    studies: StudyOption[];
    can: { update: boolean; submit: boolean; review: boolean; approve: boolean; cancel: boolean; implement: boolean };
};

const REQ_TONES: Record<string, 'neutral' | 'info' | 'success'> = { proposed: 'neutral', approved: 'info', implemented: 'success' };

export default function ManpowerPlanShow({ plan, lines, requirements, totals, validation, history, units, proposals, methodologies, studies, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState(false);
    const [lineEdit, setLineEdit] = useState<Line | 'new' | null>(null);
    const [trace, setTrace] = useState<Line | null>(null);
    const m = plan.methodology_in_force;
    const num = (v: string | number | null) => (v == null ? '—' : String(Number(v)));

    const values: PlanFormValues = { organization_id: plan.organization_id, title_en: plan.title_en, title_am: plan.title_am ?? '', notes: plan.notes ?? '', methodology_id: plan.methodology_id, planning_period_start: plan.planning_period_start ?? '', planning_period_end: plan.planning_period_end ?? '', study_id: plan.study_id ?? '' };

    async function act(action: 'submit' | 'approve' | 'cancel' | 'implement', variant: 'warning' | 'danger' = 'warning') {
        const { confirmed } = await confirm({ title: tt(`plans.${action}`), description: tt(`plans.${action}Confirm`), variant });
        if (confirmed) router.post(route(`organization-design.manpower-plans.${action}`, plan.id), {}, { preserveScroll: true });
    }
    async function returnToDraft() {
        const { confirmed, reason } = await confirm({ title: tt('plans.return'), description: tt('plans.returnPrompt'), variant: 'warning', requireReason: true });
        if (confirmed) router.post(route('organization-design.manpower-plans.return', plan.id), { reason }, { preserveScroll: true });
    }
    async function removeLine(line: Line) {
        const { confirmed } = await confirm({ title: tt('plans.removeLine'), description: tt('plans.removeLineConfirm'), variant: 'danger' });
        if (confirmed) router.delete(route('organization-design.manpower-plans.lines.destroy', line.id), { preserveScroll: true });
    }
    const calculate = (line: Line) => router.post(route('organization-design.manpower-plans.lines.calculate', line.id), {}, { preserveScroll: true });

    const detail = (label: string, value: React.ReactNode) => (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 wrap-break-word text-sm text-(--app-foreground)">{value === null || value === undefined || value === '' ? '—' : value}</dd>
        </div>
    );
    const subjectOf = (x: { unit: Named | null; proposal: { name_en: string; name_am: string | null } | null }) => x.unit ? `${x.unit.code} · ${nameOf(x.unit, locale)}` : x.proposal ? `${tt('plans.proposedUnit')}: ${nameOf(x.proposal, locale)}` : '—';

    return (
        <AuthenticatedLayout header={
            <PageHeader
                backHref={route('organization-design.manpower-plans.index')}
                title={`${plan.reference_number} · ${titleOf(plan, locale)}`}
                description={`${nameOf(plan.organization, locale)} · ${plan.planning_period_start} – ${plan.planning_period_end}`}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        {can.update && <Button variant="outline" onClick={() => setEditing(true)}>{tt('plans.edit')}</Button>}
                        {can.update && lines.length > 0 && <Button variant="outline" onClick={() => router.post(route('organization-design.manpower-plans.calculate', plan.id), {}, { preserveScroll: true })}>{tt('plans.calculateAll')}</Button>}
                        {can.cancel && <Button variant="destructiveOutline" onClick={() => act('cancel', 'danger')}>{tt('plans.cancel')}</Button>}
                        {can.submit && <Button variant="primary" onClick={() => act('submit')}>{tt('plans.submit')}</Button>}
                        {can.review && <Button variant="outline" onClick={returnToDraft}>{tt('plans.return')}</Button>}
                        {can.approve && <Button variant="primary" onClick={() => act('approve')}>{tt('plans.approve')}</Button>}
                        {can.implement && <Button variant="primary" onClick={() => act('implement')}>{tt('plans.implement')}</Button>}
                    </div>
                )}
            />
        }>
            <Head title={`${plan.reference_number} · ${titleOf(plan, locale)}`} />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <CardTitle>{tt('plans.details')}</CardTitle>
                            <StatusPill status={plan.status} />
                        </CardHeader>
                        <dl className="grid gap-4 p-5 sm:grid-cols-2">
                            {detail(tt('plans.methodologyInForce'), `${m.code} v${m.version_no} · ${tt(`methodologies.strategies.${m.calculation_type}`)}${m.rounding_rule ? ` · ${tt(`methodologies.roundingRules.${m.rounding_rule}`)}` : ''}`)}
                            {detail(tt('methodologies.parameters'), Object.entries(m.parameters).map(([k, v]) => `${tt(`methodologies.parameterNames.${k}`)}: ${v}`).join(' · '))}
                            {detail(tt('fields.study'), plan.study ? `${plan.study.reference_number} · ${plan.study.title_en}` : null)}
                            {detail(tt('fields.notes'), plan.notes)}
                            {detail(tt('plans.preparedBy'), plan.prepared_by)}
                            {detail(tt('plans.reviewedBy'), plan.reviewed_by)}
                            {detail(tt('plans.reviewComment'), plan.review_comment)}
                            {detail(tt('plans.approvedBy'), plan.approved_by)}
                        </dl>
                        {plan.status === 'under_review' && !can.approve && can.review && <p className="border-t border-(--app-border) px-5 py-3 text-xs text-(--app-muted-foreground)">{tt('plans.approveByOther')}</p>}
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                            <div><CardTitle>{tt('plans.lines')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('plans.linesHelp')}</p></div>
                            <div className="flex items-center gap-2">
                                <span className={badgeClassName('info')}>{tt('plans.totalFte').replace(':fte', num(totals.calculated_fte))}</span>
                                <span className={badgeClassName('success')}>{tt('plans.totalHeadcount').replace(':count', String(totals.headcount))}</span>
                                {can.update && <Button size="sm" variant="primary" onClick={() => setLineEdit('new')}>{tt('plans.addLine')}</Button>}
                            </div>
                        </CardHeader>
                        {lines.length === 0 ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('plans.noLines')}</p> : (
                            <Table>
                                <THead>
                                    <tr>
                                        <Th>{tt('plans.unitOrProposal')}</Th>
                                        <Th>{tt('fields.occupation')}</Th>
                                        <Th>{tt('fields.task')}</Th>
                                        <Th>{tt('plans.inputs')}</Th>
                                        <Th>{tt('plans.workloadSource')}</Th>
                                        <Th align="right">{tt('plans.fte')}</Th>
                                        <Th align="right">{tt('plans.recommended')}</Th>
                                        <Th align="right">{tt('plans.approvedHeadcount')}</Th>
                                        <Th align="right">{t('common.actions')}</Th>
                                    </tr>
                                </THead>
                                <TBody>
                                    {lines.map((l) => (
                                        <Tr key={l.id}>
                                            <Td>{subjectOf(l)}</Td>
                                            <Td muted>{l.occupation ? `${l.occupation.isco_code ?? l.occupation.code} · ${nameOf(l.occupation, locale)}` : '—'}{l.profession ? ` › ${nameOf(l.profession, locale)}` : ''}</Td>
                                            <Td muted>{l.task ? `${l.task.code} · ${nameOf(l.task, locale)}` : '—'}</Td>
                                            <Td muted className="text-xs">{Object.entries(l.inputs ?? {}).map(([k, v]) => `${tt(`methodologies.inputNames.${k}`)}: ${v}`).join(' · ') || '—'}</Td>
                                            <Td muted className="text-xs">{l.workload_source_type ? <>{tt(`plans.workloadSources.${l.workload_source_type}`)}{l.workload_source_reference && <p>{l.workload_source_reference}</p>}</> : <span className={badgeClassName('warning')}>—</span>}</Td>
                                            <Td align="right" className="font-mono text-xs">{l.calculated_fte ?? <span className={badgeClassName('warning')}>{tt('plans.notCalculated')}</span>}{l.methodology_version_no != null && <span className="ml-1 text-(--app-muted-foreground)">v{l.methodology_version_no}</span>}</Td>
                                            <Td align="right">{l.recommended_headcount ?? '—'}</Td>
                                            <Td align="right">
                                                {l.approved_headcount ?? '—'}
                                                {l.adjustment_reason && <p className="text-xs text-(--app-muted-foreground)">{l.adjustment_reason}</p>}
                                                {/* The decision's evidence: who, when, on what reference. The calculated FTE beside it is never changed. */}
                                                {l.adjusted_by && <p className="text-[11px] text-(--app-muted-foreground)">{l.adjusted_by}{l.adjusted_at && <> · <LocalizedDateDisplay value={l.adjusted_at} /></>}{l.adjustment_reference ? ` · ${l.adjustment_reference}` : ''}</p>}
                                            </Td>
                                            <Td align="right">
                                                <div className="flex justify-end">
                                                    <AppActionMenu
                                                        items={[
                                                            { label: tt('plans.trace'), onClick: () => setTrace(l), show: !!(l.calculation_trace) },
                                                            { label: tt('plans.calculate'), onClick: () => calculate(l), show: can.update },
                                                            { label: t('common.edit'), onClick: () => setLineEdit(l), show: can.update },
                                                            { label: t('common.remove'), onClick: () => removeLine(l), variant: 'danger', show: can.update },
                                                        ]}
                                                    />
                                                </div>
                                            </Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>{tt('plans.requirements')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('plans.requirementsHelp')}</p></CardHeader>
                        {requirements.length === 0 ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('plans.noRequirements')}</p> : (
                            <Table>
                                <THead>
                                    <tr>
                                        <Th>{tt('plans.unitOrProposal')}</Th>
                                        <Th>{tt('fields.occupation')}</Th>
                                        <Th align="right">{tt('plans.fte')}</Th>
                                        <Th align="right">{tt('plans.approvedHeadcount')}</Th>
                                        <Th>{tt('plans.position')}</Th>
                                        <Th>{tt('fields.status')}</Th>
                                    </tr>
                                </THead>
                                <TBody>
                                    {requirements.map((r) => (
                                        <Tr key={r.id}>
                                            <Td>{subjectOf(r)}{!r.unit && r.proposal && r.status !== 'implemented' && <p className="text-xs text-(--app-muted-foreground)">{tt('plans.waitingForUnit')}</p>}</Td>
                                            <Td muted>{r.occupation ? `${r.occupation.isco_code ?? r.occupation.code} · ${nameOf(r.occupation, locale)}` : '—'}{r.profession ? ` › ${nameOf(r.profession, locale)}` : ''}</Td>
                                            <Td align="right" className="font-mono text-xs">{r.required_fte}</Td>
                                            <Td align="right">{r.approved_headcount ?? '—'}</Td>
                                            <Td muted>
                                                {r.position ? <Link href={route('positions.show', r.position.id)} className={actionLinkClassName({ size: 'sm' })}>{r.position.job_position_code}</Link> : '—'}
                                                {r.establishment && <p className="text-xs">{r.establishment.establishment_number} · {tt('plans.slots').replace(':count', String(r.establishment.approved_slots))}</p>}
                                            </Td>
                                            <Td><span className={badgeClassName(REQ_TONES[r.status] ?? 'neutral')}>{tt(`plans.requirementStatuses.${r.status}`)}</span></Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>
                </div>

                <div className="space-y-4">
                    <ValidationReport report={validation} compact />
                    <Card>
                        <CardHeader><CardTitle>{tt('plans.history')}</CardTitle></CardHeader>
                        <ul className="divide-y divide-(--app-border)">
                            {history.map((h, i) => (
                                <li key={i} className="px-5 py-3 text-sm">
                                    <p className="font-medium text-(--app-foreground)">{h.from ? `${tt(`status.${h.from}`)} → ` : ''}{tt(`status.${h.to}`)}</p>
                                    <p className="text-xs text-(--app-muted-foreground)">{h.actor ?? '—'} · <LocalizedDateDisplay value={h.at} withTime /></p>
                                    {h.reason && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{h.reason}</p>}
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </div>

            {can.update && <PlanForm show={editing} onClose={() => setEditing(false)} title={tt('plans.edit')} initial={values} url={route('organization-design.manpower-plans.update', plan.id)} method="put" methodologies={methodologies} studies={studies} />}
            {lineEdit && <PlanLineForm planId={plan.id} organizationId={plan.organization_id} record={lineEdit === 'new' ? null : lineEdit} units={units} proposals={proposals} inputSchema={m.inputs} onClose={() => setLineEdit(null)} />}
            {trace && (
                <TraceModal line={trace} onClose={() => setTrace(null)} />
            )}
        </AuthenticatedLayout>
    );
}

function TraceModal({ line, onClose }: { line: Line; onClose: () => void }) {
    const { t, tt } = useDesignText();
    const rows = Object.entries(line.calculation_trace ?? {}).filter(([k]) => !['parameters', 'inputs', 'methodology'].includes(k));
    const nested = ['parameters', 'inputs'] as const;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" onClick={onClose}>
            <div className="max-h-[80vh] w-full max-w-xl overflow-y-auto rounded-card bg-(--app-surface) p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
                <h2 className="text-base font-semibold text-(--app-foreground)">{tt('plans.trace')}</h2>
                <dl className="mt-4 space-y-2 text-sm">
                    {rows.map(([k, v]) => <div key={k} className="grid grid-cols-[12rem_1fr] gap-2"><dt className="text-xs font-medium text-(--app-muted-foreground)">{k}</dt><dd className="break-all font-mono text-xs">{String(v)}</dd></div>)}
                    {nested.map((group) => (
                        <div key={group}>
                            <dt className="mt-3 text-xs font-semibold uppercase text-(--app-muted-foreground)">{group}</dt>
                            {Object.entries((line.calculation_trace?.[group] as Record<string, string>) ?? {}).map(([k, v]) => <div key={k} className="grid grid-cols-[12rem_1fr] gap-2"><dt className="text-xs text-(--app-muted-foreground)">{k}</dt><dd className="font-mono text-xs">{v}</dd></div>)}
                        </div>
                    ))}
                </dl>
                <div className="mt-6 flex justify-end"><Button variant="outline" onClick={onClose}>{t('common.close')}</Button></div>
            </div>
        </div>
    );
}
