import { useState } from 'react';
import { toast } from 'sonner';
import { api, fieldErrors, firstError } from '../api';
import { useAuth } from '../auth';
import ListPagination from '../components/ListPagination';
import { CreditCard } from '../components/NavIcons';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useLocale } from '../i18n';
import AuthenticatedLayout from '../layouts/AuthenticatedLayout';
import { formatDate } from '../lib/format';
import { useList } from '../lib/useList';
import { Link } from '../router';
import type { IdCard } from '../types';
import { Alert, AppDialog, Button, Card, EmptyState, FilterField, FilterPanel, FormField, Input, Table, TBody, THead, Td, Textarea, Th, Tr, actionLinkClassName, selectClassName } from '../ui';

export type Transition = 'suspend' | 'reinstate' | 'revoke';

/** Which change each status allows; the API enforces the same table (ChangeCardStatus). */
export const ALLOWED: Record<string, Transition[]> = {
    active: ['suspend', 'revoke'],
    issued: ['suspend', 'revoke'],
    suspended: ['reinstate', 'revoke'],
    printed: ['revoke'],
    pending_print: ['revoke'],
};

const STATUSES = ['active', 'issued', 'printed', 'pending_print', 'suspended', 'revoked'];

export default function CardsPage() {
    const { t } = useLocale();
    const { user } = useAuth();
    const list = useList<IdCard>('/v1/id-cards');
    const [form, setForm] = useState({ search: '', status: '' });
    const [pending, setPending] = useState<{ card: IdCard; transition: Transition } | null>(null);
    const hasFilters = Boolean(list.filters.search || list.filters.status);
    const canChange = Boolean(user?.can_change_master_data);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.cards.title')} description={t('platform.cards.description')} />}>
            <div className="space-y-4">
                <FilterPanel label={t('platform.cards.title')} onSubmit={() => list.apply(form)} onReset={() => { setForm({ search: '', status: '' }); list.apply({}); }} hasActiveFilters={hasFilters}>
                    <FilterField label={t('platform.filters.search')} htmlFor="card-search" wide>
                        <Input id="card-search" type="search" placeholder={t('platform.cards.searchPlaceholder')} value={form.search} onChange={(e) => setForm({ ...form, search: e.target.value })} />
                    </FilterField>
                    <FilterField label={t('platform.filters.status')} htmlFor="card-status">
                        <select id="card-status" className={selectClassName} value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                            <option value="">{t('platform.filters.anyStatus')}</option>
                            {STATUSES.map((status) => <option key={status} value={status}>{t(`platform.status.${status}`)}</option>)}
                        </select>
                    </FilterField>
                </FilterPanel>

                {list.error && <Alert tone="danger">{t('platform.error')}</Alert>}
                <Card className={`overflow-hidden p-0 ${list.loading ? 'opacity-70' : ''}`}>
                    {list.result && list.result.data.length === 0 ? (
                        <EmptyState icon={<CreditCard className="h-8 w-8" />} title={hasFilters ? t('platform.cards.noMatch') : t('platform.cards.empty')} />
                    ) : list.result ? (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('platform.cards.number')}</Th>
                                    <Th>{t('platform.cards.holder')}</Th>
                                    <Th>{t('platform.cards.issued')}</Th>
                                    <Th>{t('platform.cards.expires')}</Th>
                                    <Th>{t('common.status')}</Th>
                                    {canChange && <Th align="right">{t('common.actions')}</Th>}
                                </Tr>
                            </THead>
                            <TBody>
                                {list.result.data.map((row) => (
                                    <Tr key={row.id}>
                                        <Td className="font-mono text-xs">{row.card_number}</Td>
                                        <Td>
                                            <Link href={`/employees/${row.employee_id}`} className="font-medium text-(--app-foreground) hover:text-primary">{row.employee?.full_name ?? '—'}</Link>
                                            <div className="font-mono text-xs text-(--app-muted-foreground)">{row.employee?.employee_number}</div>
                                        </Td>
                                        <Td muted>{formatDate(row.issued_at)}</Td>
                                        <Td muted>{formatDate(row.expires_at)}</Td>
                                        <Td><StatusBadge status={row.status} /></Td>
                                        {canChange && (
                                            <Td align="right">
                                                <span className="inline-flex flex-wrap justify-end gap-3">
                                                    {(ALLOWED[row.status] ?? []).map((transition) => (
                                                        <button key={transition} type="button" onClick={() => setPending({ card: row, transition })}
                                                            className={actionLinkClassName({ tone: transition === 'reinstate' ? 'success' : transition === 'revoke' ? 'danger' : 'primary' })}>
                                                            {t(`platform.cards.${transition}`)}
                                                        </button>
                                                    ))}
                                                </span>
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

            <CardChangeDialog
                pending={pending}
                onClose={() => setPending(null)}
                onDone={() => { setPending(null); toast.success(t('platform.cards.done')); list.reload(); }}
            />
        </AuthenticatedLayout>
    );
}

/** Suspend, reinstate or revoke, with the reason the audit log keeps. */
export function CardChangeDialog({ pending, onClose, onDone }: { pending: { card: IdCard; transition: Transition } | null; onClose: () => void; onDone: () => void }) {
    const { t } = useLocale();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const action = pending ? t(`platform.cards.${pending.transition}`) : '';

    async function submit() {
        if (!pending) return;
        setBusy(true);
        setError(null);
        try {
            await api(`/v1/id-cards/${pending.card.id}/${pending.transition}`, { method: 'POST', body: { reason } });
            setReason('');
            onDone();
        } catch (caught) {
            setError(fieldErrors(caught).reason ?? firstError(caught) ?? t('platform.error'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <AppDialog
            open={pending !== null}
            onClose={() => { if (!busy) { setReason(''); setError(null); onClose(); } }}
            title={pending ? t('platform.cards.confirmTitle', { action, number: pending.card.card_number }) : ''}
            footer={(
                <>
                    <Button variant="outline" onClick={() => { setReason(''); setError(null); onClose(); }} disabled={busy}>{t('common.cancel')}</Button>
                    <Button variant={pending?.transition === 'reinstate' ? 'primary' : 'destructive'} onClick={() => void submit()} loading={busy} disabled={reason.trim().length < 5}>{action}</Button>
                </>
            )}
        >
            <FormField label={t('platform.employee.reason')} required description={t('platform.cards.reasonHelp')} error={error}>
                {({ id, describedBy, invalid }) => (
                    <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={reason} maxLength={1000} onChange={(e) => setReason(e.target.value)} />
                )}
            </FormField>
        </AppDialog>
    );
}
