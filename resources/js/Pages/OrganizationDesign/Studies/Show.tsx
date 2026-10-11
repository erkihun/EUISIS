import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ProposalForm, { type ProposalOption, type ProposalRecord } from '@/Components/organizationDesign/ProposalForm';
import ResponsibilitiesForm, { ResponsibilitiesSummary, type ObjectCatalog, type ResponsibilityRow, type ResponsibilityTypeOption } from '@/Components/organizationDesign/ResponsibilitiesForm';
import StudyForm, { type Option, type StudyFormValues } from '@/Components/organizationDesign/StudyForm';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { StudySummary } from './Index';

type ProposalNode = ProposalRecord & {
    id: string; status: string; organizational_change_request_id: string | null; resulting_unit_id: string | null;
    existing_unit: Option | null; parent_existing: Option | null; unit_type_record: Option | null;
    change_request: { id: string; request_no: string; status: string } | null; resulting_unit: Option | null;
    responsibilities: ResponsibilityRow[]; children: ProposalNode[];
};

type Props = {
    study: StudySummary & { reason: string | null; scope_notes: string | null; hierarchy_version_id: string | null; hierarchy_version: { id: string; version_name: string } | null; submitted_at: string | null; reviewed_by: string | null; reviewed_at: string | null; review_comment: string | null; approved_by: string | null; approved_at: string | null };
    proposals: ProposalNode[];
    history: { from: string | null; to: string; actor: string | null; reason: string | null; at: string }[];
    units: (Option & { parent_unit_id: string | null })[];
    unitTypes: Option[];
    hierarchyVersions: { id: string; version_name: string; status: string }[];
    objects: ObjectCatalog;
    responsibilityTypes: ResponsibilityTypeOption[];
    proposalTypes: string[];
    studyTypes: string[];
    can: { update: boolean; submit: boolean; review: boolean; approve: boolean; cancel: boolean; generate: boolean };
};

function flatten(tree: ProposalNode[], depth = 0, out: ProposalOption[] = []): ProposalOption[] {
    for (const node of tree) { out.push({ id: node.id, name_en: node.name_en, name_am: node.name_am, depth }); flatten(node.children, depth + 1, out); }
    return out;
}

const PROPOSAL_TONES: Record<string, 'neutral' | 'info' | 'success' | 'warning' | 'danger'> = { proposed: 'neutral', accepted: 'info', converted: 'warning', implemented: 'success', rejected: 'danger' };

