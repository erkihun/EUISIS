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

export type Transition = 'print' | 'issue' | 'activate' | 'report_lost' | 'report_damaged' | 'suspend' | 'reinstate' | 'revoke' | 'replace';

/**
 * What each card status allows, as the API enforces it (ChangeCardStatus and
 * CardRequests::replace): pending print → printed → issued → active, the
 * reversible suspension, lost/damaged reports, final revocation, replacement.
 */
export const ALLOWED: Record<string, Transition[]> = {
    pending_print: ['print', 'revoke'],
    printed: ['issue', 'revoke'],
    issued: ['activate', 'suspend', 'report_lost', 'report_damaged', 'revoke'],
    active: ['suspend', 'report_lost', 'report_damaged', 'revoke'],
    suspended: ['reinstate', 'replace', 'revoke'],
    lost: ['replace', 'revoke'],
    damaged: ['replace', 'revoke'],
    revoked: ['replace'],
    expired: ['replace'],
};

/** Changes that need a written reason; the production steps and a replacement may carry one. */
const NEEDS_REASON: Transition[] = ['report_lost', 'report_damaged', 'suspend', 'reinstate', 'revoke'];

export function transitionTone(transition: Transition): 'primary' | 'success' | 'danger' {
    if (['reinstate', 'activate', 'issue', 'print'].includes(transition)) return 'success';
    if (['revoke', 'report_lost', 'report_damaged'].includes(transition)) return 'danger';
    return 'primary';
}

const STATUSES = ['pending_print', 'printed', 'issued', 'active', 'suspended', 'lost', 'damaged', 'replaced', 'expired', 'revoked'];

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
                                                <CardActions card={row} onChoose={(transition) => setPending({ card: row, transition })} />
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

            <CardChangeDialog pending={pending} onClose={() => setPending(null)} onDone={() => { setPending(null); list.reload(); }} />
        </AuthenticatedLayout>
    );
}

/** The changes a card's status allows, as inline actions. */
export function CardActions({ card, onChoose }: { card: IdCard; onChoose: (transition: Transition) => void }) {
    const { t } = useLocale();

    return (
        <span className="inline-flex flex-wrap justify-end gap-x-3 gap-y-1">
            {(ALLOWED[card.status] ?? []).map((transition) => (
                <button key={transition} type="button" onClick={() => onChoose(transition)} className={actionLinkClassName({ tone: transitionTone(transition) })}>
                    {t(`platform.cards.${transition}`)}
                </button>
            ))}
        </span>
    );
}

/** One card change with its reason (required where the change blocks or ends the card). */
export function CardChangeDialog({ pending, onClose, onDone }: { pending: { card: IdCard; transition: Transition } | null; onClose: () => void; onDone: () => void }) {
    const { t } = useLocale();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const action = pending ? t(`platform.cards.${pending.transition}`) : '';
    const required = pending !== null && NEEDS_REASON.includes(pending.transition);
    const close = () => { setReason(''); setError(null); onClose(); };

    async function submit() {
        if (!pending) return;
        setBusy(true);
        setError(null);
        try {
            const path = pending.transition === 'replace' ? `/v1/id-cards/${pending.card.id}/replace` : `/v1/id-cards/${pending.card.id}/${pending.transition}`;
            await api(path, { method: 'POST', body: { reason: reason.trim() === '' ? null : reason } });
            toast.success(pending.transition === 'replace' ? t('platform.cards.replaced') : t('platform.cards.done'));
            setReason('');
            onDone();
        } catch (caught) {
            const fields = fieldErrors(caught);
            setError(fields.reason ?? fields.card ?? firstError(caught) ?? t('platform.error'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <AppDialog
            open={pending !== null}
            onClose={() => { if (!busy) close(); }}
            title={pending ? t('platform.cards.confirmTitle', { action, number: pending.card.card_number }) : ''}
            description={pending?.transition === 'replace' ? t('platform.cards.replaceHelp') : undefined}
            footer={(
                <>
                    <Button variant="outline" onClick={close} disabled={busy}>{t('common.cancel')}</Button>
                    <Button variant={pending && transitionTone(pending.transition) === 'danger' ? 'destructive' : 'primary'} onClick={() => void submit()} loading={busy} disabled={required && reason.trim().length < 5}>{action}</Button>
                </>
            )}
        >
            <FormField label={required ? t('platform.employee.reason') : t('platform.cards.optionalReason')} required={required} description={required ? t('platform.cards.reasonHelp') : undefined} error={error}>
                {({ id, describedBy, invalid }) => (
                    <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={reason} maxLength={1000} onChange={(e) => setReason(e.target.value)} />
                )}
            </FormField>
        </AppDialog>
    );
}
