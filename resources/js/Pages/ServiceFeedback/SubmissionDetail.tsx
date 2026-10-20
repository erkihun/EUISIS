import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import OrganizationFormDetails, { type OrganizationFormDetailsData } from '@/Components/ServiceFeedback/OrganizationFormDetails';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { FeedbackStatusBadge, statusLabelKey } from '@/Components/ServiceFeedback/feedbackUi';
import { ReferralsPanel, SubjectsPanel, type ReferralRow, type SubjectRow } from '@/Components/ServiceFeedback/ReferralPanels';
import { useLocale } from '@/hooks/useLocale';
import { AppDialog, Alert, Button, Card, FormField, Select, Textarea } from '@euisis/ui';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import type { TipType } from '@/types/publicSubmissions';

type Entry = {
    id: string; reference: string | null; status: string; message: string; subject: string | null;
    target_type: 'employee' | 'organization'; target_name: string | null; target_code?: string | null;
    created_at: string | null; submitted_at: string | null; employee: string | null; employee_number: string | null;
    organization: string | null; organization_unit: string | null; unit_selection_mode: 'SELECTED' | 'GENERAL' | null; position: string | null; category: string | null;
    client_name: string | null; client_contact: string | null; identity_hidden: boolean;
    client_email: string | null; submitter_organization: string | null;
    submitter_address: Partial<Record<'city' | 'sub_city' | 'woreda' | 'detail', string>> | null;
    tip_type: TipType | null; involved_parties: string | null; intake_channel: string | null;
    attachment_count: number; attachments_hidden: boolean;
    incident_date: string | null; incident_location: string | null;
    review_note: string | null;
    acknowledged_at: string | null; assigned_at: string | null; resolved_at: string | null; closed_at: string | null;
    attachments: { id: string; name: string; size: number; kind: 'photo' | 'video' | 'audio' | 'document' | null; scan_status: string }[];
};

const TIP_TYPE_LABEL: Record<TipType, string> = { CORRUPTION: 'serviceFeedback.tipTypeCorruption', IMPROPER_PRACTICE: 'serviceFeedback.tipTypeImproperPractice' };
const EVIDENCE_LABEL = { photo: 'serviceFeedback.evidencePhoto', video: 'serviceFeedback.evidenceVideo', audio: 'serviceFeedback.evidenceAudio', document: 'serviceFeedback.evidenceDocument' } as const;
type HistoryRow = { id: string; from_status: string | null; to_status: string; actor: string | null; reason: string | null; transitioned_at: string | null };
type AssignmentRow = { id: string; handler_type: 'user' | 'organization_unit' | 'role'; handler: string; assigned_by: string | null; via_rule: boolean; reason: string | null; assigned_at: string | null; ended_at: string | null };
type HandlerOptions = { users: { id: string; name: string }[]; units: { id: string; name: string; name_am: string | null }[]; roles: { id: string; name: string }[] };

type Props = {
    type: 'suggestion' | 'complaint';
    entry: Entry;
    history: HistoryRow[];
    assignments: AssignmentRow[];
    currentAssignment: AssignmentRow | null;
    allowedTransitions: string[];
    subjects: SubjectRow[];
    referrals: ReferralRow[];
    can: { assign: boolean; subjects: boolean; refer: boolean; decideReferral: boolean };
    handlerOptions: HandlerOptions | null;
    /** Organization form: the service received and the questionnaire answers; null otherwise. */
    organizationForm?: OrganizationFormDetailsData | null;
};

const HANDLER_TYPE_KEY: Record<AssignmentRow['handler_type'], string> = { user: 'handlerUser', organization_unit: 'handlerUnit', role: 'handlerRole' };

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return <div><dt className="text-(--app-muted-foreground)">{label}</dt><dd className="mt-0.5">{children}</dd></div>;
}

/**
 * One suggestion or complaint: what was said, who handles it, where it
 * stands and every step it took (brief §52–53). The transition form offers
 * only the statuses this user may set from here; assignment opens a dialog.
 */
