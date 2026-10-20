import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';
import { Alert, Card, buttonClassName } from '@euisis/ui';
import { useTransportLabels } from '@/Components/transport/labels';
import { ActionDialogButton, DateText, Field, Money, Pill } from '@/Components/transport/TransportShared';
import type { TransportInvoice, TransportSettlement } from '@/types/transport';

export default function Show({ invoice, settlement, can }: { invoice: TransportInvoice & { submitted_by?: string | null }; settlement: TransportSettlement | null; contracts: unknown[]; can: { review: boolean; compute: boolean } }) {
    const { t, name } = useTransportLabels();

    return (
        <AuthenticatedLayout header={<PageHeader title={`${t('transport.invoice')} ${invoice.invoice_number}`} description={name(invoice.provider)} backHref={route('transport.invoices.index')}
            actions={<div className="flex flex-wrap gap-2">
                {can.review && invoice.status === 'submitted' && <ActionDialogButton label={t('transport.takeUnderReview')} variant="primary" size="default" url={route('transport.invoices.review', invoice.id)} />}
                {can.review && ['submitted', 'under_review', 'disputed'].includes(invoice.status) && <ActionDialogButton label={t('transport.returnToProvider')} title={t('transport.returnToProvider')} destructive size="default" url={route('transport.invoices.return', invoice.id)} fields={[{ name: 'reason', label: t('transport.reason'), type: 'textarea', required: true }]} />}
                {can.compute && !settlement && ['submitted', 'under_review'].includes(invoice.status) && <Link href={route('transport.settlements.create', { invoice_id: invoice.id })} className={buttonClassName({ variant: 'primary' })}>{t('transport.computeStatement')}</Link>}
            </div>} />}>
            <Head title={invoice.invoice_number} />
            <div className="space-y-6">
                {invoice.returned_reason && invoice.status === 'returned' && <Alert tone="warning" title={t('transport.statuses.returned')}>{invoice.returned_reason}</Alert>}
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
                {settlement ? (
                    <Card className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-sm font-medium">{t('transport.statement')} {settlement.settlement_number} · <Pill value={settlement.status} /></p>
                            <p className="text-sm text-(--app-muted-foreground)">{t('transport.verifiedBoardings')}: {settlement.verified_boardings} · {t('transport.calculatedAmount')}: <Money value={settlement.calculated_amount} currency={settlement.currency_code} /></p>
                        </div>
                        <Link href={route('transport.settlements.show', settlement.id)} className={buttonClassName({ variant: 'primary' })}>{t('transport.openReconciliation')}</Link>
                    </Card>
                ) : <Alert tone="info">{t('transport.noStatementYet')}</Alert>}
            </div>
        </AuthenticatedLayout>
    );
}
