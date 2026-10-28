import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX, type ReactNode } from 'react';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card, CardDescription, CardHeader, CardTitle, FormField, Select, Textarea, buttonClassName } from '@euisis/ui';
import type { InstitutionCase } from './InstitutionCases';

type Court = {
    case: { id: number; case_number: string; case_type: string | null; status: string; review_status: string; filed_at: string | null; appealed_at: string | null; appeal_outcome: string | null; merged_into: string | null };
    applicants: Array<{ name: string; role: string }>;
    hearings: Array<{ at: string | null; type: string | null; location: string | null }>;
    decision: { title: string; decided_at: string | null } | null;
    appeal: {
        appealable: boolean;
        requests: Array<{ id: number; appeal_number: string; status: string; note: string | null; requested_at: string | null; released_at: string | null; outcome: string | null }>;
        released: boolean;
    };
};

type Props = {
    case: InstitutionCase;
    /** The case as court-ms shows it to the institution; null when court-ms cannot be reached. */
    court: Court | null;
    officers: Array<{ id: number; name: string }>;
    can: { manage: boolean };
    error: string | null;
};

/**
 * A case against the institution (court-ms Flow 7, ADR 0013 #14): its course read
 * live from the court, the officer who follows it, and the appeal. Once the case
 * is closed, the officer asks for its record to take to the appellate court; the
 * court's registry releases it, and it is downloaded here as one PDF.
 */
