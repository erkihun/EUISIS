import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import Modal from '@/Components/Modal';
import BaselineForm, { type BaselineFormValues, type HierarchyVersionOption, type LegalSourceOption } from '@/Components/organizationDesign/BaselineForm';
import ValidationReport, { type Report } from '@/Components/organizationDesign/ValidationReport';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, FieldError, FormField, Input, Select, Textarea, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { BaselineSummary } from './Index';

type Step = { key: string; status: string; status_source: string; record_count: number; notes: string | null; workflow: boolean; entry_url: string | null };

type Props = {
    baseline: BaselineSummary & {
        description: string | null;
        hierarchy_version: { id: string; name: string; status: string } | null;
        legal_source: LegalSourceOption | null;
        review_comment: string | null; approval_reference: string | null;
        reviewed_by: string | null; approved_by: string | null; published_by: string | null;
        submitted_at: string | null; approved_at: string | null; published_at: string | null;
        validation_summary: { errors: number; warnings: number; infos: number } | null; validated_at: string | null;
    };
    steps: Step[];
    validation: Report | null;
    snapshot: { taken_at: string; counts: Record<string, number>; sha256: string } | null;
    versions: { id: string; version_no: number; reference_number: string; status: string; effective_date: string | null }[];
    history: { from: string | null; to: string; actor: string | null; reason: string | null; at: string }[];
    hierarchyVersions: HierarchyVersionOption[];
    legalSources: LegalSourceOption[];
    origins: string[];
    stepStatuses: string[];
    can: { update: boolean; submit: boolean; return: boolean; approve: boolean; publish: boolean; revise: boolean; cancel: boolean; validate: boolean };
};

const STEP_TONES: Record<string, 'neutral' | 'success' | 'warning' | 'danger' | 'info'> = { complete: 'success', incomplete: 'neutral', needs_review: 'warning', error: 'danger' };

/**
 * The legacy capture wizard: a rail of the 22 steps on the left, the chosen
 * step on the right with what already exists for the organization, where to
 * add more, and a status a person may set by hand. Workflow actions sit in
 * the header; the server decides every one of them.
 */
