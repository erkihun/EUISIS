import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';
import { Alert, Card } from '@euisis/ui';
import { useTransportLabels } from '@/Components/transport/labels';
import { ActionDialogButton, DateText, Field, Pill } from '@/Components/transport/TransportShared';
import type { TransportTransaction } from '@/types/transport';

export default function Show({ transaction, can }: { transaction: TransportTransaction & { scanned_by?: string | null }; can: { void: boolean } }) {
    const { t, name, label, locale } = useTransportLabels();
    const x = transaction;
    const snapshot = (x.eligibility_snapshot ?? {}) as Record<string, string | boolean | null>;

    return (
        <AuthenticatedLayout header={<PageHeader title={`${t('transport.boarding')} · ${x.employee?.full_name ?? ''}`} description={x.employee?.employee_number} backHref={route('transport.transactions.index')}
            actions={can.void ? <ActionDialogButton label={t('transport.voidBoarding')} title={t('transport.voidBoarding')} description={t('transport.voidBoardingHelp')} destructive size="default" url={route('transport.transactions.void', x.id)} fields={[{ name: 'reason', label: t('transport.reason'), type: 'textarea', required: true }]} /> : undefined} />}>
            <Head title={t('transport.boarding')} />
            <div className="space-y-6">
                {x.voided_at && <Alert tone="danger" title={t('transport.statuses.voided')}>{x.void_reason} · <DateText value={x.voided_at} withTime /></Alert>}
                {x.transport_settlement_id && <Alert tone="info">{t('transport.boardingSettled')}</Alert>}
                <Card>
                    <dl className="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <Field label={t('transport.status')}><Pill value={x.voided_at ? 'voided' : x.status} /></Field>
                        <Field label={t('transport.result')}>{label('reasons', x.result_code)}</Field>
                        <Field label={t('transport.scannedAt')}><DateText value={x.scanned_at} withTime /></Field>
                        <Field label={t('transport.method')}>{label('methods', x.verification_method)}{x.manual_reason ? <span className="block text-xs text-(--app-muted-foreground)">{x.manual_reason}</span> : null}</Field>
                        <Field label={t('transport.provider')}>{name(x.provider)}</Field>
                        <Field label={t('transport.route')}>{x.route ? `${x.route.route_code} — ${name(x.route)}` : '—'}</Field>
                        <Field label={t('transport.direction')}>{label('directions', x.direction)}</Field>
                        <Field label={t('transport.trip')}>{x.trip ? <Link href={route('transport.trips.show', x.trip.id)} className="underline-offset-2 hover:underline">{x.trip.trip_number}</Link> : '—'}</Field>
                        <Field label={t('transport.vehicle')}>{x.vehicle?.plate_number ?? '—'}</Field>
                        <Field label={t('transport.stop')}>{x.stop ? name(x.stop) : '—'}</Field>
                        <Field label={t('transport.device')}>{x.device?.device_code ?? '—'}</Field>
                        <Field label={t('transport.syncState')}>{label('syncStates', x.sync_state)}</Field>
                        <Field label={t('transport.organization')}>{locale === 'am' && x.employee?.organization_am ? x.employee.organization_am : x.employee?.organization ?? '—'}</Field>
                        <Field label={t('transport.eligibilityBasis')}>{snapshot.basis ? String(snapshot.basis) : '—'}{snapshot.alternative_route ? ` · ${t('transport.alternativeRoute')}` : ''}</Field>
                        <Field label={t('transport.cardStatusAtScan')}>{snapshot.card_status ? String(snapshot.card_status) : '—'}</Field>
                        <Field label={t('transport.employeeStatusAtScan')}>{snapshot.employee_status ? String(snapshot.employee_status) : '—'}</Field>
                    </dl>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
