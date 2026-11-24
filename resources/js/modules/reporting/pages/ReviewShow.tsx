import RichTextViewer from '@/Components/forms/RichTextViewer';
import PageHeader from '@/Components/PageHeader';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TimelinessBadge, WorkflowBadge } from '@/modules/reporting/components/Badges';
import Discussion from '@/modules/reporting/components/Discussion';
import { ArtifactList, AttachmentList } from '@/modules/reporting/components/Files';
import { ActionErrors, Notice } from '@/modules/reporting/components/Notices';
import { PersonPicker } from '@/modules/reporting/components/Pickers';
import ReasonDialog from '@/modules/reporting/components/ReasonDialog';
import { badge, dangerBtn, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, useReporting } from '@/modules/reporting/lib/helpers';
import { StepList } from '@/modules/reporting/pages/ObligationShow';
import type { ArtifactRow, AttachmentRow, CommentRow, ObligationRow, PersonRef, ReviewStepRow, SubmissionRow } from '@/modules/reporting/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type JSX } from 'react';

interface Props {
    obligation: ObligationRow;
    submission: SubmissionRow;
    is_current: boolean;
    steps: ReviewStepRow[];
    assignments: { kind: string; reason: string; to_user_id: number; created_at: string }[];
    attachments: AttachmentRow[];
    artifacts: ArtifactRow[];
    previous_versions: SubmissionRow[];
    comments: CommentRow[];
    can: { decide: boolean; is_own_submission: boolean; reassign: boolean; comment_internal: boolean; follow_up: boolean };
}

type Decision = 'approve' | 'return' | 'reject';

/**
 * Reviewing one submitted version: the narrative (or the uploaded file), its
 * annexes, what was said before, and the decision for the open step.
 * Returning and rejecting need a reason, which the institution will read.
 */
