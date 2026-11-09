import { useForm } from '@inertiajs/react';
import { Alert, FormField, Input } from '@euisis/ui';
import type { FormEvent } from 'react';
import { useTransportLabels } from './labels';
import { FormShell, NotesField, Section, SelectField, TextField, s } from './TransportForms';
import type { TransportContract, TransportInvoice } from '@/types/transport';

/**
 * The provider's claim for a period. It states what the provider says it
 * delivered and asks for; the city's statement states what was verified.
 * The two meet in reconciliation, never on this form.
 */
export function InvoiceForm({ invoice, contracts, defaultPeriod }: { invoice?: TransportInvoice; contracts: TransportContract[]; defaultPeriod: { start: string; end: string } }) {
    const { t, label } = useTransportLabels();
    const form = useForm({
        invoice_number: invoice?.invoice_number ?? '',
        transport_contract_id: invoice?.transport_contract_id ?? (contracts.find((c) => c.status === 'active')?.id ?? ''),
        period_start: invoice?.period_start ?? defaultPeriod.start,
        period_end: invoice?.period_end ?? defaultPeriod.end,
        claimed_boardings: s(invoice?.claimed_boardings),
        claimed_trips: s(invoice?.claimed_trips),
        claimed_employee_days: s(invoice?.claimed_employee_days),
        claimed_amount: invoice?.claimed_amount ?? '',
        notes: invoice?.notes ?? '',
        attachment: null as File | null,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({
            ...d,
            invoice_number: d.invoice_number || null,
            transport_contract_id: d.transport_contract_id || null,
            claimed_boardings: d.claimed_boardings || null,
            claimed_trips: d.claimed_trips || null,
            claimed_employee_days: d.claimed_employee_days || null,
            ...(invoice ? { _method: 'patch' } : {}),
        }));
        // Always a POST: a file upload cannot ride a PATCH; the method is spoofed for an edit.
        form.post(invoice ? route('provider.portal.transport.invoices.update', invoice.id) : route('provider.portal.transport.invoices.store'), { forceFormData: true });
    }

    return (
        <FormShell onSubmit={submit} processing={form.processing} cancelHref={route('provider.portal.transport.invoices.index')} submitLabel={t('transport.saveDraft')}>
            <Alert tone="info">{t('transport.invoiceFormHelp')}</Alert>
            <Section title={t('transport.period')}>
                <SelectField label={t('transport.contract')} value={form.data.transport_contract_id} onChange={(v) => form.setData('transport_contract_id', v)} error={form.errors.transport_contract_id}
                    options={[{ value: '', label: t('transport.noContractSelected') }, ...contracts.map((c) => ({ value: c.id, label: `${c.contract_reference} v${c.version_no} · ${label('settlementModels', c.settlement_model)} · ${label('statuses', c.status)}` }))]} />
                <TextField label={t('transport.invoiceNumber')} value={form.data.invoice_number} onChange={(v) => form.setData('invoice_number', v)} error={form.errors.invoice_number} description={t('transport.invoiceNumberHelp')} />
                <TextField label={t('transport.periodStart')} type="date" required value={form.data.period_start} onChange={(v) => form.setData('period_start', v)} error={form.errors.period_start} />
                <TextField label={t('transport.periodEnd')} type="date" required value={form.data.period_end} onChange={(v) => form.setData('period_end', v)} error={form.errors.period_end} />
            </Section>
            <Section title={t('transport.claim')} description={t('transport.claimHelp')}>
                <TextField label={t('transport.claimedBoardings')} type="number" inputMode="numeric" value={form.data.claimed_boardings} onChange={(v) => form.setData('claimed_boardings', v)} error={form.errors.claimed_boardings} />
                <TextField label={t('transport.claimedTrips')} type="number" inputMode="numeric" value={form.data.claimed_trips} onChange={(v) => form.setData('claimed_trips', v)} error={form.errors.claimed_trips} />
                <TextField label={t('transport.claimedEmployeeDays')} type="number" inputMode="numeric" value={form.data.claimed_employee_days} onChange={(v) => form.setData('claimed_employee_days', v)} error={form.errors.claimed_employee_days} />
                <TextField label={`${t('transport.claimedAmount')} (ETB)`} type="number" inputMode="decimal" required value={form.data.claimed_amount} onChange={(v) => form.setData('claimed_amount', v)} error={form.errors.claimed_amount} />
                <FormField label={t('transport.attachment')} error={form.errors.attachment} description={t('transport.attachmentHelp')}>
                    {({ id }) => <Input id={id} type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => form.setData('attachment', e.target.files?.[0] ?? null)} />}
                </FormField>
                <NotesField value={form.data.notes} onChange={(v) => form.setData('notes', v)} error={form.errors.notes} />
            </Section>
        </FormShell>
    );
}
