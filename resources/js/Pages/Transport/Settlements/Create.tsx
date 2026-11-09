import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { Alert } from '@euisis/ui';
import type { FormEvent } from 'react';
import { useTransportLabels } from '@/Components/transport/labels';
import { FormShell, Section, SelectField, TextField, useProviderOptions } from '@/Components/transport/TransportForms';
import { Money } from '@/Components/transport/TransportShared';
import type { ProviderOption, TransportContract, TransportInvoice } from '@/types/transport';

export default function Create({ providers, contracts, invoice, defaultPeriod }: { providers: ProviderOption[]; contracts: TransportContract[]; invoice: TransportInvoice | null; defaultPeriod: { start: string; end: string } }) {
    const { t, label, name } = useTransportLabels();
    const providerOptions = useProviderOptions(providers);
    const form = useForm({
        provider_id: invoice?.provider_id ?? '',
        transport_contract_id: invoice?.transport_contract_id ?? '',
        transport_invoice_id: invoice?.id ?? '',
        period_start: invoice?.period_start ?? defaultPeriod.start,
        period_end: invoice?.period_end ?? defaultPeriod.end,
    });
    const own = contracts.filter((c) => c.provider_id === form.data.provider_id);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, transport_invoice_id: d.transport_invoice_id || null }));
        form.post(route('transport.settlements.store'));
    }

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('transport.computeStatement')} description={t('transport.computeStatementHelp')} backHref={route('transport.settlements.index')} />}>
            <Head title={t('transport.computeStatement')} />
            <div className="space-y-4">
                {invoice && <Alert tone="info" title={`${t('transport.invoice')} ${invoice.invoice_number}`}>{t('transport.claimedAmount')}: <Money value={invoice.claimed_amount} currency={invoice.currency_code} /> · {t('transport.claimedBoardings')}: {invoice.claimed_boardings ?? '—'}</Alert>}
                <FormShell onSubmit={submit} processing={form.processing} cancelHref={route('transport.settlements.index')} submitLabel={t('transport.computeStatement')}>
                    <Section>
                        <SelectField label={t('transport.provider')} required value={form.data.provider_id} onChange={(v) => form.setData((d) => ({ ...d, provider_id: v, transport_contract_id: '' }))} error={form.errors.provider_id} options={providerOptions} />
                        <SelectField label={t('transport.contract')} required value={form.data.transport_contract_id} onChange={(v) => form.setData('transport_contract_id', v)} error={form.errors.transport_contract_id}
                            options={[{ value: '', label: t('transport.selectContract') }, ...own.map((c) => ({ value: c.id, label: `${c.contract_reference} v${c.version_no} · ${label('settlementModels', c.settlement_model)} · ${c.organization ? name(c.organization) : t('transport.wholeCity')}` }))]} />
                        <TextField label={t('transport.periodStart')} type="date" required value={form.data.period_start} onChange={(v) => form.setData('period_start', v)} error={form.errors.period_start} />
                        <TextField label={t('transport.periodEnd')} type="date" required value={form.data.period_end} onChange={(v) => form.setData('period_end', v)} error={form.errors.period_end} />
                    </Section>
                </FormShell>
            </div>
        </AuthenticatedLayout>
    );
}
