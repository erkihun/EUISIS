import axios from 'axios';
import { useState, type ReactNode } from 'react';
import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { useBranding } from '@/hooks/useBranding';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card } from '@euisis/ui';
import {
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';

type CourtCase = {
    id: number;
    case_number: string;
    title: string;
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
};

/**
 * My Portal > My court cases (court-ms Flow 4). Applicants at the administrative
 * tribunal are city employees: their cases are read from court-ms, and every
 * action opens the court portal in a new tab, already signed in as them.
 */
export default function EmployeeCourtCases({ state, summary, error: initialError }: Props) {
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

    const actions = canOpen ? (
        <div className="flex flex-wrap gap-2">
            {openButton('new_case', label('fileCase'), 'primary')}
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

            {state === 'no_employee' && <Alert tone="info">{label('noEmployee')}</Alert>}
            {state === 'disabled' && <Alert tone="info">{withBrand(label('disabled'))}</Alert>}
            {state === 'unavailable' && <Alert tone="warning">{label('unavailable')}</Alert>}
            {state === 'not_linked' && (
                <Card className="space-y-3">
                    <h2 className="text-base font-semibold text-(--app-foreground)">{label('notLinkedTitle')}</h2>
                    <p className="text-sm text-(--app-muted-foreground)">{label('notLinked')}</p>
                    <div>{openButton('new_case', label('fileCase'), 'primary')}</div>
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
                            <div className={tableContainerClassName}>
                                <table className={tableClassName}>
                                    <thead className={tableHeadClassName}>
                                        <tr>
                                            {(['caseNumber', 'title', 'type', 'status', 'review', 'filed', 'nextHearing', 'actions'] as const).map((column) => (
                                                <th key={column} scope="col" className={tableHeaderCellClassName}>{label(`table.${column}`)}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className={tableBodyClassName}>
                                        {summary.cases.map((courtCase) => (
                                            <tr key={courtCase.id} className={tableRowClassName}>
                                                <td className={`${tableCellClassName} font-mono text-sm`}>{courtCase.case_number}</td>
                                                <td className={tableCellClassName}>{courtCase.title}</td>
                                                <td className={tableCellClassName}>{courtCase.case_type ?? '—'}</td>
                                                <td className={tableCellClassName}><StatusBadge status={courtCase.status} label={label(`status.${courtCase.status}`)} /></td>
                                                <td className={tableCellClassName}><StatusBadge status={courtCase.review_status} label={label(`review.${courtCase.review_status}`)} /></td>
                                                <td className={tableCellClassName}><LocalizedDateDisplay value={courtCase.filed_at} /></td>
                                                <td className={tableCellClassName}>
                                                    {courtCase.next_hearing_at ? <LocalizedDateDisplay value={courtCase.next_hearing_at} withTime /> : <span className="text-(--app-muted-foreground)">{label('noHearing')}</span>}
                                                </td>
                                                <td className={tableCellClassName}>{openButton(`case:${courtCase.id}`, label('openCase'))}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
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
