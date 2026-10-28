import { Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { useBranding } from '@/hooks/useBranding';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card, FormField, Select, TBody, THead, Table, Td, Th, Tr, actionLinkClassName, buttonClassName } from '@euisis/ui';

type CourtCase = {
    id: number;
    case_number: string;
    title: string;
    /** The other party (court-ms Flow 4); the case title is the employee's own name. */
    respondent: string | null;
    case_type: string | null;
    status: string;
    review_status: string;
    filed_at: string | null;
    next_hearing_at: string | null;
    /** How the employee is a party (ADR 0013 #13): lead, co_applicant or intervener. */
    role?: string | null;
    /** The case this one was merged into. */
    merged_into?: { id: number; case_number: string } | null;
};

/** An invitation to join a case, or an application to intervene (court-ms case_applicants). */
type PartyCard = {
    id: number;
    case_id: number;
    case_number: string;
    case_type: string | null;
    respondent: string | null;
    role: 'lead' | 'co_applicant' | 'intervener';
    status: string;
    invited_by: string | null;
    lawyer: { name: string; license_number: string | null } | null;
    decision_note: string | null;
    decided_at: string | null;
    created_at: string | null;
};

/** `id` is court-ms's `type:source` key; `case_id` the case it concerns. */
type Notice = { id: string; title: string; message: string; created_at: string | null; case_id?: number | null };

type Props = {
    /** linked: the employee has a court account; not_linked: filing the first case creates it. */
    state: 'linked' | 'not_linked' | 'unavailable' | 'disabled' | 'no_employee';
    summary: {
        counts: { cases?: number; open?: number; awaiting_review?: number; upcoming_hearings?: number; unread_notices?: number };
        cases: CourtCase[];
        notices: Notice[];
    } | null;
    /** Why court-ms refused the last step (courtCases.portal.errors.*). */
    error: string | null;
    /** The case number of a case just filed from My Portal (Flow 5). */
    filed: string | null;
    /** Invitations to join a case and applications to intervene; null when court-ms cannot be reached. */
    parties: { invitations: PartyCard[]; interventions: PartyCard[] } | null;
    /** The court's register of verified lawyers, when an invitation lets the employee name one. */
    lawyers?: Array<{ name: string; license_number: string }>;
    /** The step just done (courtCases.portal.done.*). */
    done: string | null;
};

/**
 * Joining a colleague's case: the employee may name a lawyer of their own from
 * the court's register, with the power of attorney the registry then checks.
 * Without a lawyer they simply join.
 */
function JoinPanel({ invitation, lawyers, onCancel }: { invitation: PartyCard; lawyers: Array<{ name: string; license_number: string }>; onCancel: () => void }) {
    const { t } = useLocale();
    const label = (key: string) => t(`courtCases.portal.invitations.${key}`);
    const form = useForm<{ accept: boolean; lawyer_license_number: string; power_of_attorney: File | null }>({ accept: true, lawyer_license_number: '', power_of_attorney: null });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('employee.court-cases.invitations.respond', invitation.id), { forceFormData: true, preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="basis-full space-y-3 rounded-md border border-dashed border-(--app-border) bg-(--app-surface-muted) p-3">
            <p className="text-sm text-(--app-muted-foreground)">{label('lawyerHelp')}</p>
            <div className="grid gap-3 md:grid-cols-2">
                <FormField label={label('lawyer')} description={lawyers.length === 0 ? label('noLawyers') : undefined} error={errors.lawyer_license_number}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.lawyer_license_number} disabled={lawyers.length === 0}
                            onChange={(e) => form.setData('lawyer_license_number', e.target.value)}>
                            <option value="">{label('noLawyer')}</option>
                            {lawyers.map((lawyer) => <option key={lawyer.license_number} value={lawyer.license_number}>{`${lawyer.name} · ${lawyer.license_number}`}</option>)}
                        </Select>
                    )}
                </FormField>
                {form.data.lawyer_license_number !== '' && (
                    <FormField label={label('powerOfAttorney')} required description={label('powerOfAttorneyHelp')} error={errors.power_of_attorney}>
                        {({ id, describedBy, invalid }) => (
                            <input id={id} aria-describedby={describedBy} aria-invalid={invalid} type="file" required accept=".pdf,application/pdf"
                                className="block w-full text-sm text-(--app-foreground) file:mr-3 file:rounded-md file:border-0 file:bg-(--app-surface) file:px-3 file:py-2"
                                onChange={(e) => form.setData('power_of_attorney', e.target.files?.[0] ?? null)} />
                        )}
                    </FormField>
                )}
            </div>
            <div className="flex flex-wrap gap-2">
                <Button type="submit" size="sm" variant="primary" loading={form.processing}>{label('join')}</Button>
                <Button type="button" size="sm" variant="ghost" disabled={form.processing} onClick={onCancel}>{label('cancel')}</Button>
            </div>
        </form>
    );
}

/**
 * My Portal > My court cases (court-ms Flow 4). Applicants at the administrative
 * tribunal are city employees: their cases are read from court-ms, filed here
 * (Flow 5, Employee/CourtCaseFile) and followed and answered here too (Flow 6,
 * Employee/CourtCaseShow); the court portal itself is not used.
 */