export default function StudyShow({ study, proposals, history, units, unitTypes, hierarchyVersions, objects, responsibilityTypes, proposalTypes, studyTypes, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState(false);
    const [proposalEdit, setProposalEdit] = useState<{ record: ProposalNode | null; parentId: string | null } | null>(null);
    const [respEdit, setRespEdit] = useState<ProposalNode | null>(null);
    const allProposals = flatten(proposals);

    const values: StudyFormValues = {
        organization_id: study.organization_id, title_en: study.title_en, title_am: study.title_am ?? '', study_type: study.study_type, reason: study.reason ?? '', scope_notes: study.scope_notes ?? '',
        started_at: study.started_at ?? '', target_effective_date: study.target_effective_date ?? '', hierarchy_version_id: study.hierarchy_version_id ?? '',
    };

    async function act(action: 'submit' | 'approve' | 'cancel' | 'generate', variant: 'warning' | 'danger' = 'warning') {
        const { confirmed } = await confirm({ title: tt(`studies.${action}`), description: tt(`studies.${action}Confirm`), variant });
        if (confirmed) router.post(route(`organization-design.studies.${action}`, study.id), {}, { preserveScroll: true });
    }
    async function returnToDraft() {
        const { confirmed, reason } = await confirm({ title: tt('studies.return'), description: tt('studies.returnPrompt'), variant: 'warning', requireReason: true });
        if (confirmed) router.post(route('organization-design.studies.return', study.id), { reason }, { preserveScroll: true });
    }
    async function removeProposal(node: ProposalNode) {
        const { confirmed } = await confirm({ title: tt('studies.removeProposal'), description: tt('studies.removeProposalConfirm'), variant: 'danger' });
        if (confirmed) router.delete(route('organization-design.proposals.destroy', node.id), { preserveScroll: true });
    }

    const detail = (label: string, value: React.ReactNode) => (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{label}</dt>
            <dd className="mt-0.5 break-words text-sm text-[color:var(--app-foreground)]">{value === null || value === undefined || value === '' ? '—' : value}</dd>
        </div>
    );

    const renderProposal = (node: ProposalNode, depth: number) => (
        <li key={node.id}>
            <div className="rounded-[var(--radius-card)] border border-[color:var(--app-border)] p-3" style={{ marginLeft: `${depth * 16}px` }}>
                <div className="flex flex-wrap items-start gap-2">
                    <span className={badgeClassName('neutral')}>{tt(`studies.proposalTypes.${node.proposal_type}`)}</span>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-[color:var(--app-foreground)]">{nameOf(node, locale)}</p>
                        <p className="text-xs text-[color:var(--app-muted-foreground)]">
                            {node.existing_unit && <>{tt('fields.existingUnit')}: {node.existing_unit.code} · {nameOf(node.existing_unit, locale)} · </>}
                            {node.parent_existing && <>{tt('studies.parentExisting')}: {node.parent_existing.code} · </>}
                            {node.unit_type_record && <>{nameOf(node.unit_type_record, locale)} · </>}
                            {node.change_request && <>{tt('studies.changeRequest')}: <Link href={route('organizational-change-requests.show', node.change_request.id)} className={actionLinkClassName({ size: 'xs' })}>{node.change_request.request_no}</Link> · </>}
                            {node.resulting_unit && <>{tt('studies.resultingUnit')}: {node.resulting_unit.code}</>}
                        </p>
                        {node.design_rationale && <p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">{node.design_rationale}</p>}
                        <div className="mt-1"><ResponsibilitiesSummary rows={node.responsibilities} /></div>
                    </div>
                    <span className={badgeClassName(PROPOSAL_TONES[node.status] ?? 'neutral')}>{tt(`studies.proposalStatuses.${node.status}`)}</span>
                    {can.update && (
                        <span className="flex flex-wrap gap-3">
                            <button type="button" className={actionLinkClassName()} onClick={() => setProposalEdit({ record: node, parentId: node.parent_proposed_unit_id })}>{t('common.edit')}</button>
                            <button type="button" className={actionLinkClassName({ tone: 'neutral' })} onClick={() => setProposalEdit({ record: null, parentId: node.id })}>{tt('studies.addChildProposal')}</button>
                            <button type="button" className={actionLinkClassName({ tone: 'neutral' })} onClick={() => setRespEdit(node)}>{tt('responsibilities.edit')}</button>
                            <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => removeProposal(node)}>{t('common.remove')}</button>
                        </span>
                    )}
                </div>
            </div>
            {node.children.length > 0 && <ul className="mt-2 space-y-2">{node.children.map((c) => renderProposal(c, depth + 1))}</ul>}
        </li>
    );

    return (
        <AuthenticatedLayout header={
            <PageHeader
                backHref={route('organization-design.studies.index')}
                title={`${study.reference_number} · ${titleOf(study, locale)}`}
                description={`${nameOf(study.organization, locale)} · ${tt(`studies.types.${study.study_type}`)}`}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        {can.update && <Button variant="outline" onClick={() => setEditing(true)}>{tt('studies.edit')}</Button>}
                        {can.cancel && <Button variant="destructiveOutline" onClick={() => act('cancel', 'danger')}>{tt('studies.cancel')}</Button>}
                        {can.submit && <Button variant="primary" onClick={() => act('submit')}>{tt('studies.submit')}</Button>}
                        {can.review && <Button variant="outline" onClick={returnToDraft}>{tt('studies.return')}</Button>}
                        {can.approve && <Button variant="primary" onClick={() => act('approve')}>{tt('studies.approve')}</Button>}
                        {can.generate && <Button variant="primary" onClick={() => act('generate')}>{tt('studies.generate')}</Button>}
                        {can.generate && <Button variant="outline" onClick={() => router.post(route('organization-design.studies.adopt', study.id), {}, { preserveScroll: true })}>{tt('studies.adopt')}</Button>}
                    </div>
                )}
            />
        }>
            <Head title={`${study.reference_number} · ${titleOf(study, locale)}`} />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <CardTitle>{tt('studies.details')}</CardTitle>
                            <StatusPill status={study.status} />
                        </CardHeader>
                        <dl className="grid gap-4 p-5 sm:grid-cols-2">
                            {detail(tt('fields.reason'), study.reason)}
                            {detail(tt('fields.scopeNotes'), study.scope_notes)}
                            {detail(tt('fields.startedAt'), study.started_at ? <LocalizedDateDisplay value={study.started_at} /> : null)}
                            {detail(tt('fields.targetEffectiveDate'), study.target_effective_date ? <LocalizedDateDisplay value={study.target_effective_date} /> : null)}
                            {detail(tt('fields.hierarchyVersion'), study.hierarchy_version?.version_name)}
                            {detail(tt('studies.preparedBy'), study.prepared_by)}
                            {detail(tt('studies.reviewedBy'), study.reviewed_by)}
                            {detail(tt('studies.reviewComment'), study.review_comment)}
                            {detail(tt('studies.approvedBy'), study.approved_by)}
                        </dl>
                        {study.status === 'under_review' && !can.approve && can.review && (
                            <p className="border-t border-[color:var(--app-border)] px-5 py-3 text-xs text-[color:var(--app-muted-foreground)]">{tt('studies.approveByOther')}</p>
                        )}
                        {can.generate && <p className="border-t border-[color:var(--app-border)] px-5 py-3 text-xs text-[color:var(--app-muted-foreground)]">{tt('studies.adoptHelp')}</p>}
                    </Card>

                    <Card>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <div><CardTitle>{tt('studies.proposals')}</CardTitle><p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{tt('studies.proposalsHelp')}</p></div>
                            {can.update && <Button size="sm" variant="primary" onClick={() => setProposalEdit({ record: null, parentId: null })}>{tt('studies.addProposal')}</Button>}
                        </CardHeader>
                        <div className="p-3">
                            {proposals.length === 0 ? <p className="px-2 py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{tt('studies.noProposals')}</p> : <ul className="space-y-2">{proposals.map((p) => renderProposal(p, 0))}</ul>}
                        </div>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardHeader><CardTitle>{tt('studies.history')}</CardTitle></CardHeader>
                        <ul className="divide-y divide-[color:var(--app-border)]">
                            {history.map((h, i) => (
                                <li key={i} className="px-5 py-3 text-sm">
                                    <p className="font-medium text-[color:var(--app-foreground)]">{h.from ? `${tt(`status.${h.from}`)} → ` : ''}{tt(`status.${h.to}`)}</p>
                                    <p className="text-xs text-[color:var(--app-muted-foreground)]">{h.actor ?? '—'} · <LocalizedDateDisplay value={h.at} withTime /></p>
                                    {h.reason && <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{h.reason}</p>}
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </div>

            {can.update && <StudyForm show={editing} onClose={() => setEditing(false)} title={tt('studies.edit')} initial={values} url={route('organization-design.studies.update', study.id)} method="put" studyTypes={studyTypes} hierarchyVersions={hierarchyVersions} />}
            {proposalEdit && <ProposalForm studyId={study.id} record={proposalEdit.record} parentProposedId={proposalEdit.parentId} units={units} unitTypes={unitTypes} proposals={allProposals} proposalTypes={proposalTypes} onClose={() => setProposalEdit(null)} />}
            {respEdit && <ResponsibilitiesForm subjectType="proposal" subjectId={respEdit.id} title={nameOf(respEdit, locale)} rows={respEdit.responsibilities} objects={objects} types={responsibilityTypes} onClose={() => setRespEdit(null)} />}
        </AuthenticatedLayout>
    );
}
