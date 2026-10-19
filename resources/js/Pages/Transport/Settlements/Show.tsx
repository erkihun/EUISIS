import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';
import { Alert, Card, TableCard, buttonClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@euisis/ui';
import { useTransportLabels } from '@/Components/transport/labels';
import { ActionDialogButton, DateText, Field, Kpi, Money, Pill } from '@/Components/transport/TransportShared';
import { DISPUTE_TYPES, type Reconciliation, type RouteRef, type TransportSettlement } from '@/types/transport';

/** Reconciliation: claimed vs verified vs calculated vs adjusted vs approved, each kept, none overwritten. */
export default function Show({ settlement, reconciliation, routes, can }: { settlement: TransportSettlement; reconciliation: Reconciliation; routes: RouteRef[]; can: { manage: boolean; approve: boolean; isComputer: boolean } }) {
    const { t, name, label } = useTransportLabels();
    const s = settlement;
    const cur = s.currency_code;
    const transition = (action: string) => route('transport.settlements.transition', s.id);
    const routeName = (id: string) => { const r = routes.find((x) => x.id === id); return r ? `${r.route_code} — ${name(r)}` : id; };
    const final = ['approved', 'paid', 'rejected', 'cancelled'].includes(s.status);

    return (
        <AuthenticatedLayout header={<PageHeader title={`${t('transport.statement')} ${s.settlement_number}`} description={`${name(s.provider)} · ${s.contract_snapshot?.contract_reference ?? ''} v${s.contract_snapshot?.version_no ?? ''} · ${label('settlementModels', s.contract_snapshot?.settlement_model)}`} backHref={route('transport.settlements.index')}
            actions={<div className="flex flex-wrap gap-2">
                <a href={route('transport.settlements.pdf', s.id)} className={buttonClassName({ variant: 'outline' })}>{t('transport.statementPdf')}</a>
                {can.manage && s.status === 'draft' && <ActionDialogButton label={t('transport.recompute')} size="default" url={route('transport.settlements.recompute', s.id)} />}
                {can.manage && s.status === 'draft' && <ActionDialogButton label={t('transport.submitForReview')} variant="primary" size="default" url={transition('submit')} data={{ action: 'submit' }} />}
                {can.manage && s.status === 'under_review' && <ActionDialogButton label={t('transport.returnToDraft')} size="default" url={transition('return')} data={{ action: 'return' }} />}
                {can.approve && s.status === 'under_review' && <ActionDialogButton label={t('transport.approveStatement')} title={t('transport.approveStatement')} description={can.isComputer ? t('transport.settlementDistinctApprover') : t('transport.approveStatementHelp')} variant="primary" size="default" url={transition('approve')} data={{ action: 'approve' }}
                    fields={[...(s.calculated_amount === null ? [{ name: 'approved_amount', label: t('transport.approvedAmount'), type: 'number' as const, required: true, description: t('transport.approvedAmountManualHelp') }] : []), { name: 'notes', label: t('transport.notes'), type: 'textarea' as const }]} />}
                {can.approve && s.status === 'under_review' && <ActionDialogButton label={t('transport.rejectStatement')} title={t('transport.rejectStatement')} destructive size="default" url={transition('reject')} data={{ action: 'reject' }} fields={[{ name: 'reason', label: t('transport.reason'), type: 'textarea', required: true }]} />}
                {can.manage && ['draft', 'under_review'].includes(s.status) && <ActionDialogButton label={t('transport.cancel')} title={t('transport.cancelStatement')} description={t('transport.cancelStatementHelp')} destructive size="default" url={transition('cancel')} data={{ action: 'cancel' }} />}
                {can.approve && s.status === 'approved' && <ActionDialogButton label={t('transport.recordPayment')} title={t('transport.recordPayment')} description={t('transport.recordPaymentHelp')} variant="primary" size="default" url={transition('pay')} data={{ action: 'pay' }} fields={[{ name: 'payment_reference', label: t('transport.paymentReference'), required: true }]} />}
            </div>} />}>
            <Head title={s.settlement_number ?? ''} />
            <div className="space-y-6">
                {s.policy_gaps.length > 0 && <Alert tone="warning" title={t('transport.policyGapsTitle')}>{s.policy_gaps.map((g) => label('dataQuality', g) || g).join(' · ')}</Alert>}
                {s.rejected_reason && <Alert tone="danger" title={t('transport.statuses.rejected')}>{s.rejected_reason}</Alert>}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
                    <Kpi label={t('transport.claimedAmount')} value={reconciliation.claimed_amount ?? '—'} hint={cur} />
                    <Kpi label={t('transport.calculatedAmount')} value={reconciliation.calculated_amount ?? '—'} hint={cur} />
                    <Kpi label={t('transport.difference')} value={reconciliation.difference ?? '—'} hint={cur} tone={reconciliation.difference && reconciliation.difference !== '0.00' ? 'warning' : undefined} />
                    <Kpi label={t('transport.adjustments')} value={reconciliation.adjustment_total} hint={cur} />
                    <Kpi label={t('transport.approvedAmount')} value={reconciliation.approved_amount ?? '—'} hint={cur} tone="success" />
                    <Kpi label={t('transport.disputes')} value={reconciliation.disputed} tone={reconciliation.disputed > 0 ? 'warning' : undefined} />
                </div>
                <Card>
                    <dl className="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <Field label={t('transport.status')}><Pill value={s.status} /></Field>
                        <Field label={t('transport.period')}><DateText value={s.period_start} /> – <DateText value={s.period_end} /></Field>
                        <Field label={t('transport.invoice')}>{s.invoice ? <Link href={route('transport.invoices.show', s.invoice.id)} className="underline-offset-2 hover:underline">{s.invoice.invoice_number}</Link> : '—'}</Field>
                        <Field label={t('transport.paymentReference')}>{s.payment_reference ?? '—'}</Field>
                        <Field label={t('transport.computedBy')}>{s.computed_by_name ?? '—'}{s.computed_at && <span className="block text-xs text-(--app-muted-foreground)"><DateText value={s.computed_at} withTime /></span>}</Field>
                        <Field label={t('transport.reviewedBy')}>{s.reviewed_by_name ?? '—'}</Field>
                        <Field label={t('transport.approvedBy')}>{s.approved_by_name ?? '—'}{s.approved_at && <span className="block text-xs text-(--app-muted-foreground)"><DateText value={s.approved_at} withTime /></span>}</Field>
                        <Field label={t('transport.notes')}>{s.notes ?? '—'}</Field>
                    </dl>
                </Card>
                <TableCard title={t('transport.reconciliation')}>
                    <table className={tableClassName}>
                        <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.unit')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.claimed')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.verified')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.difference')}</th></tr></thead>
                        <tbody className={tableBodyClassName}>
                            <tr className={tableRowClassName}><td className={tableCellClassName}>{t('transport.boardings')}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{reconciliation.claimed_boardings ?? '—'}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{reconciliation.verified_boardings}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{reconciliation.boardings_difference ?? '—'}</td></tr>
                            <tr className={tableRowClassName}><td className={tableCellClassName}>{t('transport.employeeDays')}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{reconciliation.claimed_employee_days ?? '—'}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{reconciliation.employee_days}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{reconciliation.claimed_employee_days !== null ? reconciliation.claimed_employee_days - reconciliation.employee_days : '—'}</td></tr>
                            <tr className={tableRowClassName}><td className={tableCellClassName}>{t('transport.completedTrips')}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{reconciliation.claimed_trips ?? '—'}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{reconciliation.completed_trips}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{reconciliation.claimed_trips !== null ? reconciliation.claimed_trips - reconciliation.completed_trips : '—'}</td></tr>
                            <tr className={tableRowClassName}><td className={tableCellClassName}>{t('transport.uniqueRiders')}</td><td className={`${tableCellClassName} text-right`}>—</td><td className={`${tableCellClassName} text-right tabular-nums`}>{s.unique_riders}</td><td className={tableCellClassName} /></tr>
                            <tr className={tableRowClassName}><td className={tableCellClassName}>{t('transport.cancelledTrips')}</td><td className={`${tableCellClassName} text-right`}>—</td><td className={`${tableCellClassName} text-right tabular-nums`}>{s.cancelled_trips}</td><td className={tableCellClassName} /></tr>
                        </tbody>
                    </table>
                    {s.basis?.data_quality && (s.basis.data_quality.boardings_on_uncompleted_trips || s.basis.data_quality.offline_pending_boardings) ? <p className="border-t border-(--app-border) p-4 text-sm text-amber-700">{t('transport.basisDataQuality')}: {t('transport.dataQuality.boardings_on_uncompleted_trips')} {s.basis.data_quality.boardings_on_uncompleted_trips ?? 0} · {t('transport.dataQuality.offline_pending_boardings')} {s.basis.data_quality.offline_pending_boardings ?? 0}</p> : null}
                </TableCard>
                <TableCard title={t('transport.calculation')}>
                    {(s.basis?.lines ?? []).length === 0 ? <p className="p-4 text-sm text-amber-700">{t('transport.statementNoAmount')}</p> : (
                        <table className={tableClassName}>
                            <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.line')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.quantity')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.rate')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.amount')}</th></tr></thead>
                            <tbody className={tableBodyClassName}>{(s.basis?.lines ?? []).map((line, i) => <tr key={i} className={tableRowClassName}><td className={tableCellClassName}>{t(`transport.lines.${line.label}`)}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{line.quantity ?? '—'}</td><td className={`${tableCellClassName} text-right`}>{line.rate ? <Money value={line.rate} currency={cur} /> : '—'}</td><td className={`${tableCellClassName} text-right`}><Money value={line.amount} currency={cur} /></td></tr>)}</tbody>
                        </table>
                    )}
                </TableCard>
                <TableCard title={t('transport.adjustmentsAndDisputes')} actions={can.manage && !final ? <ActionDialogButton label={t('transport.recordAdjustment')} title={t('transport.recordAdjustment')} description={t('transport.recordAdjustmentHelp')} variant="outline" url={route('transport.settlements.adjustments.store', s.id)}
                    fields={[{ name: 'amount', label: `${t('transport.amount')} (${cur})`, type: 'number', required: true, description: t('transport.adjustmentAmountHelp') }, { name: 'quantity', label: t('transport.quantity'), type: 'number' }, { name: 'reason', label: t('transport.reason'), type: 'textarea', required: true }]} /> : undefined}>
                    {(s.adjustments ?? []).length === 0 ? <p className="p-4 text-sm text-(--app-muted-foreground)">{t('transport.noAdjustments')}</p> : (
                        <table className={tableClassName}>
                            <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.origin')}</th><th className={tableHeaderCellClassName}>{t('transport.reason')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.amount')}</th><th className={tableHeaderCellClassName}>{t('transport.status')}</th><th className={tableHeaderCellClassName} /></tr></thead>
                            <tbody className={tableBodyClassName}>{(s.adjustments ?? []).map((a) => (
                                <tr key={a.id} className={tableRowClassName}>
                                    <td className={tableCellClassName}>{label('adjustmentOrigins', a.origin)}{a.dispute_type ? <div className="text-xs text-(--app-muted-foreground)">{label('disputeTypes', a.dispute_type)}</div> : null}</td>
                                    <td className={tableCellClassName}>{a.reason}{a.decision_notes ? <div className="text-xs text-(--app-muted-foreground)">{a.decision_notes}</div> : null}</td>
                                    <td className={`${tableCellClassName} text-right`}><Money value={a.amount} currency={cur} />{a.quantity ? <div className="text-xs text-(--app-muted-foreground)">× {a.quantity}</div> : null}</td>
                                    <td className={tableCellClassName}><Pill value={a.status} /></td>
                                    <td className={`${tableCellClassName} text-right whitespace-nowrap`}>
                                        {a.status === 'pending' && !final && (can.approve || can.manage) && (
                                            <>
                                                <ActionDialogButton label={t('transport.approve')} size="xs" variant="primary" url={route('transport.settlements.adjustments.decide', [s.id, a.id])} data={{ decision: 'approve' }} title={t('transport.approve')} fields={[{ name: 'notes', label: t('transport.notes'), type: 'textarea' }]} />{' '}
                                                <ActionDialogButton label={t('transport.reject')} size="xs" destructive url={route('transport.settlements.adjustments.decide', [s.id, a.id])} data={{ decision: 'reject' }} title={t('transport.reject')} fields={[{ name: 'notes', label: t('transport.notes'), type: 'textarea' }]} />
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}</tbody>
                        </table>
                    )}
                </TableCard>
                {(s.basis?.by_route ?? []).length > 0 && (
                    <TableCard title={t('transport.byRoute')}>
                        <table className={tableClassName}><thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.route')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.boardings')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.uniqueRiders')}</th></tr></thead>
                            <tbody className={tableBodyClassName}>{(s.basis?.by_route ?? []).map((r) => <tr key={r.route_id} className={tableRowClassName}><td className={tableCellClassName}>{routeName(r.route_id)}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{r.boardings}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{r.riders}</td></tr>)}</tbody></table>
                    </TableCard>
                )}
                <p className="text-xs text-(--app-muted-foreground)">{DISPUTE_TYPES.length ? t('transport.statementFooter') : ''}</p>
            </div>
        </AuthenticatedLayout>
    );
}