export default function ReviewShow({ obligation, submission, is_current, steps, assignments, attachments, artifacts, previous_versions, comments, can }: Props): JSX.Element {
    const { rt, name, label, moment, am, subject } = useReporting();
    const [decision, setDecision] = useState<Decision | null>(null);
    const [reassigning, setReassigning] = useState(false);
    const [delegate, setDelegate] = useState<PersonRef | null>(null);
    const [busy, setBusy] = useState(false);
    const open = steps.find((step) => step.status === 'pending');
    const sections = submission.sections ?? [];
    const send = (url: string, data: Record<string, unknown>, done: () => void) => {
        setBusy(true);
        router.post(url, data as never, { preserveScroll: true, onFinish: () => { setBusy(false); done(); } });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={`${subject(obligation)} · ${name(obligation.report_type)}`} description={`${label(obligation.period)} · ${rt('common.version', { n: submission.version_no })} · ${submission.reference_no}`} backHref={route('reporting.review.index')} />}>
            <Head title={rt('review.title')} />
            <ActionErrors />
            <div className="grid gap-5 pb-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="min-w-0 space-y-5">
                    {!is_current && <Notice tone="warning">{rt('review.notCurrent')}</Notice>}

                    <section className={cx(panelCls, 'p-4')} aria-labelledby="rs-receipt">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 id="rs-receipt" className={sectionTitleCls}>{rt('review.receipt')}</h2>
                            <div className="flex flex-wrap gap-1.5">
                                <WorkflowBadge status={submission.status} />
                                <TimelinessBadge value={obligation.timeliness} />
                                <span className={badge('neutral')}>{rt(`prepare.mode.${submission.mode}`)}</span>
                                {submission.is_nil && <span className={badge('warning')}>{rt('show.nil')}</span>}
                            </div>
                        </div>
                        <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            <div><dt className={labelCls}>{rt('review.col.submitted')}</dt><dd>{moment(submission.submitted_at)} · {submission.submitted_by_name}</dd></div>
                            <div><dt className={labelCls}>{rt('register.col.effectiveDeadline')}</dt><dd>{moment(obligation.effective_due_at)}</dd></div>
                            <div><dt className={labelCls}>{rt('register.col.firstSubmission')}</dt><dd>{moment(obligation.first_submitted_at)}</dd></div>
                            <div><dt className={labelCls}>{rt('register.col.reviewDeadline')}</dt><dd>{obligation.review_due_at ? moment(obligation.review_due_at) : '—'}{obligation.review_overdue && <span className={cx(badge('danger'), 'ms-1')}>{rt('register.reviewOverdue')}</span>}</dd></div>
                        </dl>
                        {submission.late_reason && <p className="mt-2 text-sm"><span className="font-medium">{rt('prepare.lateReason')}:</span> {submission.late_reason}</p>}
                    </section>

                    {submission.is_nil && (
                        <section className={cx(panelCls, 'p-4')}>
                            <h2 className={sectionTitleCls}>{rt('show.nil')}</h2>
                            <p className={cx(mutedCls, 'text-xs')}>{rt('review.nilHelp')}</p>
                            <p className="mt-2 whitespace-pre-line text-sm">{submission.nil_reason}</p>
                        </section>
                    )}

                    {submission.mode === 'upload' && !submission.is_nil && (
                        <section className={cx(panelCls, 'space-y-3 p-4')} aria-labelledby="rs-upload">
                            <h2 id="rs-upload" className={sectionTitleCls}>{rt('review.uploadedReport')}</h2>
                            <Notice tone="warning">{rt('review.verifyCompleteness')}</Notice>
                            <AttachmentList files={attachments.filter((f) => f.kind === 'report_document')} />
                            <div>
                                <h3 className={labelCls}>{rt('prepare.summary')}</h3>
                                <p className="whitespace-pre-line text-sm">{submission.summary}</p>
                            </div>
                        </section>
                    )}

                    {sections.map((section, index) => (
                        <section key={section.key} className={cx(panelCls, 'p-4')} aria-labelledby={`rs-${section.key}`}>
                            <h2 id={`rs-${section.key}`} className={sectionTitleCls}>{index + 1}. {am ? section.title_am : section.title_en}</h2>
                            <RichTextViewer html={section.html} empty={<p className={cx(mutedCls, 'mt-2')}>{rt('review.sectionEmpty')}</p>} className="mt-2 text-sm" />
                            {comments.filter((c) => c.section_key === section.key).length > 0 && (
                                <p className={cx(mutedCls, 'mt-2 text-xs')}>{rt('review.sectionComments', { count: comments.filter((c) => c.section_key === section.key).length })}</p>
                            )}
                        </section>
                    ))}

                    <section className={cx(panelCls, 'p-4')} aria-labelledby="rs-files">
                        <h2 id="rs-files" className={cx(sectionTitleCls, 'mb-2')}>{rt('files.attachedTitle')}</h2>
                        <AttachmentList files={attachments.filter((f) => f.kind !== 'report_document')} />
                        {artifacts.length > 0 && (
                            <div className="mt-3 border-t border-(--app-border) pt-3">
                                <h3 className={labelCls}>{rt('files.generatedTitle')}</h3>
                                <ArtifactList artifacts={artifacts} reloadOnly={['artifacts']} />
                            </div>
                        )}
                    </section>

                    <section className={cx(panelCls, 'p-4')} aria-labelledby="rs-discussion">
                        <h2 id="rs-discussion" className={cx(sectionTitleCls, 'mb-3')}>{rt('discussion.title')}</h2>
                        <Discussion obligationId={obligation.id} comments={comments} sections={sections} canComment canInternal={can.comment_internal} />
                    </section>
                </div>

                <aside className="space-y-5 lg:sticky lg:top-4 lg:self-start">
                    <section className={cx(panelCls, 'p-4')} aria-labelledby="rs-steps">
                        <h2 id="rs-steps" className={cx(sectionTitleCls, 'mb-2')}>{rt('review.steps')}</h2>
                        <StepList steps={steps} />
                        {assignments.length > 0 && (
                            <ul className={cx(mutedCls, 'mt-2 space-y-1 border-t border-(--app-border) pt-2 text-xs')}>
                                {assignments.map((a) => <li key={a.created_at}>{rt(`review.assignment.${a.kind}`)} · {moment(a.created_at)} · {a.reason}</li>)}
                            </ul>
                        )}

                        {open && is_current && (
                            <div className="mt-4 space-y-2 border-t border-(--app-border) pt-3">
                                <h3 className="text-sm font-semibold">{rt('review.decideStep', { step: am ? open.name_am : open.name_en })}</h3>
                                {can.decide ? (
                                    <>
                                        <button type="button" className={cx(primaryBtn, 'w-full justify-center')} onClick={() => setDecision('approve')}>{rt('review.action.approve')}</button>
                                        <button type="button" className={cx(secondaryBtn, 'w-full justify-center')} onClick={() => setDecision('return')}>{rt('review.action.return')}</button>
                                        <button type="button" className={cx(dangerBtn, 'w-full justify-center')} onClick={() => setDecision('reject')}>{rt('review.action.reject')}</button>
                                    </>
                                ) : <p className={cx(mutedCls, 'text-xs')}>{rt(can.is_own_submission ? 'review.ownSubmission' : 'review.cannotDecide')}</p>}
                                {can.reassign && <button type="button" className={cx(secondaryBtn, 'w-full justify-center')} onClick={() => setReassigning(true)}>{rt('review.reassign')}</button>}
                            </div>
                        )}
                    </section>

                    {previous_versions.length > 0 && (
                        <section className={cx(panelCls, 'p-4')} aria-labelledby="rs-previous">
                            <h2 id="rs-previous" className={cx(sectionTitleCls, 'mb-2')}>{rt('review.previousVersions')}</h2>
                            <ul className="space-y-1.5 text-sm">
                                {previous_versions.map((version) => (
                                    <li key={version.id}>
                                        <Link href={route('reporting.review.show', version.id)} className="text-primary hover:underline">{rt('common.version', { n: version.version_no })} · {version.reference_no}</Link>
                                        <span className={cx(mutedCls, 'block text-xs')}>{moment(version.submitted_at)} · {rt(`workflow.${version.status}`)}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <Link href={route('reporting.obligations.show', obligation.id)} className={cx(secondaryBtn, 'w-full justify-center')}>{rt('prepare.reportPage')}</Link>
                </aside>
            </div>

            <ReasonDialog
                open={decision !== null} busy={busy} optional={decision === 'approve'} destructive={decision === 'reject'}
                title={decision ? rt(`review.action.${decision}`) : ''} description={decision ? rt(`review.confirm.${decision}`) : undefined}
                confirmLabel={decision ? rt(`review.action.${decision}`) : ''} reasonLabel={decision === 'approve' ? rt('review.remark') : rt('review.reasonForInstitution')}
                onClose={() => setDecision(null)}
                onConfirm={(reason) => open && decision && send(route('reporting.review.decide', submission.id), { decision, reason: reason || null, step_no: open.step_no }, () => setDecision(null))}
            />

            <ReasonDialog open={reassigning} busy={busy} title={rt('review.reassign')} description={rt('review.reassignHelp')} confirmLabel={rt('review.reassign')}
                onClose={() => setReassigning(false)}
                onConfirm={(reason) => open && delegate && send(route('reporting.review.reassign', open.id), { to_user_id: delegate.id, reason, kind: 'delegation' }, () => setReassigning(false))}>
                <PersonPicker label={rt('review.newReviewer')} value={delegate} onChange={setDelegate} />
            </ReasonDialog>
        </AuthenticatedLayout>
    );
}
