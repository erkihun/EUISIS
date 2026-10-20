import { Link } from '@inertiajs/react';
import { Alert, Card, TableCard, buttonClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@euisis/ui';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { useTransportLabels } from '@/Components/transport/labels';
import { ActionDialogButton, DateText, Field, Kpi, Money, Pill } from '@/Components/transport/TransportShared';
import { DISPUTE_TYPES, type Reconciliation, type RouteRef, type TransportSettlement } from '@/types/transport';

/** The city's statement as the provider reads it: verified usage, the amount, and the provider's own disputes. Nothing here is editable. */
export default function Show({ settlement, reconciliation, routes, canDispute }: { settlement: TransportSettlement; reconciliation: Reconciliation; routes: RouteRef[]; canDispute: boolean }) {
    const { t, name, label } = useTransportLabels();
    const s = settlement;
    const cur = s.currency_code;
    const routeName = (id: string) => { const r = routes.find((x) => x.id === id); return r ? `${r.route_code} — ${name(r)}` : id; };
    const num = `${tableCellClassName} text-right tabular-nums`;

    return (
        <TransportProviderLayout title={`${t('transport.statement')} ${s.settlement_number ?? ''}`}>
            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link href={route('provider.portal.transport.settlements.index')} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>← {t('transport.back')}</Link>
                    <div className="flex flex-wrap gap-2">
                        <a href={route('provider.portal.transport.settlements.pdf', s.id)} className={buttonClassName({ variant: 'outline' })}>{t('transport.statementPdf')}</a>
                        {canDispute && <ActionDialogButton label={t('transport.raiseDispute')} title={t('transport.raiseDispute')} description={t('transport.raiseDisputeHelp')} variant="primary" size="default" url={route('provider.portal.transport.settlements.disputes.store', s.id)}
                            fields={[{ name: 'dispute_type', label: t('transport.disputeType'), type: 'select', options: DISPUTE_TYPES.map((d) => ({ value: d, label: label('disputeTypes', d) })) }, { name: 'amount', label: `${t('transport.amount')} (${cur})`, type: 'number', required: true, description: t('transport.disputeAmountHelp') }, { name: 'quantity', label: t('transport.quantity'), type: 'number' }, { name: 'reason', label: t('transport.reason'), type: 'textarea', required: true }]} />}
                    </div>
                </div>
                {s.rejected_reason && <Alert tone="danger" title={label('statuses', 'rejected')}>{s.rejected_reason}</Alert>}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <Kpi label={t('transport.claimedAmount')} value={reconciliation.claimed_amount ?? '—'} hint={cur} />
                    <Kpi label={t('transport.calculatedAmount')} value={reconciliation.calculated_amount ?? '—'} hint={cur} />
                    <Kpi label={t('transport.difference')} value={reconciliation.difference ?? '—'} hint={cur} tone={reconciliation.difference && reconciliation.difference !== '0.00' ? 'warning' : undefined} />
                    <Kpi label={t('transport.adjustments')} value={reconciliation.adjustment_total} hint={cur} />
                    <Kpi label={t('transport.approvedAmount')} value={reconciliation.approved_amount ?? '—'} hint={cur} tone="success" />
                </div>
                <Card>
                    <dl className="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <Field label={t('transport.status')}><Pill value={s.status} /></Field>
                        <Field label={t('transport.period')}><DateText value={s.period_start} /> – <DateText value={s.period_end} /></Field>
                        <Field label={t('transport.contract')}>{s.contract_snapshot?.contract_reference ?? '—'} v{s.contract_snapshot?.version_no ?? ''}<span className="block text-xs text-(--app-muted-foreground)">{label('settlementModels', s.contract_snapshot?.settlement_model)}</span></Field>
                        <Field label={t('transport.invoice')}>{s.invoice ? <Link href={route('provider.portal.transport.invoices.show', s.invoice.id)} className="underline-offset-2 hover:underline">{s.invoice.invoice_number}</Link> : '—'}</Field>
                        <Field label={t('transport.paymentReference')}>{s.payment_reference ?? '—'}</Field>
                        <Field label={t('transport.approvedAt')}>{s.approved_at ? <DateText value={s.approved_at} withTime /> : '—'}</Field>
                    </dl>
                </Card>
                <TableCard title={t('transport.reconciliation')}>
                    <table className={tableClassName}>
                        <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.unit')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.claimed')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.verified')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.difference')}</th></tr></thead>
                        <tbody className={tableBodyClassName}>
                            <tr className={tableRowClassName}><td className={tableCellClassName}>{t('transport.boardings')}</td><td className={num}>{reconciliation.claimed_boardings ?? '—'}</td><td className={num}>{reconciliation.verified_boardings}</td><td className={num}>{reconciliation.boardings_difference ?? '—'}</td></tr>
                            <tr className={tableRowClassName}><td className={tableCellClassName}>{t('transport.employeeDays')}</td><td className={num}>{reconciliation.claimed_employee_days ?? '—'}</td><td className={num}>{reconciliation.employee_days}</td><td className={num}>{reconciliation.claimed_employee_days !== null ? reconciliation.claimed_employee_days - reconciliation.employee_days : '—'}</td></tr>
                            <tr className={tableRowClassName}><td className={tableCellClassName}>{t('transport.completedTrips')}</td><td className={num}>{reconciliation.claimed_trips ?? '—'}</td><td className={num}>{reconciliation.completed_trips}</td><td className={num}>{reconciliation.claimed_trips !== null ? reconciliation.claimed_trips - reconciliation.completed_trips : '—'}</td></tr>
                            <tr className={tableRowClassName}><td className={tableCellClassName}>{t('transport.uniqueRiders')}</td><td className={num}>—</td><td className={num}>{s.unique_riders}</td><td className={tableCellClassName} /></tr>
                        </tbody>
                    </table>
                </TableCard>
                <TableCard title={t('transport.calculation')}>
                    {(s.basis?.lines ?? []).length === 0 ? <p className="p-4 text-sm text-amber-700">{t('transport.statementNoAmount')}</p> : (
                        <table className={tableClassName}>
                            <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.line')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.quantity')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.rate')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.amount')}</th></tr></thead>
                            <tbody className={tableBodyClassName}>{(s.basis?.lines ?? []).map((line, i) => <tr key={i} className={tableRowClassName}><td className={tableCellClassName}>{t(`transport.lines.${line.label}`)}</td><td className={num}>{line.quantity ?? '—'}</td><td className={`${tableCellClassName} text-right`}>{line.rate ? <Money value={line.rate} currency={cur} /> : '—'}</td><td className={`${tableCellClassName} text-right`}><Money value={line.amount} currency={cur} /></td></tr>)}</tbody>
                        </table>
                    )}
                </TableCard>
                <TableCard title={t('transport.adjustmentsAndDisputes')}>
                    {(s.adjustments ?? []).length === 0 ? <p className="p-4 text-sm text-(--app-muted-foreground)">{t('transport.noAdjustments')}</p> : (
                        <table className={tableClassName}>
                            <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.origin')}</th><th className={tableHeaderCellClassName}>{t('transport.reason')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.amount')}</th><th className={tableHeaderCellClassName}>{t('transport.status')}</th></tr></thead>
                            <tbody className={tableBodyClassName}>{(s.adjustments ?? []).map((a) => <tr key={a.id} className={tableRowClassName}><td className={tableCellClassName}>{label('adjustmentOrigins', a.origin)}{a.dispute_type ? <div className="text-xs text-(--app-muted-foreground)">{label('disputeTypes', a.dispute_type)}</div> : null}</td><td className={tableCellClassName}>{a.reason}{a.decision_notes ? <div className="text-xs text-(--app-muted-foreground)">{a.decision_notes}</div> : null}</td><td className={`${tableCellClassName} text-right`}><Money value={a.amount} currency={cur} /></td><td className={tableCellClassName}><Pill value={a.status} /></td></tr>)}</tbody>
                        </table>
                    )}
                </TableCard>
                {(s.basis?.by_route ?? []).length > 0 && (
                    <TableCard title={t('transport.byRoute')}>
                        <table className={tableClassName}><thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.route')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.boardings')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.uniqueRiders')}</th></tr></thead>
                            <tbody className={tableBodyClassName}>{(s.basis?.by_route ?? []).map((r) => <tr key={r.route_id} className={tableRowClassName}><td className={tableCellClassName}>{routeName(r.route_id)}</td><td className={num}>{r.boardings}</td><td className={num}>{r.riders}</td></tr>)}</tbody></table>
                    </TableCard>
                )}
            </div>
        </TransportProviderLayout>
    );
}
