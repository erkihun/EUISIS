import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { useBranding } from '@/hooks/useBranding';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card, TBody, THead, Table, Td, Th, Tr, actionLinkClassName, buttonClassName } from '@euisis/ui';

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
};

/**
 * My Portal > My court cases (court-ms Flow 4). Applicants at the administrative
 * tribunal are city employees: their cases are read from court-ms, filed here
 * (Flow 5, Employee/CourtCaseFile) and followed and answered here too (Flow 6,
 * Employee/CourtCaseShow); the court portal itself is not used.
 */
export default function EmployeeCourtCases({ state, summary, error, filed }: Props) {
    const { t } = useLocale();
    const { withBrand } = useBranding();
    const label = (key: string) => t(`courtCases.portal.${key}`);
    const [reading, setReading] = useState<string | null>(null);

    /** Marks one notice (or all of them) as read in court-ms; the list comes back without it. */
    const markRead = (id: string | null) => {
        setReading(id ?? 'all');
        router.post(route('employee.court-cases.notices.read'), id === null ? { all: true } : { id }, { preserveScroll: true, onFinish: () => setReading(null) });
    };

    // Cases are filed in EUISIS, against an institution registered here (court-ms Flow 5).
    const fileLink = <Link href={route('employee.court-cases.file')} className={buttonClassName({ variant: 'primary', size: 'sm' })}>{label('fileCase')}</Link>;

    const actions = state === 'linked' || state === 'not_linked' ? fileLink : undefined;

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
                                            <Td className="whitespace-nowrap font-mono text-sm">{courtCase.case_number}</Td>
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
