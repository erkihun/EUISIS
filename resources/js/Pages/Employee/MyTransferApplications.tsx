import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import TransferProgress from '@/Components/transfers/TransferProgress';
import TransferDocumentList from '@/Components/transfers/TransferDocumentList';
import type { ApprovalStatus, ApprovalType, TransferApplicationStatus } from '@/Components/transfers/types';
import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import { localizedName } from '@/utils/localizedName';
import { Alert, Button, Card, Input, Select, buttonClassName } from '@euisis/ui';
import { ListPagination, type LaravelPageMeta } from '@/Components/ui';
import type { PageProps } from '@/types';

type Application = {
    id: string;
    application_number: string | null;
    status: TransferApplicationStatus;
    submitted_at: string | null;
    applicant_notes: string | null;
    organization_name: string | null;
    organization_name_am: string | null;
    position_title: string | null;
    position_title_am: string | null;
    announcement_id: string;
    closing_date: string | null;
    rejected_reason: string | null;
    effective_date: string | null;
    documents: { id: string; document_type: string; original_name: string }[];
    approvals: { approval_type: ApprovalType | null; status: ApprovalStatus | null }[];
    can_withdraw: boolean;
};

type Props = PageProps & {
    applications: (LaravelPageMeta & { data: Application[] }) | null;
    has_employee: boolean;
    filters: { status?: string; search?: string };
};

const STATUSES: TransferApplicationStatus[] = [
    'submitted', 'under_review', 'selected', 'release_pending', 'receiving_pending', 'final_approval_pending',
    'approved', 'transferred', 'rejected', 'withdrawn',
];

