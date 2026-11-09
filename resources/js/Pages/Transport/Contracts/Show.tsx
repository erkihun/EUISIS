import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';
import { Alert, Card, TableCard, buttonClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@euisis/ui';
import { useTransportLabels } from '@/Components/transport/labels';
import { ActionDialogButton, DateText, Field, Money, Pill } from '@/Components/transport/TransportShared';
import type { TransportContract, TransportSettlement } from '@/types/transport';

export default function Show({ contract, versions, settlements, can }: { contract: TransportContract; versions: TransportContract[]; settlements: TransportSettlement[]; can: { update: boolean; approve: boolean; settlements: boolean } }) {
    const { t, name, label } = useTransportLabels();
    const title = `${contract.contract_reference} v${contract.version_no}`;

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={`${name(contract.provider)} · ${contract.organization ? name(contract.organization) : t('transport.wholeCity')}`} backHref={route('transport.contracts.index')}
            actions={<div className="flex flex-wrap gap-2">
                {contract.has_agreement && <a href={route('transport.contracts.agreement', contract.id)} className={buttonClassName({ variant: 'outline' })}>{t('transport.agreementFile')}</a>}
                {can.update && contract.status === 'draft' && <Link href={route('transport.contracts.edit', contract.id)} className={buttonClassName({ variant: 'outline' })}>{t('transport.edit')}</Link>}
                {can.update && contract.status === 'draft' && <ActionDialogButton label={t('transport.approveContract')} title={t('transport.approveContract')} description={can.approve ? t('transport.approveContractHelp') : t('transport.contractDistinctApprover')} variant="primary" size="default" url={route('transport.contracts.approve', contract.id)} />}
                {can.update && contract.status === 'draft' && <ActionDialogButton label={t('transport.cancel')} title={t('transport.cancelContract')} description={t('transport.cancelContractHelp')} destructive size="default" url={route('transport.contracts.cancel', contract.id)} />}
                {can.update && contract.status === 'active' && <ActionDialogButton label={t('transport.newVersion')} title={t('transport.newVersion')} description={t('transport.newVersionHelp')} variant="primary" size="default" url={route('transport.contracts.versions.store', contract.id)} />}
                {can.update && contract.status === 'active' && <ActionDialogButton label={t('transport.endContract')} title={t('transport.endContract')} destructive size="default" url={route('transport.contracts.end', contract.id)} fields={[{ name: 'effective_to', label: t('transport.validUntil'), type: 'date', required: true }]} />}
            </div>} />}>
            <Head title={title} />
            <div className="space-y-6">
                {contract.missing_terms.length > 0 && contract.settlement_model !== 'other_approved_model' && <Alert tone="warning" title={t('transport.termsMissingTitle')}>{t('transport.termsMissingHelp')}: {contract.missing_terms.map((k) => t(`transport.terms.${k}`)).join(', ')}</Alert>}
                {contract.settlement_model === 'other_approved_model' && <Alert tone="info">{t('transport.otherModelHelp')}</Alert>}
                <Card>
                    <dl className="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <Field label={t('transport.status')}><Pill value={contract.status} /></Field>
                        <Field label={t('transport.settlementModel')}>{label('settlementModels', contract.settlement_model)}</Field>
                        <Field label={t('transport.settlementPeriod')}>{label('settlementPeriods', contract.settlement_period)}</Field>
                        <Field label={t('transport.currency')}>{contract.currency_code}</Field>
                        <Field label={t('transport.validFrom')}><DateText value={contract.effective_from} /></Field>
                        <Field label={t('transport.validUntil')}>{contract.effective_to ? <DateText value={contract.effective_to} /> : '—'}</Field>
                        <Field label={t('transport.approvedAt')}>{contract.approved_at ? <DateText value={contract.approved_at} withTime /> : '—'}</Field>
                        <Field label={t('transport.termsReference')}>{contract.terms_reference ?? '—'}</Field>
                    </dl>
                </Card>
                <TableCard title={t('transport.settlementTerms')}>
                    <dl className="grid grid-cols-2 gap-4 p-4 md:grid-cols-3">
                        {Object.entries(contract.terms).length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{t('transport.noTerms')}</p> : Object.entries(contract.terms).map(([key, value]) => <Field key={key} label={t(`transport.terms.${key}`)}><Money value={value} currency={contract.currency_code} /></Field>)}
                    </dl>
                    {contract.service_coverage && <p className="border-t border-(--app-border) p-4 text-sm whitespace-pre-line">{contract.service_coverage}</p>}
                </TableCard>
                <TableCard title={t('transport.versions')}>
                    <table className={tableClassName}><thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.version')}</th><th className={tableHeaderCellClassName}>{t('transport.validity')}</th><th className={tableHeaderCellClassName}>{t('transport.status')}</th></tr></thead>
                        <tbody className={tableBodyClassName}>{versions.map((v) => <tr key={v.id} className={tableRowClassName}><td className={tableCellClassName}><Link href={route('transport.contracts.show', v.id)} className="underline-offset-2 hover:underline">v{v.version_no}</Link></td><td className={tableCellClassName}><DateText value={v.effective_from} /> – {v.effective_to ? <DateText value={v.effective_to} /> : '…'}</td><td className={tableCellClassName}><Pill value={v.status} /></td></tr>)}</tbody></table>
                </TableCard>
                {can.settlements && settlements.length > 0 && (
                    <TableCard title={t('transport.settlements')}>
                        <table className={tableClassName}><thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>{t('transport.statement')}</th><th className={tableHeaderCellClassName}>{t('transport.period')}</th><th className={`${tableHeaderCellClassName} text-right`}>{t('transport.calculatedAmount')}</th><th className={tableHeaderCellClassName}>{t('transport.status')}</th></tr></thead>
                            <tbody className={tableBodyClassName}>{settlements.map((s) => <tr key={s.id} className={tableRowClassName}><td className={tableCellClassName}><Link href={route('transport.settlements.show', s.id)} className="underline-offset-2 hover:underline">{s.settlement_number}</Link></td><td className={tableCellClassName}><DateText value={s.period_start} /> – <DateText value={s.period_end} /></td><td className={`${tableCellClassName} text-right`}><Money value={s.calculated_amount} currency={s.currency_code} /></td><td className={tableCellClassName}><Pill value={s.status} /></td></tr>)}</tbody></table>
                    </TableCard>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