export default function EmployeeCourtCases({ state, summary, error, filed, parties, lawyers = [], done }: Props) {
    const { t } = useLocale();
    const { withBrand } = useBranding();
    const label = (key: string) => t(`courtCases.portal.${key}`);
    const [reading, setReading] = useState<string | null>(null);
    const [answering, setAnswering] = useState<number | null>(null);
    // The colleague's invitation being joined: its panel asks whether to name a lawyer first.
    const [joining, setJoining] = useState<number | null>(null);

    /** Joins the case (it opens here once joined) or declines the invitation. */
    const answer = (invitation: PartyCard, accept: boolean) => {
        setAnswering(invitation.id);
        router.post(route('employee.court-cases.invitations.respond', invitation.id), { accept }, { preserveScroll: true, onFinish: () => setAnswering(null) });
    };

    /** Marks one notice (or all of them) as read in court-ms; the list comes back without it. */
    const markRead = (id: string | null) => {
        setReading(id ?? 'all');
        router.post(route('employee.court-cases.notices.read'), id === null ? { all: true } : { id }, { preserveScroll: true, onFinish: () => setReading(null) });
    };

    // Cases are filed in EUISIS, against an institution registered here (court-ms Flow 5).
    const fileLink = <Link href={route('employee.court-cases.file')} className={buttonClassName({ variant: 'primary', size: 'sm' })}>{label('fileCase')}</Link>;

    const actions = state === 'linked' || state === 'not_linked' ? (
        <div className="flex flex-wrap gap-2">
            <Link href={route('employee.court-cases.intervene')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{label('intervene')}</Link>
            {fileLink}
        </div>
    ) : undefined;
    const invitations = parties?.invitations ?? [];
    const interventions = parties?.interventions ?? [];

    const counts = summary?.counts ?? {};
    const stat = (value: number | undefined, text: string): ReactNode => (
        <Card className="flex flex-col gap-1">
            <span className="text-xs font-medium uppercase tracking-wide text-(--app-muted-foreground)">{text}</span>
            <span className="text-2xl font-semibold text-(--app-foreground)">{value ?? 0}</span>
        </Card>
    );

    return (
        <PortalPage title={label('title')} description={label('description')} actions={actions}>
            {error && <Alert tone="danger">{t(`courtCases.portal.errors.${error}`)}</Alert>}
            {filed && <Alert tone="success">{label('filed').replace('{number}', filed)}</Alert>}
            {done && <Alert tone="success">{label(`done.${done}`)}</Alert>}

            {invitations.length > 0 && (
                <Card className="space-y-3">
                    <div>
                        <h2 className="text-base font-semibold text-(--app-foreground)">{label('invitations.title')}</h2>
                        <p className="text-sm text-(--app-muted-foreground)">{label('invitations.help')}</p>
                    </div>
                    <ul className="divide-y divide-(--app-border)">
                        {invitations.map((invitation) => (
                            <li key={invitation.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                                <span className="min-w-0 space-y-0.5">
                                    <span className="block text-sm font-semibold text-(--app-foreground)">
                                        <span className="font-mono">{invitation.case_number}</span>
                                        {invitation.respondent && <> · {invitation.respondent}</>}
                                    </span>
                                    <span className="block text-sm text-(--app-muted-foreground)">
                                        {/* A lawyer filed for them (as the applicant or one of several clients), or a colleague named them. */}
                                        {invitation.lawyer && !invitation.invited_by
                                            ? label('invitations.byLawyer').replace('{name}', invitation.lawyer.name)
                                            : label('invitations.byColleague').replace('{name}', invitation.invited_by ?? '—')}
                                    </span>
                                    {invitation.case_type && <span className="block text-xs text-(--app-muted-foreground)">{invitation.case_type}</span>}
                                </span>
                                <span className="flex flex-wrap gap-2">
                                    <Button type="button" size="sm" variant="primary" disabled={answering !== null || joining === invitation.id} loading={answering === invitation.id}
                                        onClick={() => (invitation.lawyer ? answer(invitation, true) : setJoining(invitation.id))}>
                                        {label('invitations.accept')}
                                    </Button>
                                    <Button type="button" size="sm" variant="outline" onClick={() => answer(invitation, false)} disabled={answering !== null}>{label('invitations.decline')}</Button>
                                </span>
                                {joining === invitation.id && <JoinPanel invitation={invitation} lawyers={lawyers} onCancel={() => setJoining(null)} />}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {state === 'no_employee' && <Alert tone="info">{label('noEmployee')}</Alert>}
            {state === 'disabled' && <Alert tone="info">{withBrand(label('disabled'))}</Alert>}
            {state === 'unavailable' && <Alert tone="warning">{label('unavailable')}</Alert>}
            {state === 'not_linked' && (
                <Card className="space-y-3">
                    <h2 className="text-base font-semibold text-(--app-foreground)">{label('notLinkedTitle')}</h2>
                    <p className="text-sm text-(--app-muted-foreground)">{label('notLinked')}</p>
                    <div>{fileLink}</div>
                </Card>
            )}

            {state === 'linked' && summary && (
                <>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                        {stat(counts.cases, label('counts.cases'))}
                        {stat(counts.open, label('counts.open'))}
                        {stat(counts.awaiting_review, label('counts.awaitingReview'))}
                        {stat(counts.upcoming_hearings, label('counts.upcomingHearings'))}
                        {stat(counts.unread_notices, label('counts.unreadNotices'))}
                    </div>

                    <Card className="p-0">
                        {summary.cases.length === 0 ? (
                            <EmptyState title={label('noCases')} />
                        ) : (
                            <Table>
                                <THead>
                                    <Tr>
                                        {(['caseNumber', 'respondent', 'type', 'status', 'review', 'filed', 'nextHearing', 'actions'] as const).map((column) => (
                                            <Th key={column} scope="col">{label(`table.${column}`)}</Th>
                                        ))}
                                    </Tr>
                                </THead>
                                <TBody>
                                    {summary.cases.map((courtCase) => (
                                        <Tr key={courtCase.id}>
                                            <Td className="whitespace-nowrap">
                                                <span className="block font-mono text-sm">{courtCase.case_number}</span>
                                                {courtCase.role && courtCase.role !== 'lead' && <span className="block text-xs text-(--app-muted-foreground)">{label(`roles.${courtCase.role}`)}</span>}
                                                {courtCase.merged_into && <span className="block text-xs text-amber-700 dark:text-amber-400">{label('mergedInto').replace('{number}', courtCase.merged_into.case_number)}</span>}
                                            </Td>
                                            <Td muted={!courtCase.respondent} className="min-w-48">{courtCase.respondent ?? '—'}</Td>
                                            <Td muted={!courtCase.case_type} className="whitespace-nowrap">{courtCase.case_type ?? '—'}</Td>
                                            <Td><StatusBadge status={courtCase.status} label={label(`status.${courtCase.status}`)} /></Td>
                                            <Td><StatusBadge status={courtCase.review_status} label={label(`review.${courtCase.review_status}`)} /></Td>
                                            <Td className="whitespace-nowrap"><LocalizedDateDisplay value={courtCase.filed_at} /></Td>
                                            <Td muted={!courtCase.next_hearing_at} className="whitespace-nowrap">
                                                {courtCase.next_hearing_at ? <LocalizedDateDisplay value={courtCase.next_hearing_at} withTime /> : label('noHearing')}
                                            </Td>
                                            <Td className="whitespace-nowrap">
                                                <Link href={route('employee.court-cases.show', courtCase.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                                    aria-label={`${label('openCase')} ${courtCase.case_number}`}>{label('openCase')}</Link>
                                            </Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>

                    {interventions.length > 0 && (
                        <Card className="space-y-3">
                            <h2 className="text-base font-semibold text-(--app-foreground)">{label('interventions.title')}</h2>
                            <ul className="divide-y divide-(--app-border)">
                                {interventions.map((intervention) => (
                                    <li key={intervention.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                                        <span className="min-w-0 space-y-0.5">
                                            <span className="block font-mono text-sm font-semibold text-(--app-foreground)">{intervention.case_number}</span>
                                            <LocalizedDateDisplay value={intervention.created_at} className="block text-xs text-(--app-muted-foreground)" />
                                            {intervention.decision_note && <span className="block text-sm text-(--app-muted-foreground)">{intervention.decision_note}</span>}
                                        </span>
                                        <span className="flex items-center gap-3">
                                            <StatusBadge status={intervention.status} label={label(`interventions.status.${intervention.status}`)} />
                                            {intervention.status === 'admitted' && (
                                                <Link href={route('employee.court-cases.show', intervention.case_id)} className={actionLinkClassName()}>{label('openCase')}</Link>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    <Card className="space-y-3">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-base font-semibold text-(--app-foreground)">{label('noticesTitle')}</h2>
                            {summary.notices.length > 0 && (
                                <Button type="button" variant="outline" size="sm" onClick={() => markRead(null)} disabled={reading !== null} loading={reading === 'all'}>{label('markAllRead')}</Button>
                            )}
                        </div>
                        {summary.notices.length === 0 ? (
                            <p className="text-sm text-(--app-muted-foreground)">{label('noNotices')}</p>
                        ) : (
                            <ul className="divide-y divide-(--app-border)">
                                {summary.notices.map((notice) => (
                                    <li key={notice.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                                        <span className="min-w-0">
                                            <span className="block text-sm font-semibold text-(--app-foreground)">{notice.title}</span>
                                            <span className="block text-sm text-(--app-muted-foreground)">{notice.message}</span>
                                            <LocalizedDateDisplay value={notice.created_at} withTime className="text-xs text-(--app-muted-foreground)" />
                                        </span>
                                        <span className="flex gap-3">
                                            {notice.case_id && <Link href={route('employee.court-cases.show', notice.case_id)} className={actionLinkClassName()}>{label('openCase')}</Link>}
                                            <button type="button" onClick={() => markRead(notice.id)} disabled={reading !== null} className={actionLinkClassName({ tone: 'neutral' })}>{label('markRead')}</button>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </>
            )}
        </PortalPage>
    );
}