export default function MyTransferApplications({ applications, has_employee, filters }: Props) {
    const { t, locale } = useLocale();
    // The applicant's wording: "Not selected" rather than "Rejected", "Awaiting release" rather than a stage code.
    const statusLabel = (status: string) => {
        const key = `employeePortal.applicationStatuses.${status}`;
        const label = t(key);

        return label !== key ? label : t(`status.${status}`);
    };
    const { confirm } = useConfirm();
    const { organizationName } = useDisplayFormat();
    const [search, setSearch] = useState(filters.search ?? '');
    const first = useRef(true);

    // Search as the employee types, once they pause, not on every keystroke.
    useEffect(() => {
        if (first.current) { first.current = false; return; }
        const timer = window.setTimeout(() => {
            router.get(route('employee.transfer-applications'), clean({ ...filters, search }), { preserveState: true, replace: true });
        }, 350);

        return () => window.clearTimeout(timer);
    }, [search]);

    async function withdraw(application: Application) {
        const { confirmed, reason } = await confirm({
            title: t('transfers.withdrawApplication'),
            description: t('transfers.withdrawReasonPrompt'),
            confirmLabel: t('transfers.withdrawApplication'),
            cancelLabel: t('common.cancel'),
            variant: 'warning',
            requireReason: true,
            reasonLabel: t('confirmations.reason'),
        });
        if (confirmed && reason?.trim()) {
            router.post(route('employee.transfer-applications.withdraw', { application: application.id }), { reason: reason.trim() }, { preserveScroll: true });
        }
    }

    const browse = (
        <Link href={route('employee.announcements')} className={buttonClassName({ variant: 'primary' })}>
            {t('employeePortal.browseAnnouncements')}
        </Link>
    );

    return (
        <PortalPage title={t('transfers.myApplications')} description={t('transfers.myApplicationsHint')} actions={browse}>
            {!has_employee ? (
                <Alert tone="warning">{t('transfers.noEmployeeProfile')}</Alert>
            ) : (
                <div className="space-y-4">
                    <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)]">
                        <label className="sr-only" htmlFor="application-search">{t('common.search')}</label>
                        <Input id="application-search" type="search" value={search} placeholder={t('transfers.searchMyApplications')} onChange={(e) => setSearch(e.target.value)} />
                        <label className="sr-only" htmlFor="application-status">{t('common.status')}</label>
                        <Select id="application-status" value={filters.status ?? ''}
                            onChange={(e) => router.get(route('employee.transfer-applications'), clean({ ...filters, search, status: e.target.value }), { preserveState: true, replace: true })}>
                            <option value="">{t('common.allStatuses')}</option>
                            {STATUSES.map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
                        </Select>
                    </div>

                    {!applications || applications.data.length === 0 ? (
                        <Card>
                            <EmptyState
                                title={t('transfers.noApplications')}
                                description={filters.status || filters.search ? t('common.noMatchingRecords') : t('transfers.noMyApplicationsHint')}
                                action={filters.status || filters.search ? undefined : browse}
                            />
                        </Card>
                    ) : (
                        <>
                            {applications.data.map((application) => (
                                <Card key={application.id} className="space-y-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h2 className="text-base font-semibold text-(--app-foreground)">
                                                {localizedName(application.position_title, application.position_title_am, locale) || '—'}
                                            </h2>
                                            <p className="text-sm text-(--app-muted-foreground)">
                                                {application.organization_name ? organizationName(application.organization_name, application.organization_name_am) : '—'}
                                            </p>
                                            <p className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-(--app-muted-foreground)">
                                                {application.application_number && <span>{t('transfers.applicationNumber')}: {application.application_number}</span>}
                                                {application.submitted_at && <span>{t('employeePortal.appliedOn')}: <LocalizedDateDisplay value={application.submitted_at} /></span>}
                                                {application.effective_date && <span>{t('transfers.effectiveDate')}: <LocalizedDateDisplay value={application.effective_date} /></span>}
                                            </p>
                                        </div>
                                        <StatusBadge status={application.status} label={statusLabel(application.status)} />
                                    </div>

                                    <TransferProgress status={application.status} approvals={application.approvals} statusLabel={statusLabel(application.status)} />

                                    {application.rejected_reason && (
                                        <Alert tone="danger" title={t('transfers.rejectedReason')}>{application.rejected_reason}</Alert>
                                    )}

                                    <details className="group rounded-control border border-(--app-border) px-4 py-3">
                                        <summary className="cursor-pointer text-sm font-medium text-(--app-foreground) marker:text-(--app-muted-foreground)">
                                            {t('transfers.whatYouSent')}
                                        </summary>
                                        <div className="mt-3 space-y-4">
                                            {application.applicant_notes && (
                                                <div>
                                                    <p className="text-xs font-medium text-(--app-muted-foreground)">{t('transfers.coverLetter')}</p>
                                                    <p className="mt-1 text-sm whitespace-pre-line wrap-break-word text-(--app-foreground)">{application.applicant_notes}</p>
                                                </div>
                                            )}
                                            <TransferDocumentList
                                                documents={application.documents}
                                                hrefFor={(document) => route('employee.transfer-application-documents.download', { document: document.id })}
                                            />
                                        </div>
                                    </details>

                                    <div className="flex flex-wrap justify-end gap-2">
                                        {/* The portal shows an announcement only while it is open. */}
                                        {application.closing_date && application.closing_date >= today() && (
                                            <Link href={route('employee.announcements.show', { announcement: application.announcement_id })} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>
                                                {t('transfers.viewAnnouncement')}
                                            </Link>
                                        )}
                                        {application.can_withdraw && (
                                            <Button variant="outline" size="sm" onClick={() => withdraw(application)}>{t('transfers.withdrawApplication')}</Button>
                                        )}
                                    </div>
                                </Card>
                            ))}
                            <ListPagination meta={applications} />
                        </>
                    )}
                </div>
            )}
        </PortalPage>
    );
}

/** Today as YYYY-MM-DD in the browser's own day. */
const today = () => {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
};

const clean = (values: Record<string, string | undefined>) => Object.fromEntries(Object.entries(values).filter(([, value]) => value));