export default function SubmissionDetail({ type, entry, history, assignments, currentAssignment, allowedTransitions, can, handlerOptions, subjects = [], referrals = [], organizationForm = null }: Props) {
    const { t, locale } = useLocale();
    const title = t(type === 'complaint' ? 'serviceFeedback.adminTypeComplaint' : 'serviceFeedback.adminTypeSuggestion');
    const [assigning, setAssigning] = useState(false);

    return <AuthenticatedLayout header={<PageHeader title={title} backHref={route(type === 'complaint' ? 'service-submissions.complaints' : 'service-submissions.suggestions')} />}>
        <Head title={entry.reference ? `${title} ${entry.reference}` : title} />
        <div className="space-y-5">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-sm text-(--app-muted-foreground)">{t(entry.target_type === 'organization' ? 'serviceFeedback.targetInstitutionHeading' : 'serviceFeedback.serviceProvidedBy')}</p>
                    <h2 className="text-xl font-semibold wrap-anywhere">{entry.target_name ?? entry.employee ?? entry.employee_number ?? '—'}</h2>
                    {entry.target_code && <p className="text-sm text-(--app-muted-foreground)">{entry.target_code}</p>}
                    {entry.reference && <p className="mt-1 font-mono text-sm">{entry.reference}</p>}
                </div>
                <FeedbackStatusBadge status={entry.status} className="text-sm" />
            </header>

            {entry.status === 'unrouted' && <Alert tone="warning">{t('serviceFeedback.unroutedDetail')}</Alert>}

            <div className="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div className="space-y-5">
                    <section className={PANEL}>
                        <h2 className="text-lg font-semibold">{t('serviceFeedback.submissionDetails')}</h2>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <Field label={t('serviceFeedback.submittedDate')}><LocalizedDateDisplay value={entry.submitted_at ?? entry.created_at} withTime /></Field>
                            {type === 'suggestion' && (
                                <Field label={t('serviceFeedback.tipTypeLabel')}>{entry.tip_type ? t(TIP_TYPE_LABEL[entry.tip_type]) : t('serviceFeedback.tipTypeUnclassified')}</Field>
                            )}
                            {entry.intake_channel && <Field label={t('serviceFeedback.intakeChannel')}>{t(`serviceFeedback.intakeChannel_${entry.intake_channel}`)}</Field>}
                            <Field label={t('serviceFeedback.filterOrganization')}>{entry.organization ?? '—'}</Field>
                            {entry.target_type === 'employee' ? <>
                                <Field label={t('serviceFeedback.serviceUnit')}>{entry.organization_unit ?? '—'}</Field>
                                <Field label={t('serviceFeedback.position')}>{entry.position ?? '—'}</Field>
                            </> : (
                                // What the visitor said the submission concerns: contextual attribution, not the handler and not a finding.
                                <Field label={t('serviceFeedback.relevantWorkUnit')}>{entry.organization_unit ?? (entry.unit_selection_mode === 'GENERAL' ? t('serviceFeedback.generalNotSure') : '—')}</Field>
                            )}
                            <Field label={t('serviceFeedback.category')}>{entry.category ?? '—'}</Field>
                            <Field label={t('serviceFeedback.client')}>{entry.identity_hidden ? t('serviceFeedback.identityHidden') : entry.client_name ?? t('serviceFeedback.anonymousClient')}</Field>
                            <Field label={t('serviceFeedback.contact')}>{entry.identity_hidden ? t('serviceFeedback.identityHidden') : entry.client_contact ?? t('serviceFeedback.notProvided')}</Field>
                            {!entry.identity_hidden && entry.client_email && <Field label={t('serviceFeedback.clientEmail')}>{entry.client_email}</Field>}
                            {!entry.identity_hidden && entry.submitter_organization && <Field label={t('serviceFeedback.submitterOrganization')}>{entry.submitter_organization}</Field>}
                            {!entry.identity_hidden && entry.submitter_address && (
                                <Field label={t('serviceFeedback.submitterAddress')}>{Object.values(entry.submitter_address).filter(Boolean).join(', ')}</Field>
                            )}
                            <Field label={t('serviceFeedback.incidentDate')}><LocalizedDateDisplay value={entry.incident_date} /></Field>
                            <Field label={t('serviceFeedback.incidentLocation')}>{entry.incident_location ?? '—'}</Field>
                        </dl>
                        <div className="border-t border-(--app-border) pt-4">
                            <h3 className="text-sm font-semibold">{entry.subject ?? t(type === 'complaint' ? 'serviceFeedback.complaintDescription' : 'serviceFeedback.suggestionText')}</h3>
                            <p className="mt-2 whitespace-pre-wrap wrap-break-word text-sm">{entry.message}</p>
                        </div>
                        {entry.involved_parties && (
                            <div className="border-t border-(--app-border) pt-4">
                                <h3 className="text-sm font-semibold">{t('serviceFeedback.involvedParties')}</h3>
                                <p className="mt-2 whitespace-pre-wrap wrap-break-word text-sm">{entry.involved_parties}</p>
                            </div>
                        )}
                    </section>

                    {organizationForm && <OrganizationFormDetails details={organizationForm} />}

                    {entry.attachments_hidden && entry.attachment_count > 0 && <section className={PANEL}>
                        <h2 className="font-semibold">{t('serviceFeedback.attachments')}</h2>
                        <p className="text-sm text-(--app-muted-foreground)">{t('serviceFeedback.attachmentsHidden').replace(':count', String(entry.attachment_count))}</p>
                    </section>}
                    {entry.attachments.length > 0 && <section className={PANEL}>
                        {/* Submitted evidence: what the citizen sent, not verified proof. Each download is audited. */}
                        <h2 className="font-semibold">{t('serviceFeedback.submittedEvidence')}</h2>
                        <ul className="space-y-2">{entry.attachments.map((file) => (
                            <li key={file.id} className="text-sm">
                                <a href={route('service-submissions.attachments.download', { type, submission: entry.id, attachment: file.id })} className="font-medium text-primary underline underline-offset-4">{file.name}</a>
                                <span className="ml-2 text-(--app-muted-foreground)">
                                    {file.kind ? t(EVIDENCE_LABEL[file.kind]) : ''} · {Math.ceil(file.size / 1024)} KB
                                    {file.scan_status === 'not_scanned' && <> · {t('serviceFeedback.evidenceNotScanned')}</>}
                                </span>
                            </li>
                        ))}</ul>
                    </section>}

                    <section className={PANEL}>
                        <h2 className="font-semibold">{t('serviceFeedback.statusHistory')}</h2>
                        {history.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{t('serviceFeedback.noHistory')}</p>
                            : <ol className="relative space-y-4 border-s border-(--app-border) ps-5">
                                {history.map((row) => <li key={row.id} className="relative">
                                    <span aria-hidden="true" className="absolute inset-s-[-1.45rem] top-1.5 h-2.5 w-2.5 rounded-full bg-primary" />
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        {row.from_status && <><FeedbackStatusBadge status={row.from_status} /><span aria-hidden="true">→</span></>}
                                        <FeedbackStatusBadge status={row.to_status} />
                                        <span className="text-(--app-muted-foreground)"><LocalizedDateDisplay value={row.transitioned_at} withTime /></span>
                                    </div>
                                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{row.actor ?? t('common.system')}{row.reason ? <> · {row.reason}</> : row.to_status === 'unrouted' ? <> · {t('serviceFeedback.reasonNoRoutingRule')}</> : row.to_status === 'assigned' && !row.actor ? <> · {t('serviceFeedback.viaRoutingRule')}</> : null}</p>
                                </li>)}
                            </ol>}
                    </section>
                </div>

                <div className="space-y-5">
                    <section className={PANEL}>
                        <div className="flex items-start justify-between gap-3">
                            <h2 className="font-semibold">{t('serviceFeedback.currentHandler')}</h2>
                            {can.assign && <Button type="button" size="sm" variant={currentAssignment ? 'outline' : 'primary'} onClick={() => setAssigning(true)}>{t(currentAssignment ? 'serviceFeedback.reassign' : 'serviceFeedback.assignHandler')}</Button>}
                        </div>
                        {currentAssignment ? <div className="space-y-1 text-sm">
                            <div className="font-medium">{currentAssignment.handler}</div>
                            <div className="text-(--app-muted-foreground)">{t(`serviceFeedback.${HANDLER_TYPE_KEY[currentAssignment.handler_type]}`)} · {currentAssignment.via_rule ? t('serviceFeedback.viaRoutingRule') : currentAssignment.assigned_by ?? t('common.system')} · <LocalizedDateDisplay value={currentAssignment.assigned_at} withTime /></div>
                        </div> : <p className="text-sm text-(--app-muted-foreground)">{t('serviceFeedback.noHandler')}</p>}
                        {assignments.length > 1 && <details className="text-sm">
                            <summary className="cursor-pointer font-medium text-primary">{t('serviceFeedback.assignmentHistory')}</summary>
                            <ul className="mt-2 space-y-2">{assignments.map((row) => <li key={row.id} className="rounded-control bg-(--app-surface-muted) p-2">
                                <div className="font-medium">{row.handler} <span className="font-normal text-(--app-muted-foreground)">({t(`serviceFeedback.${HANDLER_TYPE_KEY[row.handler_type]}`)})</span></div>
                                <div className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={row.assigned_at} withTime />{row.ended_at && <> → <LocalizedDateDisplay value={row.ended_at} withTime /></>} · {row.via_rule ? t('serviceFeedback.viaRoutingRule') : row.assigned_by ?? t('common.system')}{row.reason && <> · {row.reason}</>}</div>
                            </li>)}</ul>
                        </details>}
                    </section>

                    {allowedTransitions.length > 0 && <TransitionForm key={`${entry.status}:${allowedTransitions.join(',')}`} type={type} submissionId={entry.id} allowedTransitions={allowedTransitions} />}

                    {entry.review_note && <Card className="p-5 text-sm"><h2 className="font-semibold">{t('serviceFeedback.reviewNote')}</h2><p className="mt-2 whitespace-pre-wrap">{entry.review_note}</p></Card>}
                    {type === 'complaint' && <SubjectsPanel submissionId={entry.id} subjects={subjects} canManage={can.subjects} />}
                    {type === 'complaint' && <ReferralsPanel submissionId={entry.id} referrals={referrals} canRefer={can.refer} canDecide={can.decideReferral} />}
                </div>
            </div>
        </div>

        {assigning && handlerOptions && <AssignDialog type={type} submissionId={entry.id} options={handlerOptions} reassign={currentAssignment !== null} locale={locale} onClose={() => setAssigning(false)} />}
    </AuthenticatedLayout>;
}