export default function InstitutionCaseShow({ case: row, court, officers, can, error }: Props): JSX.Element {
    const { t } = useLocale();
    const label = (key: string, values: Record<string, string | number> = {}) =>
        Object.entries(values).reduce((text, [name, value]) => text.replaceAll(`{${name}}`, String(value)), t(`courtCases.institution.${key}`));
    const [officer, setOfficer] = useState(row.officer ? String(row.officer.id) : '');
    const appealForm = useForm({ grounds: '' });
    const appeal = court?.appeal;
    const openAppeal = appeal?.requests.find((request) => ['submitted', 'under_review', 'approved', 'closed'].includes(request.status));

    const assign = () => router.post(route('tribunal-cases.institution-cases.assign', row.id), { officer_user_id: officer }, { preserveScroll: true });
    const sendAppeal = (event: FormEvent) => {
        event.preventDefault();
        appealForm.post(route('tribunal-cases.institution-cases.appeal', row.id), { preserveScroll: true, onSuccess: () => appealForm.reset() });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={label('caseTitle', { number: row.case_number })} description={row.case_type ?? undefined} backHref={route('tribunal-cases.institution-cases.index')} />}>
            <Head title={label('caseTitle', { number: row.case_number })} />

            <div className="space-y-6">
                {error && <Alert tone="danger">{label(`errors.${error}`)}</Alert>}
                {!court && <Alert tone="warning">{t('courtCases.portal.unavailable')}</Alert>}
                {court?.case.appealed_at && (
                    <Alert tone="warning">{label('appealed')}{court.case.appeal_outcome && <> · {label(`appeal.outcomes.${court.case.appeal_outcome}`)}</>}</Alert>
                )}

                <Card className="space-y-4">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Fact name={label('table.applicants')}>{(court?.applicants.map((party) => party.name) ?? row.applicants).join(', ') || '—'}</Fact>
                        <Fact name={label('table.status')}>{court ? t(`courtCases.portal.status.${court.case.status}`) : (row.status ? t(`courtCases.portal.status.${row.status}`) : '—')}</Fact>
                        <Fact name={label('filed')}><LocalizedDateDisplay value={court?.case.filed_at ?? row.filed_at} /></Fact>
                        <Fact name={label('table.received')}><LocalizedDateDisplay value={row.received_at} withTime /></Fact>
                    </dl>
                    {court?.decision && <p className="text-sm text-(--app-foreground)">{label('decided', { title: court.decision.title })} <LocalizedDateDisplay value={court.decision.decided_at} /></p>}
                </Card>

                <Card className="space-y-3">
                    <CardHeader className="mb-0">
                        <CardTitle>{label('officer')}</CardTitle>
                        <CardDescription>{row.officer ? label('followedBy', { name: row.officer.name }) : label('unassignedHelp')}</CardDescription>
                    </CardHeader>
                    {can.manage && (
                        <div className="flex flex-wrap items-end gap-2">
                            <FormField label={label('assignTo')} className="min-w-64">
                                {({ id }) => (
                                    <Select id={id} value={officer} onChange={(e) => setOfficer(e.target.value)}>
                                        <option value="">—</option>
                                        {officers.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                                    </Select>
                                )}
                            </FormField>
                            <Button type="button" variant="outline" disabled={officer === '' || officer === String(row.officer?.id ?? '')} onClick={assign}>{label('assign')}</Button>
                        </div>
                    )}
                </Card>

                {court && court.hearings.length > 0 && (
                    <Card>
                        <CardHeader className="mb-2"><CardTitle>{label('hearings')}</CardTitle></CardHeader>
                        <ul className="divide-y divide-(--app-border)">
                            {court.hearings.map((hearing, index) => (
                                <li key={index} className="py-2 text-sm">
                                    <LocalizedDateDisplay value={hearing.at} withTime className="font-semibold text-(--app-foreground)" />
                                    {[hearing.type, hearing.location].filter(Boolean).map((part) => <span key={part} className="text-(--app-muted-foreground)"> · {part}</span>)}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                {appeal && (appeal.appealable || appeal.requests.length > 0) && (
                    <Card className="space-y-3">
                        <CardHeader className="mb-0">
                            <CardTitle>{label('appeal.title')}</CardTitle>
                            <CardDescription>{label('appeal.help')}</CardDescription>
                        </CardHeader>
                        {appeal.requests.map((request) => (
                            <div key={request.id} className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-(--app-border) p-3 text-sm">
                                <span className="min-w-0 space-y-0.5">
                                    <span className="block font-mono font-semibold text-(--app-foreground)">{request.appeal_number}</span>
                                    <LocalizedDateDisplay value={request.requested_at} className="block text-xs text-(--app-muted-foreground)" />
                                    {request.status === 'rejected' && request.note && <span className="block text-(--app-muted-foreground)">{request.note}</span>}
                                    {request.outcome && <span className="block font-medium">{label('appeal.outcome', { outcome: label(`appeal.outcomes.${request.outcome}`) })}</span>}
                                </span>
                                <StatusBadge status={request.status === 'approved' || request.status === 'closed' ? 'approved' : request.status === 'rejected' ? 'rejected' : 'pending'} label={label(`appeal.statuses.${request.status}`)} />
                            </div>
                        ))}
                        {appeal.released && (
                            <div className="flex flex-wrap items-center gap-3 rounded-lg bg-(--app-surface-muted) p-3">
                                <a href={route('tribunal-cases.institution-cases.appeal-record', row.id)} className={buttonClassName({ variant: 'primary', size: 'sm' })}>{label('appeal.download')}</a>
                                <span className="text-xs text-(--app-muted-foreground)">{label('appeal.downloadHelp')}</span>
                            </div>
                        )}
                        {appeal.appealable && !openAppeal && can.manage && (
                            <form onSubmit={sendAppeal} className="space-y-3">
                                <FormField label={label('appeal.grounds')} required error={appealForm.errors.grounds}>
                                    {({ id, describedBy, invalid }) => (
                                        <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} required rows={4} maxLength={10000} value={appealForm.data.grounds}
                                            onChange={(e) => appealForm.setData('grounds', e.target.value)} />
                                    )}
                                </FormField>
                                <Button type="submit" variant="primary" size="sm" loading={appealForm.processing} disabled={appealForm.processing}>{label('appeal.submit')}</Button>
                            </form>
                        )}
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Fact({ name, children }: { name: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-(--app-muted-foreground)">{name}</dt>
            <dd className="mt-1 text-sm text-(--app-foreground)">{children}</dd>
        </div>
    );
}
