import { Link } from '@inertiajs/react';
import { Alert, Card, buttonClassName } from '@euisis/ui';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { useTransportLabels } from '@/Components/transport/labels';
import { ActionDialogButton, DateText, Field, Money, Pill } from '@/Components/transport/TransportShared';
import type { TransportInvoice, TransportSettlement } from '@/types/transport';

export default function Show({ invoice, settlement }: { invoice: TransportInvoice; settlement: TransportSettlement | null }) {
    const { t, label } = useTransportLabels();
    const editable = invoice.status === 'draft' || invoice.status === 'returned';
    const visibleSettlement = settlement && settlement.status !== 'draft' ? settlement : null;

    return (
        <TransportProviderLayout title={`${t('transport.invoice')} ${invoice.invoice_number}`}>
            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link href={route('provider.portal.transport.invoices.index')} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>← {t('transport.back')}</Link>
                    <div className="flex flex-wrap gap-2">
                        {editable && <Link href={route('provider.portal.transport.invoices.edit', invoice.id)} className={buttonClassName({ variant: 'outline' })}>{t('transport.edit')}</Link>}
                        {editable && <ActionDialogButton label={t('transport.submitInvoice')} title={t('transport.submitInvoice')} description={t('transport.submitInvoiceHelp')} variant="primary" size="default" url={route('provider.portal.transport.invoices.submit', invoice.id)} />}
                        {['submitted', 'under_review'].includes(invoice.status) && <ActionDialogButton label={t('transport.withdrawInvoice')} title={t('transport.withdrawInvoice')} description={t('transport.withdrawInvoiceHelp')} destructive size="default" url={route('provider.portal.transport.invoices.withdraw', invoice.id)} />}
                    </div>
                </div>
                {invoice.status === 'returned' && invoice.returned_reason && <Alert tone="warning" title={label('statuses', 'returned')}>{invoice.returned_reason}</Alert>}
                <Card>
                    <dl className="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <Field label={t('transport.status')}><Pill value={invoice.status} /></Field>
                        <Field label={t('transport.period')}><DateText value={invoice.period_start} /> – <DateText value={invoice.period_end} /></Field>
                        <Field label={t('transport.contract')}>{invoice.contract ? `${invoice.contract.contract_reference} v${invoice.contract.version_no}` : '—'}</Field>
                        <Field label={t('transport.submittedAt')}>{invoice.submitted_at ? <DateText value={invoice.submitted_at} withTime /> : '—'}</Field>
                        <Field label={t('transport.claimedAmount')}><Money value={invoice.claimed_amount} currency={invoice.currency_code} /></Field>
                        <Field label={t('transport.claimedBoardings')}>{invoice.claimed_boardings ?? '—'}</Field>
                        <Field label={t('transport.claimedTrips')}>{invoice.claimed_trips ?? '—'}</Field>
                        <Field label={t('transport.claimedEmployeeDays')}>{invoice.claimed_employee_days ?? '—'}</Field>
                    </dl>
                    {invoice.notes && <p className="mt-4 border-t border-(--app-border) pt-4 text-sm whitespace-pre-line">{invoice.notes}</p>}
                </Card>
                {visibleSettlement ? (
                    <Card className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-sm font-medium">{t('transport.statement')} {visibleSettlement.settlement_number} · <Pill value={visibleSettlement.status} /></p>
                            <p className="text-sm text-(--app-muted-foreground)">{t('transport.verifiedBoardings')}: {visibleSettlement.verified_boardings} · {t('transport.calculatedAmount')}: <Money value={visibleSettlement.calculated_amount} currency={visibleSettlement.currency_code} />{visibleSettlement.approved_amount ? <> · {t('transport.approvedAmount')}: <Money value={visibleSettlement.approved_amount} currency={visibleSettlement.currency_code} /></> : null}</p>
                        </div>
                        <Link href={route('provider.portal.transport.settlements.show', visibleSettlement.id)} className={buttonClassName({ variant: 'primary' })}>{t('transport.openReconciliation')}</Link>
                    </Card>
                ) : <Alert tone="info">{t('transport.noStatementYetPortal')}</Alert>}
            </div>
        </TransportProviderLayout>
    );
}