const PANEL = 'space-y-4 rounded-panel border border-(--app-border) bg-(--app-surface) p-5';

/** Keyed by the current status by its parent, so its default choice follows every change. */
function TransitionForm({ type, submissionId, allowedTransitions }: { type: 'suggestion' | 'complaint'; submissionId: string; allowedTransitions: string[] }) {
    const { t } = useLocale();
    const form = useForm({ status: allowedTransitions[0] ?? '', reason: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('service-submissions.transition', { type, submission: submissionId }), { preserveScroll: true, onSuccess: () => form.reset('reason') });
    }

    return <form onSubmit={submit} className={PANEL}>
        <h2 className="font-semibold">{t('serviceFeedback.changeStatus')}</h2>
        <FormField id="transition-status" label={t('serviceFeedback.newStatus')} error={form.errors.status} required>
            {({ id, describedBy, invalid }) => <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                {allowedTransitions.map((status) => <option key={status} value={status}>{t(statusLabelKey(status))}</option>)}
            </Select>}
        </FormField>
        <FormField id="transition-reason" label={t('serviceFeedback.transitionReason')} description={t('serviceFeedback.reviewHint')} error={form.errors.reason}>
            {({ id, describedBy, invalid }) => <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} rows={3} maxLength={2000} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />}
        </FormField>
        <Button type="submit" variant="primary" loading={form.processing}>{t('serviceFeedback.saveStatus')}</Button>
    </form>;
}