export default function LegacyBaselineShow({ baseline, steps, validation, snapshot, versions, history, hierarchyVersions, legalSources, origins, stepStatuses, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const firstOpen = steps.find((s) => !s.workflow && s.status !== 'complete')?.key ?? steps[0]?.key;
    const [activeKey, setActiveKey] = useState<string>(firstOpen);
    const [editing, setEditing] = useState(false);
    const [returning, setReturning] = useState(false);
    const [approving, setApproving] = useState(false);
    const active = steps.find((s) => s.key === activeKey) ?? steps[0];
    const stepForm = useForm<{ status: string; notes: string }>({ status: active?.status ?? 'incomplete', notes: active?.notes ?? '' });
    const returnForm = useForm<{ reason: string }>({ reason: '' });
    const approveForm = useForm<{ approval_reference: string }>({ approval_reference: '' });

    function selectStep(step: Step) {
        setActiveKey(step.key);
        stepForm.setData({ status: step.status, notes: step.notes ?? '' });
        stepForm.clearErrors();
    }

    const values: BaselineFormValues = {
        organization_id: baseline.organization_id, title_en: baseline.title_en, title_am: baseline.title_am ?? '', description: baseline.description ?? '',
        origin: baseline.origin, effective_date: baseline.effective_date ?? '', hierarchy_version_id: baseline.hierarchy_version_id ?? '', legal_source_id: baseline.legal_source_id ?? '',
    };

    const post = (name: string, data: Record<string, string> = {}) => router.post(route(`organization-design.legacy-baselines.${name}`, baseline.id), data, { preserveScroll: true });

    async function submit() {
        const { confirmed } = await confirm({ title: tt('legacyBaselines.submit'), description: tt('legacyBaselines.submitConfirm'), variant: 'warning' });
        if (confirmed) post('submit');
    }
    async function publish() {
        const { confirmed } = await confirm({ title: tt('legacyBaselines.publish'), description: tt('legacyBaselines.publishConfirm'), variant: 'warning' });
        if (confirmed) post('publish');
    }
    async function revise() {
        const { confirmed } = await confirm({ title: tt('legacyBaselines.revise'), description: tt('legacyBaselines.reviseConfirm'), variant: 'warning' });
        if (confirmed) post('revisions.store');
    }
    async function cancel() {
        const { confirmed } = await confirm({ title: tt('legacyBaselines.cancel'), description: tt('legacyBaselines.cancelConfirm'), variant: 'danger' });
        if (confirmed) post('cancel');
    }

    const detail = (label: string, value: React.ReactNode) => (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 wrap-break-word text-sm text-(--app-foreground)">{value === null || value === undefined || value === '' ? '—' : value}</dd>
        </div>
    );

    const completed = steps.filter((s) => s.status === 'complete').length;

    return (
        <AuthenticatedLayout header={
            <PageHeader
                backHref={route('organization-design.legacy-baselines.index')}
                title={`${baseline.reference_number} · ${titleOf(baseline, locale)}`}
                description={`${nameOf(baseline.organization, locale)} · v${baseline.version_no} · ${completed}/${steps.length}`}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        {can.update && <Button variant="outline" onClick={() => setEditing(true)}>{tt('legacyBaselines.edit')}</Button>}
                        {can.validate && <Button variant="outline" onClick={() => post('validate')}>{tt('legacyBaselines.runValidation')}</Button>}
                        {can.cancel && <Button variant="destructiveOutline" onClick={cancel}>{tt('legacyBaselines.cancel')}</Button>}
                        {can.submit && <Button variant="primary" onClick={submit}>{tt('legacyBaselines.submit')}</Button>}
                        {can.return && <Button variant="outline" onClick={() => setReturning(true)}>{tt('legacyBaselines.return')}</Button>}
                        {can.approve && <Button variant="primary" onClick={() => setApproving(true)}>{tt('legacyBaselines.approve')}</Button>}
                        {can.publish && <Button variant="primary" onClick={publish}>{tt('legacyBaselines.publish')}</Button>}
                        {can.revise && <Button variant="outline" onClick={revise}>{tt('legacyBaselines.revise')}</Button>}
                    </div>
                )}
            />
        }>
            <Head title={`${baseline.reference_number} · ${titleOf(baseline, locale)}`} />

            <div className="space-y-4">
                <Card>
                    <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                        <CardTitle>{tt('legacyBaselines.details')}</CardTitle>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className={badgeClassName(baseline.validation_status === 'passed' ? 'success' : baseline.validation_status === 'failed' ? 'danger' : baseline.validation_status === 'warnings' ? 'warning' : 'neutral')}>{tt(`legacyBaselines.validationStatus.${baseline.validation_status}`)}</span>
                            <StatusPill status={baseline.status} />
                        </div>
                    </CardHeader>
                    <dl className="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                        {detail(tt('legacyBaselines.effectiveDate'), baseline.effective_date ? <LocalizedDateDisplay value={baseline.effective_date} /> : null)}
                        {detail(tt('fields.origin'), tt(`legacyBaselines.origin.${baseline.origin}`))}
                        {detail(tt('legacyBaselines.hierarchyVersion'), baseline.hierarchy_version ? `${baseline.hierarchy_version.name} · ${baseline.hierarchy_version.status}` : null)}
                        {detail(tt('legacyBaselines.legalSource'), baseline.legal_source ? <Link href={route('organization-design.legal-sources.show', baseline.legal_source.id)} className="hover:underline">{baseline.legal_source.code} · {locale === 'am' && baseline.legal_source.title_am ? baseline.legal_source.title_am : baseline.legal_source.title_en}</Link> : null)}
                        {detail(tt('legacyBaselines.preparedBy'), baseline.prepared_by)}
                        {detail(tt('legacyBaselines.reviewedBy'), baseline.reviewed_by)}
                        {detail(tt('legacyBaselines.approvedBy'), baseline.approved_by ? `${baseline.approved_by}${baseline.approval_reference ? ` · ${baseline.approval_reference}` : ''}` : null)}
                        {detail(tt('legacyBaselines.publishedBy'), baseline.published_by ? <>{baseline.published_by} · <LocalizedDateDisplay value={baseline.published_at} /></> : null)}
                        {baseline.review_comment && <div className="sm:col-span-2 lg:col-span-4">{detail(tt('legacyBaselines.reviewComment'), baseline.review_comment)}</div>}
                        {baseline.description && <div className="sm:col-span-2 lg:col-span-4">{detail(tt('fields.description'), baseline.description)}</div>}
                    </dl>
                </Card>

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle>{tt('legacyBaselines.steps')}</CardTitle>
                            <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('legacyBaselines.stepsHelp')}</p>
                        </CardHeader>
                        <ol className="divide-y divide-(--app-border)">
                            {steps.map((step, index) => (
                                <li key={step.key}>
                                    <button type="button" onClick={() => selectStep(step)} aria-current={step.key === activeKey ? 'step' : undefined}
                                        className={`flex w-full items-center gap-3 px-4 py-2.5 text-start text-sm transition-colors hover:bg-(--app-surface-muted) ${step.key === activeKey ? 'bg-(--app-surface-muted) font-semibold text-(--app-foreground)' : 'text-(--app-foreground)'}`}>
                                        <span className="w-6 shrink-0 text-xs tabular-nums text-(--app-muted-foreground)">{index + 1}</span>
                                        <span className="min-w-0 flex-1 truncate">{tt(`legacyBaselines.stepNames.${step.key}`)}</span>
                                        {!step.workflow && <span className="text-xs tabular-nums text-(--app-muted-foreground)">{step.record_count}</span>}
                                        <span className={badgeClassName(STEP_TONES[step.status] ?? 'neutral')}>{tt(`legacyBaselines.stepStatus.${step.status}`)}</span>
                                    </button>
                                </li>
                            ))}
                        </ol>
                    </Card>

                    <div className="space-y-4">
                        {active && (
                            <Card>
                                <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                                    <CardTitle>{tt(`legacyBaselines.stepNames.${active.key}`)}</CardTitle>
                                    <span className={badgeClassName(STEP_TONES[active.status] ?? 'neutral')}>{tt(`legacyBaselines.stepStatus.${active.status}`)} · {tt(`legacyBaselines.statusSource.${active.status_source}`)}</span>
                                </CardHeader>
                                <div className="space-y-4 p-5">
                                    <p className="text-sm text-(--app-muted-foreground)">{tt(`legacyBaselines.stepHints.${active.key}`)}</p>
                                    {!active.workflow && (
                                        <div className="flex flex-wrap items-center gap-3">
                                            <span className="text-2xl font-semibold tabular-nums text-(--app-foreground)">{active.record_count}</span>
                                            <span className="text-sm text-(--app-muted-foreground)">{tt('legacyBaselines.records')}</span>
                                            {active.entry_url && <a href={active.entry_url} className={actionLinkClassName()}>{tt('legacyBaselines.openEntry')}</a>}
                                        </div>
                                    )}
                                    {active.workflow && active.key === 'validation' && !validation && <p className="text-sm text-(--app-muted-foreground)">{tt('legacyBaselines.validationNotRun')}</p>}
                                    {active.notes && active.status_source === 'manual' && !can.update && <p className="text-sm text-(--app-foreground)">{active.notes}</p>}
                                    {!active.workflow && can.update && (
                                        <form onSubmit={(e) => { e.preventDefault(); stepForm.put(route('organization-design.legacy-baselines.steps.save', [baseline.id, active.key]), { preserveScroll: true }); }} className="grid gap-3 border-t border-(--app-border) pt-4 sm:grid-cols-[12rem_1fr_auto] sm:items-end">
                                            <FormField id="step-status" label={tt('legacyBaselines.markStep')} error={stepForm.errors.status}>
                                                {({ id }) => (
                                                    <Select id={id} value={stepForm.data.status} onChange={(e) => stepForm.setData('status', e.target.value)}>
                                                        {stepStatuses.map((s) => <option key={s} value={s}>{tt(`legacyBaselines.stepStatus.${s}`)}</option>)}
                                                    </Select>
                                                )}
                                            </FormField>
                                            <FormField id="step-notes" label={tt('legacyBaselines.stepNotes')} error={stepForm.errors.notes}>
                                                {({ id }) => <Input id={id} value={stepForm.data.notes} onChange={(e) => stepForm.setData('notes', e.target.value)} />}
                                            </FormField>
                                            <Button type="submit" variant="primary" loading={stepForm.processing}>{tt('actions.save')}</Button>
                                            <FieldError>{(stepForm.errors as Record<string, string | undefined>).step}</FieldError>
                                        </form>
                                    )}
                                </div>
                            </Card>
                        )}

                        {validation && <ValidationReport report={validation} compact />}

                        {snapshot && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>{tt('legacyBaselines.snapshot')}</CardTitle>
                                    <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('legacyBaselines.snapshotHelp')} · {tt('legacyBaselines.snapshotTaken')} <LocalizedDateDisplay value={snapshot.taken_at} /></p>
                                </CardHeader>
                                <dl className="grid grid-cols-2 gap-3 p-5 sm:grid-cols-4">
                                    {Object.entries(snapshot.counts).map(([key, value]) => (
                                        <div key={key}>
                                            <dt className="text-xs text-(--app-muted-foreground)">{tt(`legacyBaselines.snapshotCounts.${key}`)}</dt>
                                            <dd className="text-lg font-semibold tabular-nums text-(--app-foreground)">{value}</dd>
                                        </div>
                                    ))}
                                </dl>
                                <p className="border-t border-(--app-border) px-5 py-2 font-mono text-[11px] text-(--app-muted-foreground)">{snapshot.sha256}</p>
                            </Card>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader><CardTitle>{tt('legacyBaselines.revisions')}</CardTitle></CardHeader>
                        <ul className="divide-y divide-(--app-border)">
                            {versions.map((v) => (
                                <li key={v.id} className="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                                    <Link href={route('organization-design.legacy-baselines.show', v.id)} className={`hover:underline ${v.id === baseline.id ? 'font-semibold' : ''}`}>v{v.version_no} · {v.reference_number}</Link>
                                    <span className="flex items-center gap-2 text-(--app-muted-foreground)"><LocalizedDateDisplay value={v.effective_date} /><StatusPill status={v.status} /></span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle>{tt('legacyBaselines.history')}</CardTitle></CardHeader>
                        <ol className="divide-y divide-(--app-border)">
                            {history.length === 0 && <li className="px-5 py-4 text-sm text-(--app-muted-foreground)">—</li>}
                            {history.map((h, i) => (
                                <li key={i} className="px-5 py-3 text-sm">
                                    <span className="font-medium text-(--app-foreground)">{h.from ? `${tt(`status.${h.from}`)} → ` : ''}{tt(`status.${h.to}`)}</span>
                                    <span className="text-(--app-muted-foreground)"> · {h.actor ?? '—'} · <LocalizedDateDisplay value={h.at} /></span>
                                    {h.reason && <p className="text-xs text-(--app-muted-foreground)">{h.reason}</p>}
                                </li>
                            ))}
                        </ol>
                    </Card>
                </div>
            </div>

            {can.update && (
                <BaselineForm show={editing} onClose={() => setEditing(false)} title={tt('legacyBaselines.edit')} initial={values}
                    url={route('organization-design.legacy-baselines.update', baseline.id)} method="put" hierarchyVersions={hierarchyVersions} legalSources={legalSources} origins={origins} />
            )}

            <Modal show={returning} onClose={() => setReturning(false)} maxWidth="md">
                <form onSubmit={(e) => { e.preventDefault(); returnForm.post(route('organization-design.legacy-baselines.return', baseline.id), { preserveScroll: true, onSuccess: () => setReturning(false) }); }} className="p-6">
                    <h2 className="text-base font-semibold text-(--app-foreground)">{tt('legacyBaselines.return')}</h2>
                    <div className="mt-5">
                        <FormField id="return-reason" label={tt('legacyBaselines.returnReason')} required error={returnForm.errors.reason}>
                            {({ id }) => <Textarea id={id} value={returnForm.data.reason} onChange={(e) => returnForm.setData('reason', e.target.value)} />}
                        </FormField>
                    </div>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => setReturning(false)}>{tt('actions.cancel')}</Button>
                        <Button type="submit" variant="primary" loading={returnForm.processing}>{tt('legacyBaselines.return')}</Button>
                    </div>
                </form>
            </Modal>

            <Modal show={approving} onClose={() => setApproving(false)} maxWidth="md">
                <form onSubmit={(e) => { e.preventDefault(); approveForm.post(route('organization-design.legacy-baselines.approve', baseline.id), { preserveScroll: true, onSuccess: () => setApproving(false) }); }} className="p-6">
                    <h2 className="text-base font-semibold text-(--app-foreground)">{tt('legacyBaselines.approve')}</h2>
                    <p className="mt-1 text-sm text-(--app-muted-foreground)">{tt('legacyBaselines.approveHelp')}</p>
                    <div className="mt-5">
                        <FormField id="approval-reference" label={tt('legacyBaselines.approvalReference')} error={approveForm.errors.approval_reference}>
                            {({ id }) => <Input id={id} value={approveForm.data.approval_reference} onChange={(e) => approveForm.setData('approval_reference', e.target.value)} />}
                        </FormField>
                        <FieldError>{(approveForm.errors as Record<string, string | undefined>).status}</FieldError>
                    </div>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => setApproving(false)}>{tt('actions.cancel')}</Button>
                        <Button type="submit" variant="primary" loading={approveForm.processing}>{tt('legacyBaselines.approve')}</Button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
