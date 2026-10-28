import { Link } from '@inertiajs/react';
import axios from 'axios';
import { useState, type ReactNode } from 'react';
import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { useBranding } from '@/hooks/useBranding';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card, TBody, THead, Table, Td, Th, Tr, buttonClassName } from '@euisis/ui';

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

type Notice = { id: string; title: string; message: string; created_at: string | null };

type Props = {
    /** linked: the employee has a court account; not_linked: the first open creates it. */
    state: 'linked' | 'not_linked' | 'unavailable' | 'disabled' | 'no_employee';
    summary: {
        counts: { cases?: number; open?: number; awaiting_review?: number; upcoming_hearings?: number; unread_notices?: number };
        cases: CourtCase[];
        notices: Notice[];
    } | null;
    /** Why the last attempt to open the court portal was refused (courtCases.portal.errors.*). */
    error: string | null;
    /** The case number of a case just filed from My Portal (Flow 5). */
    filed: string | null;
};

/**
 * My Portal > My court cases (court-ms Flow 4). Applicants at the administrative
 * tribunal are city employees: their cases are read from court-ms and filed here
 * (Flow 5, Employee/CourtCaseFile); following and answering a case opens the court
 * portal in a new tab, already signed in as them.
 */
export default function EmployeeCourtCases({ state, summary, error: initialError, filed }: Props) {
    const { t } = useLocale();
    const { withBrand } = useBranding();
    const label = (key: string) => t(`courtCases.portal.${key}`);
    const [opening, setOpening] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(initialError);
    const canOpen = state === 'linked' || state === 'not_linked' || state === 'unavailable';

    /** Opens the tab inside the click (so no pop-up blocker stops it), then sends it to the one-time link. */
    async function open(target: string) {
        const tab = window.open('about:blank', '_blank');
        setOpening(target);
        setError(null);
        try {
            const response = await axios.post<{ url: string }>(route('employee.court-cases.open'), { target }, { headers: { Accept: 'application/json' } });
            if (tab) {
                tab.opener = null;
                tab.location.href = response.data.url;
            } else {
                window.location.href = response.data.url;
            }
        } catch (failure) {
            tab?.close();
            const code = axios.isAxiosError(failure) ? (failure.response?.data as { error_code?: string } | undefined)?.error_code : undefined;
            setError(code ?? 'unavailable');
        } finally {
            setOpening(null);
        }
    }

    const openButton = (target: string, text: string, variant: 'primary' | 'outline' = 'outline') => (
        <Button type="button" variant={variant} size="sm" onClick={() => void open(target)} disabled={opening !== null || !canOpen}
            aria-label={`${text} (${label('opensInNewTab')})`} loading={opening === target}>
            {text}
        </Button>
    );

    // Cases are filed in EUISIS, against an institution registered here (court-ms Flow 5).
    const fileLink = <Link href={route('employee.court-cases.file')} className={buttonClassName({ variant: 'primary', size: 'sm' })}>{label('fileCase')}</Link>;

    const actions = canOpen ? (
        <div className="flex flex-wrap gap-2">
            {state !== 'unavailable' && fileLink}
            {openButton('dashboard', label('openPortal'))}
        </div>
    ) : undefined;

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
                                            <Td muted={!courtCase.respondent}>{courtCase.respondent ?? '—'}</Td>
                                            <Td muted={!courtCase.case_type} className="whitespace-nowrap">{courtCase.case_type ?? '—'}</Td>
                                            <Td><StatusBadge status={courtCase.status} label={label(`status.${courtCase.status}`)} /></Td>
                                            <Td><StatusBadge status={courtCase.review_status} label={label(`review.${courtCase.review_status}`)} /></Td>
                                            <Td className="whitespace-nowrap"><LocalizedDateDisplay value={courtCase.filed_at} /></Td>
                                            <Td muted={!courtCase.next_hearing_at} className="whitespace-nowrap">
                                                {courtCase.next_hearing_at ? <LocalizedDateDisplay value={courtCase.next_hearing_at} withTime /> : label('noHearing')}
                                            </Td>
                                            <Td className="whitespace-nowrap">{openButton(`case:${courtCase.id}`, label('openCase'))}</Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>

                    <Card className="space-y-3">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-base font-semibold text-(--app-foreground)">{label('noticesTitle')}</h2>
                            {summary.notices.length > 0 && openButton('notifications', label('viewNotices'))}
                        </div>
                        {summary.notices.length === 0 ? (
                            <p className="text-sm text-(--app-muted-foreground)">{label('noNotices')}</p>
                        ) : (
                            <ul className="divide-y divide-(--app-border)">
                                {summary.notices.map((notice) => (
                                    <li key={notice.id} className="py-3">
                                        <p className="text-sm font-semibold text-(--app-foreground)">{notice.title}</p>
                                        <p className="text-sm text-(--app-muted-foreground)">{notice.message}</p>
                                        <LocalizedDateDisplay value={notice.created_at} withTime className="text-xs text-(--app-muted-foreground)" />
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