function AssignDialog({ type, submissionId, options, reassign, locale, onClose }: {
    type: 'suggestion' | 'complaint'; submissionId: string; options: HandlerOptions; reassign: boolean; locale: string; onClose: () => void;
}) {
    const { t } = useLocale();
    const form = useForm({ handler_type: 'user' as AssignmentRow['handler_type'], handler_id: options.users[0]?.id ?? '', reason: '' });
    const choices = form.data.handler_type === 'user' ? options.users
        : form.data.handler_type === 'organization_unit' ? options.units.map((unit) => ({ id: unit.id, name: locale === 'am' && unit.name_am ? unit.name_am : unit.name }))
            : options.roles;

    function setType(handlerType: AssignmentRow['handler_type']) {
        const first = (handlerType === 'user' ? options.users : handlerType === 'organization_unit' ? options.units : options.roles)[0]?.id ?? '';
        form.setData((data) => ({ ...data, handler_type: handlerType, handler_id: first }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('service-submissions.assign', { type, submission: submissionId }), { preserveScroll: true, onSuccess: onClose });
    }

    return <AppDialog open onClose={onClose} title={t(reassign ? 'serviceFeedback.reassign' : 'serviceFeedback.assignHandler')} description={t('serviceFeedback.assignHint')} maxWidth="lg"
        footer={<><Button type="button" variant="outline" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="assign-form" variant="primary" loading={form.processing}>{t(reassign ? 'serviceFeedback.reassign' : 'serviceFeedback.assignHandler')}</Button></>}>
        <form id="assign-form" onSubmit={submit} className="grid gap-4">
            <FormField id="assign-type" label={t('serviceFeedback.handlerType')} error={form.errors.handler_type} required>
                {({ id, describedBy, invalid }) => <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.handler_type} onChange={(e) => setType(e.target.value as AssignmentRow['handler_type'])}>
                    <option value="user">{t('serviceFeedback.handlerUser')}</option>
                    <option value="organization_unit">{t('serviceFeedback.handlerUnit')}</option>
                    <option value="role">{t('serviceFeedback.handlerRole')}</option>
                </Select>}
            </FormField>
            <FormField id="assign-handler" label={t('serviceFeedback.handler')} error={form.errors.handler_id} required>
                {({ id, describedBy, invalid }) => <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.handler_id} onChange={(e) => form.setData('handler_id', e.target.value)}>
                    {choices.length === 0 && <option value="">{t('serviceFeedback.noHandlerOptions')}</option>}
                    {choices.map((choice) => <option key={choice.id} value={choice.id}>{choice.name}</option>)}
                </Select>}
            </FormField>
            <FormField id="assign-reason" label={t('serviceFeedback.transitionReason')} error={form.errors.reason}>
                {({ id, describedBy, invalid }) => <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} rows={3} maxLength={2000} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />}
            </FormField>
        </form>
    </AppDialog>;
}
