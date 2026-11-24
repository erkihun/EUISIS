import RichTextViewer from '@/Components/forms/RichTextViewer';
import PageHeader from '@/Components/PageHeader';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TimelinessBadge, WorkflowBadge } from '@/modules/reporting/components/Badges';
import Discussion from '@/modules/reporting/components/Discussion';
import { ArtifactList, AttachmentList } from '@/modules/reporting/components/Files';
import FollowUpPanel from '@/modules/reporting/components/FollowUpPanel';
import { ActionErrors, Notice } from '@/modules/reporting/components/Notices';
import { PersonPicker } from '@/modules/reporting/components/Pickers';
import ReasonDialog from '@/modules/reporting/components/ReasonDialog';
import { badge, dangerBtn, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, toLocalInput, useReporting } from '@/modules/reporting/lib/helpers';
import type { CommentRow, ExtensionRow, FollowUpRow, ObligationRow, PersonRef, ReviewStepRow, SubmissionRow } from '@/modules/reporting/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type JSX, type ReactNode } from 'react';

interface Props {
    obligation: ObligationRow;
    content_visible: boolean;
    submissions: SubmissionRow[];
    comments: CommentRow[];
    extensions: ExtensionRow[];
    applicability_decisions: { applicability: string; reason: string; decided_by_name: string; effective_at: string }[];
    follow_ups: FollowUpRow[];
    history: { id: string; event: string; actor_name: string | null; data: Record<string, unknown> | null; created_at: string }[];
    can: Record<'prepare' | 'request_extension' | 'decide_extension' | 'set_applicability' | 'review' | 'reopen' | 'reassign' | 'follow_up' | 'comment_internal' | 'comment', boolean>;
}

type Dialog = null | { kind: 'extension' } | { kind: 'decide'; extension: ExtensionRow; approve: boolean } | { kind: 'applicability'; to: string } | { kind: 'reopen' } | { kind: 'reassign' };

function Fact({ term, children }: { term: string; children: ReactNode }): JSX.Element {
    return (
        <div>
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{term}</dt>
            <dd className="text-sm">{children}</dd>
        </div>
    );
}

/** The review steps of one version, in order, with who decided what and when. */
export function StepList({ steps }: { steps: ReviewStepRow[] }): JSX.Element {
    const { rt, moment, am, subject } = useReporting();

    return (
        <ol className="space-y-1.5">
            {steps.map((step) => (
                <li key={step.id} className="flex flex-wrap items-baseline gap-2 text-sm">
                    <span className="font-medium">{step.step_no}. {am ? step.name_am : step.name_en}</span>
                    <span className={badge(step.decision === 'approve' ? 'success' : step.decision ? 'warning' : step.status === 'pending' ? 'info' : 'neutral')}>
                        {step.decision ? rt(`review.decision.${step.decision}`) : rt(`review.step.${step.status}`)}
                    </span>
                    {step.decided_by_name && <span className={cx(mutedCls, 'text-xs')}>{step.decided_by_name} · {moment(step.decided_at)}</span>}
                    {step.status === 'pending' && step.due_at && <span className={cx(mutedCls, 'text-xs')}>{rt('review.dueBy')}: {moment(step.due_at)}</span>}
                    {step.reason && <p className="w-full whitespace-pre-line ps-4 text-sm">{step.reason}</p>}
                </li>
            ))}
        </ol>
    );
}

/**
 * One report: what is owed and by when, every version with its receipt, the
 * review, comments, deadline decisions, follow-up actions and the history.
 * Earlier versions are never replaced; a returned version stays on the page.
 */
