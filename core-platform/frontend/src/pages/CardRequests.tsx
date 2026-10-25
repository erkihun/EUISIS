import { useState } from 'react';
import { toast } from 'sonner';
import { api } from '../api';
import { useAuth } from '../auth';
import FormDialog from '../components/FormDialog';
import ListPagination from '../components/ListPagination';
import { CardReceiveIcon } from '../components/NavIcons';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useLocale } from '../i18n';
import AuthenticatedLayout from '../layouts/AuthenticatedLayout';
import { formatDateTime } from '../lib/format';
import { useList } from '../lib/useList';
import { Link } from '../router';
import { Alert, Card, EmptyState, FilterField, FilterPanel, Table, TBody, THead, Td, Th, Tr, actionLinkClassName, selectClassName } from '../ui';

type CardRequestRow = {
    id: string;
    employee_id: string;
    request_type: string | null;
    status: string;
    request_reason: string | null;
    requested_by: number;
    submitted_at: string | null;
    approved_at: string | null;
    rejection_reason: string | null;
    cancellation_reason: string | null;
    employee: { id: string; employee_number: string; full_name: string } | null;
};

const PENDING = ['draft', 'submitted', 'verified'];

/**
 * Card Requests: the queue of new and replacement card requests. Approval is
 * for someone other than the requester (the API refuses the requester), and
 * creates the card waiting to print; rejection and cancellation need a reason.
 */
export default function CardRequestsPage() {
    const { t } = useLocale();
    const { user } = useAuth();
    const list = useList<CardRequestRow>('/v1/card-requests', { status: 'pending' });
    const [status, setStatus] = useState('pending');
    const [deciding, setDeciding] = useState<{ row: CardRequestRow; decision: 'approve' | 'reject' | 'cancel' } | null>(null);
    const canDecide = Boolean(user?.can_change_master_data);
    const decisionTitle = deciding ? t(`platform.cardRequests.${deciding.decision}Title`) : '';

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.cardRequests.title')} description={t('platform.cardRequests.description')} />}>
            <div className="space-y-4">
                <FilterPanel label={t('platform.cardRequests.title')} onSubmit={() => list.apply({ status })} onReset={() => { setStatus('pending'); list.apply({ status: 'pending' }); }} hasActiveFilters={list.filters.status !== 'pending'}>
                    <FilterField label={t('common.status')} htmlFor="request-status">
                        <select id="request-status" className={selectClassName} value={status} onChange={(e) => setStatus(e.target.value)}>
                            <option value="pending">{t('platform.cardRequests.pending')}</option>
                            <option value="">{t('platform.cardRequests.all')}</option>
                            {['approved', 'rejected', 'cancelled'].map((value) => <option key={value} value={value}>{t(`platform.status.${value}`)}</option>)}
                        </select>
                    </FilterField>
                </FilterPanel>

                {list.error && <Alert tone="danger">{t('platform.error')}</Alert>}
                <Card className={`overflow-hidden p-0 ${list.loading ? 'opacity-70' : ''}`}>
                    {list.result && list.result.data.length === 0 ? (
                        <EmptyState icon={<CardReceiveIcon className="h-8 w-8" />} title={t('platform.cardRequests.empty')} />
                    ) : list.result ? (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('platform.cardRequests.employee')}</Th>
                                    <Th>{t('platform.cardRequests.type')}</Th>
                                    <Th>{t('platform.cardRequests.submitted')}</Th>
                                    <Th>{t('platform.cardRequests.reason')}</Th>
                                    <Th>{t('common.status')}</Th>
                                    {canDecide && <Th align="right">{t('common.actions')}</Th>}
                                </Tr>
                            </THead>
                            <TBody>
                                {list.result.data.map((row) => (
                                    <Tr key={row.id}>
                                        <Td>
                                            <Link href={`/employees/${row.employee_id}`} className="font-medium text-(--app-foreground) hover:text-primary">{row.employee?.full_name ?? '—'}</Link>
                                            <div className="font-mono text-xs text-(--app-muted-foreground)">{row.employee?.employee_number}</div>
                                        </Td>
                                        <Td>{row.request_type ? t(`platform.requestTypes.${row.request_type}`) : '—'}</Td>
                                        <Td muted className="whitespace-nowrap tabular-nums">{formatDateTime(row.submitted_at)}</Td>
                                        <Td muted>{row.rejection_reason ?? row.cancellation_reason ?? row.request_reason ?? '—'}</Td>
                                        <Td><StatusBadge status={row.status} /></Td>
                                        {canDecide && (
                                            <Td align="right">
                                                {PENDING.includes(row.status) && (
                                                    <span className="inline-flex flex-wrap justify-end gap-x-3 gap-y-1">
                                                        {row.requested_by !== user?.id && (
                                                            <button type="button" className={actionLinkClassName({ tone: 'success' })} onClick={() => setDeciding({ row, decision: 'approve' })}>{t('platform.cardRequests.approve')}</button>
                                                        )}
                                                        <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => setDeciding({ row, decision: 'reject' })}>{t('platform.cardRequests.reject')}</button>
                                                        <button type="button" className={actionLinkClassName({ tone: 'neutral' })} onClick={() => setDeciding({ row, decision: 'cancel' })}>{t('platform.cardRequests.cancel')}</button>
                                                    </span>
                                                )}
                                            </Td>
                                        )}
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    ) : (
                        <EmptyState title={t('common.loading')} />
                    )}
                    <ListPagination meta={list.result} onPageChange={list.setPage} disabled={list.loading} />
                </Card>
            </div>

            <FormDialog
                open={deciding !== null}
                title={decisionTitle}
                description={deciding?.decision === 'approve' ? t('platform.cardRequests.approveHelp') : undefined}
                fields={deciding?.decision === 'approve'
                    ? [{ name: 'notes', label: t('platform.cardRequests.notes'), type: 'textarea' }]
                    : [{ name: 'reason', label: t('platform.cardRequests.reason'), type: 'textarea', required: true, description: t('platform.cards.reasonHelp') }]}
                initial={{ notes: null, reason: null }}
                submitLabel={deciding ? t(`platform.cardRequests.${deciding.decision}`) : ''}
                destructive={deciding?.decision !== 'approve'}
                onClose={() => setDeciding(null)}
                onSubmit={async (values) => {
                    if (!deciding) return;
                    await api(`/v1/card-requests/${deciding.row.id}/${deciding.decision}`, { method: 'POST', body: values });
                    toast.success(deciding.decision === 'approve' ? t('platform.cardRequests.approved') : t('platform.cardRequests.closed'));
                    setDeciding(null);
                    list.reload();
                }}
            />
        </AuthenticatedLayout>
    );
}