export default function ObligationShow({ obligation, content_visible, submissions, comments, extensions, applicability_decisions, follow_ups, history, can }: Props): JSX.Element {
    const { rt, name, subject, label, moment, am } = useReporting();
    const [dialog, setDialog] = useState<Dialog>(null);
    const [busy, setBusy] = useState(false);
    const [requested, setRequested] = useState(toLocalInput(obligation.effective_due_at));
    const [approved, setApproved] = useState('');
    const [preparer, setPreparer] = useState<PersonRef | null>(null);
    const [accountable, setAccountable] = useState<PersonRef | null>(null);
    const post = (url: string, data: Record<string, unknown>) => {
        setBusy(true);
        router.post(url, data as never, { preserveScroll: true, onFinish: () => setBusy(false), onSuccess: () => setDialog(null) });
    };
    const required = obligation.applicability === 'required';
    const latest = submissions[0];
    const sections = latest?.sections ?? [];

    return (
        <AuthenticatedLayout header={<PageHeader title={`${name(obligation.report_type)} · ${label(obligation.period)}`} description={subject(obligation)} backHref={route('reporting.register')}
            actions={(
                <span className="flex flex-wrap gap-2">
                    {can.prepare && <Link href={route('reporting.prepare.edit', obligation.id)} className={primaryBtn}>{rt(obligation.workflow === 'returned' ? 'assignments.revise' : obligation.workflow === 'not_started' ? 'assignments.start' : 'assignments.continue')}</Link>}
                    {can.review && latest && <Link href={route('reporting.review.show', latest.id)} className={primaryBtn}>{rt('review.open')}</Link>}
                </span>
            )} />}>
            <Head title={name(obligation.report_type)} />
            <ActionErrors />
            <div className="space-y-5 pb-4">
                {!required && <Notice tone="warning">{rt(`show.notice.${obligation.applicability}`)}</Notice>}

                {/* What is owed */}
                <section className={cx(panelCls, 'p-4')} aria-labelledby="ob-facts">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="ob-facts" className={sectionTitleCls}>{rt('show.obligation')}</h2>
                        <div className="flex flex-wrap items-center gap-2">
                            <WorkflowBadge status={obligation.workflow} />
                            <TimelinessBadge value={obligation.timeliness} applicability={obligation.applicability} />
                            {obligation.review_overdue && <span className={badge('danger')}>{rt('register.reviewOverdue')}</span>}
                        </div>
                    </div>
                    <dl className="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Fact term={rt('show.institution')}>
                            {subject(obligation)} <span className={cx(mutedCls, 'text-xs')}>({obligation.organization.code})</span>
                            {obligation.hierarchy && obligation.hierarchy.length > 0 && <span className={cx(mutedCls, 'block text-xs')}>{rt('show.under')}: {obligation.hierarchy.map((o) => name(o)).join(' › ')}</span>}
                        </Fact>
                        <Fact term={rt('show.receiving')}>{name(obligation.receiving)}</Fact>
                        <Fact term={rt('show.responsible')}>
                            {rt('show.accountable')}: {obligation.accountable_name ?? '—'}<br />{rt('show.preparer')}: {obligation.preparer_name ?? '—'}
                        </Fact>
                        <Fact term={rt('register.col.originalDeadline')}>{moment(obligation.original_due_at)}</Fact>
                        <Fact term={rt('register.col.effectiveDeadline')}>
                            {moment(obligation.effective_due_at)}
                            {obligation.deadline_extended && <span className={cx(badge('info'), 'ms-1')}>{rt('register.extended')}</span>}
                        </Fact>
                        <Fact term={rt('register.col.firstSubmission')}>
                            {obligation.first_submitted_at ? moment(obligation.first_submitted_at) : rt('register.notSubmitted')}
                            {obligation.deadline_extended && obligation.timeliness_original && obligation.timeliness_original !== obligation.timeliness && (
                                <span className={cx(mutedCls, 'block text-xs')}>{rt('register.byOriginal')}: {rt(`timeliness.${obligation.timeliness_original}`)}</span>
                            )}
                        </Fact>
                        {obligation.revision_due_at && <Fact term={rt('assignments.revisionDue')}>{moment(obligation.revision_due_at)}</Fact>}
                        {obligation.review_due_at && <Fact term={rt('register.col.reviewDeadline')}>{moment(obligation.review_due_at)}</Fact>}
                    </dl>
                    <div className="mt-4 flex flex-wrap gap-2 border-t border-(--app-border) pt-3">
                        {can.request_extension && <button type="button" className={secondaryBtn} onClick={() => setDialog({ kind: 'extension' })}>{rt('show.requestExtension')}</button>}
                        {can.reassign && <button type="button" className={secondaryBtn} onClick={() => setDialog({ kind: 'reassign' })}>{rt('show.reassign')}</button>}
                        {can.set_applicability && required && <button type="button" className={secondaryBtn} onClick={() => setDialog({ kind: 'applicability', to: 'exempted' })}>{rt('show.exempt')}</button>}
                        {can.set_applicability && required && <button type="button" className={dangerBtn} onClick={() => setDialog({ kind: 'applicability', to: 'cancelled' })}>{rt('show.cancel')}</button>}
                        {can.set_applicability && !required && <button type="button" className={secondaryBtn} onClick={() => setDialog({ kind: 'applicability', to: 'required' })}>{rt('show.reinstate')}</button>}
                        {can.reopen && <button type="button" className={secondaryBtn} onClick={() => setDialog({ kind: 'reopen' })}>{rt('show.reopen')}</button>}
                    </div>
                </section>

                {/* Versions and receipts */}
                <section className={cx(panelCls, 'p-4')} aria-labelledby="ob-versions">
                    <h2 id="ob-versions" className={sectionTitleCls}>{rt('show.versions')}</h2>
                    {submissions.length === 0 ? <p className={cx(mutedCls, 'mt-2')}>{rt('show.noSubmission')}</p> : (
                        <ol className="mt-3 space-y-4">
                            {submissions.map((submission, index) => (
                                <li key={submission.id} className="rounded-control border border-(--app-border) p-3">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <h3 className="text-sm font-semibold">{rt('common.version', { n: submission.version_no })} · <span className="font-mono">{submission.reference_no}</span></h3>
                                            <p className={cx(mutedCls, 'text-xs')}>{rt('show.submittedBy', { name: submission.submitted_by_name })} · {moment(submission.submitted_at)}</p>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <WorkflowBadge status={submission.status} />
                                            <span className={badge('neutral')}>{rt(`prepare.mode.${submission.mode}`)}</span>
                                            {submission.is_nil && <span className={badge('warning')}>{rt('show.nil')}</span>}
                                            {submission.is_late && <span className={badge('warning')}>{rt('timeliness.late')}</span>}
                                        </div>
                                    </div>
                                    {submission.late_reason && <p className="mt-2 text-sm"><span className="font-medium">{rt('prepare.lateReason')}:</span> {submission.late_reason}</p>}
                                    <p className={cx(mutedCls, 'mt-1 break-all text-xs')} title={rt('show.hashHelp')}>{rt('show.hash')}: <span className="font-mono">{submission.content_hash.slice(0, 16)}…</span></p>

                                    {submission.steps && submission.steps.length > 0 && <div className="mt-3 border-t border-(--app-border) pt-3"><StepList steps={submission.steps} /></div>}

                                    {content_visible ? (
                                        <>
                                            {(submission.artifacts?.length ?? 0) > 0 && (
                                                <div className="mt-3 border-t border-(--app-border) pt-3">
                                                    <h4 className={labelCls}>{rt('files.generatedTitle')}</h4>
                                                    <ArtifactList artifacts={submission.artifacts ?? []} reloadOnly={['submissions']} />
                                                </div>
                                            )}
                                            {(submission.attachments?.length ?? 0) > 0 && (
                                                <div className="mt-3 border-t border-(--app-border) pt-3">
                                                    <h4 className={labelCls}>{rt('files.attachedTitle')}</h4>
                                                    <AttachmentList files={submission.attachments ?? []} />
                                                </div>
                                            )}
                                            {index === 0 && (
                                                <details className="mt-3 border-t border-(--app-border) pt-3">
                                                    <summary className="cursor-pointer text-sm font-medium text-primary">{rt('show.readNarrative')}</summary>
                                                    <div className="mt-3 space-y-4">
                                                        {submission.is_nil && <p className="text-sm"><span className="font-medium">{rt('prepare.nilReason')}:</span> {submission.nil_reason}</p>}
                                                        {submission.summary && <p className="whitespace-pre-line text-sm"><span className="font-medium">{rt('prepare.summary')}:</span> {submission.summary}</p>}
                                                        {(submission.sections ?? []).map((section, number) => (
                                                            <div key={section.key}>
                                                                <h4 className="text-sm font-semibold">{number + 1}. {am ? section.title_am : section.title_en}</h4>
                                                                <RichTextViewer html={section.html} empty={<p className={mutedCls}>—</p>} className="mt-1 text-sm" />
                                                            </div>
                                                        ))}
                                                    </div>
                                                </details>
                                            )}
                                        </>
                                    ) : <p className={cx(mutedCls, 'mt-3 text-xs')}>{rt('show.confidential')}</p>}
                                </li>
                            ))}
                        </ol>
                    )}
                </section>

                {/* Deadline decisions */}
                {(extensions.length > 0 || applicability_decisions.length > 0) && (
                    <section className={cx(panelCls, 'p-4')} aria-labelledby="ob-deadlines">
                        <h2 id="ob-deadlines" className={sectionTitleCls}>{rt('show.deadlineDecisions')}</h2>
                        <ul className="mt-2 divide-y divide-(--app-border)">
                            {extensions.map((extension) => (
                                <li key={extension.id} className="py-2.5 text-sm">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{rt('show.extensionTo', { date: moment(extension.requested_due_at) })}</span>
                                        <span className={badge(extension.status === 'approved' ? 'success' : extension.status === 'rejected' ? 'danger' : 'warning')}>{rt(`show.extensionStatus.${extension.status}`)}</span>
                                        {extension.is_retrospective && <span className={badge('warning')} title={rt('show.retrospectiveHelp')}>{rt('show.retrospective')}</span>}
                                    </div>
                                    <p className={cx(mutedCls, 'text-xs')}>{extension.requested_by_name} · {moment(extension.requested_at)}</p>
                                    <p className="whitespace-pre-line">{extension.reason}</p>
                                    {extension.decided_at && (
                                        <p className="mt-1 text-sm">
                                            <span className={cx(mutedCls, 'text-xs')}>{extension.decided_by_name} · {moment(extension.decided_at)}</span><br />
                                            {extension.decision_reason}
                                            {extension.approved_due_at && <span className={cx(mutedCls, 'block text-xs')}>{rt('show.deadlineMoved', { from: moment(extension.previous_due_at), to: moment(extension.approved_due_at) })}</span>}
                                        </p>
                                    )}
                                    {extension.status === 'pending' && can.decide_extension && (
                                        <div className="mt-2 flex gap-2">
                                            <button type="button" className={primaryBtn} onClick={() => { setApproved(toLocalInput(extension.requested_due_at)); setDialog({ kind: 'decide', extension, approve: true }); }}>{rt('show.grant')}</button>
                                            <button type="button" className={secondaryBtn} onClick={() => setDialog({ kind: 'decide', extension, approve: false })}>{rt('show.refuse')}</button>
                                        </div>
                                    )}
                                </li>
                            ))}
                            {applicability_decisions.map((decision) => (
                                <li key={decision.effective_at} className="py-2.5 text-sm">
                                    <span className={badge('neutral')}>{rt(`applicability.${decision.applicability}`)}</span>
                                    <span className={cx(mutedCls, 'ms-2 text-xs')}>{decision.decided_by_name} · {moment(decision.effective_at)}</span>
                                    <p className="whitespace-pre-line">{decision.reason}</p>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <div className="grid gap-5 lg:grid-cols-2">
                    <section className={cx(panelCls, 'p-4')} aria-labelledby="ob-comments">
                        <h2 id="ob-comments" className={cx(sectionTitleCls, 'mb-3')}>{rt('discussion.title')}</h2>
                        <Discussion obligationId={obligation.id} comments={comments} sections={sections} canComment={can.comment} canInternal={can.comment_internal} />
                    </section>
                    <section className={cx(panelCls, 'p-4')} aria-labelledby="ob-followups">
                        <h2 id="ob-followups" className={cx(sectionTitleCls, 'mb-3')}>{rt('followUps.title')}</h2>
                        <FollowUpPanel obligationId={obligation.id} actions={follow_ups} canCreate={can.follow_up} canUpdate={can.comment} />
                    </section>
                </div>

                <section className={cx(panelCls, 'p-4')} aria-labelledby="ob-history">
                    <h2 id="ob-history" className={sectionTitleCls}>{rt('show.history')}</h2>
                    {history.length === 0 ? <p className={cx(mutedCls, 'mt-2')}>{rt('show.noHistory')}</p> : (
                        <ol className="mt-2 space-y-1.5">
                            {history.map((entry) => (
                                <li key={entry.id} className="text-sm">
                                    <span className={cx(mutedCls, 'text-xs')}>{moment(entry.created_at)}</span>{' '}
                                    <span className="font-medium">{rt(`history.${entry.event}`)}</span>
                                    {entry.actor_name && <span className={mutedCls}> · {entry.actor_name}</span>}
                                    {typeof entry.data?.reason === 'string' && <span className="block ps-4 text-sm">{entry.data.reason}</span>}
                                </li>
                            ))}
                        </ol>
                    )}
                </section>
            </div>

            <ReasonDialog open={dialog?.kind === 'extension'} busy={busy} title={rt('show.requestExtension')} description={rt('show.requestExtensionHelp')} confirmLabel={rt('show.sendRequest')}
                onClose={() => setDialog(null)} onConfirm={(reason) => post(route('reporting.extensions.store', obligation.id), { requested_due_at: requested, reason })}>
                <div>
                    <label htmlFor="ob-ext-date" className={labelCls}>{rt('show.newDeadline')}</label>
                    <input id="ob-ext-date" type="datetime-local" className={inputCls} value={requested} min={toLocalInput(obligation.effective_due_at)} onChange={(e) => setRequested(e.target.value)} required />
                    <p className={cx(mutedCls, 'mt-1 text-xs')}>{rt('common.localTime')}</p>
                </div>
            </ReasonDialog>

            <ReasonDialog open={dialog?.kind === 'decide'} busy={busy} destructive={dialog?.kind === 'decide' && !dialog.approve}
                title={rt(dialog?.kind === 'decide' && dialog.approve ? 'show.grant' : 'show.refuse')} confirmLabel={rt(dialog?.kind === 'decide' && dialog.approve ? 'show.grant' : 'show.refuse')}
                description={rt('show.decideHelp')} onClose={() => setDialog(null)}
                onConfirm={(reason) => dialog?.kind === 'decide' && post(route('reporting.extensions.decide', dialog.extension.id), { approve: dialog.approve, reason, approved_due_at: dialog.approve ? approved : null })}>
                {dialog?.kind === 'decide' && dialog.approve && (
                    <div>
                        <label htmlFor="ob-ext-approved" className={labelCls}>{rt('show.newDeadline')}</label>
                        <input id="ob-ext-approved" type="datetime-local" className={inputCls} value={approved} onChange={(e) => setApproved(e.target.value)} required />
                        <p className={cx(mutedCls, 'mt-1 text-xs')}>{rt('common.localTime')}</p>
                    </div>
                )}
            </ReasonDialog>

            <ReasonDialog open={dialog?.kind === 'applicability'} busy={busy} destructive={dialog?.kind === 'applicability' && dialog.to === 'cancelled'}
                title={dialog?.kind === 'applicability' ? rt(`show.applicabilityTitle.${dialog.to}`) : ''} description={rt('show.applicabilityHelp')} confirmLabel={rt('common.confirm')}
                onClose={() => setDialog(null)} onConfirm={(reason) => dialog?.kind === 'applicability' && post(route('reporting.obligations.applicability', obligation.id), { applicability: dialog.to, reason })} />

            <ReasonDialog open={dialog?.kind === 'reopen'} busy={busy} title={rt('show.reopen')} description={rt('show.reopenHelp')} confirmLabel={rt('show.reopen')}
                onClose={() => setDialog(null)} onConfirm={(reason) => post(route('reporting.obligations.reopen', obligation.id), { reason })} />

            <ReasonDialog open={dialog?.kind === 'reassign'} busy={busy} title={rt('show.reassign')} description={rt('show.reassignHelp')} confirmLabel={rt('common.save')}
                onClose={() => setDialog(null)} onConfirm={(reason) => post(route('reporting.obligations.responsible', obligation.id), { preparer_user_id: preparer?.id ?? null, accountable_user_id: accountable?.id ?? null, reason })}>
                <div className="space-y-3">
                    <PersonPicker label={rt('show.accountable')} value={accountable} onChange={setAccountable} />
                    <PersonPicker label={rt('show.preparer')} value={preparer} onChange={setPreparer} />
                </div>
            </ReasonDialog>
        </AuthenticatedLayout>
    );
}
