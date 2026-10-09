import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormField } from '@euisis/ui';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { inputCls, SelectOptions } from '@/Components/Cafeteria/PolicyUi';

type Network = { id: string; provider_id: string; code: string; name_en: string; name_am: string | null; description: string | null; status: string };

export default function NetworkForm({ network, providers, defaults }: {
    network: Network | null;
    providers: Array<{ id: string; provider_code: string; name_en: string; name_am: string | null }>;
    defaults: { provider_id?: string };
}) {
    const { t } = useLocale();
    const tt = (key: string) => t(`cafeteriaPolicy.${key}`);
    const form = useForm({
        provider_id: network?.provider_id ?? defaults.provider_id ?? '',
        code: network?.code ?? '',
        name_en: network?.name_en ?? '',
        name_am: network?.name_am ?? '',
        description: network?.description ?? '',
        status: network?.status ?? 'active',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        if (network) {
            form.transform(({ provider_id: _p, code: _c, ...rest }) => rest);
            form.patch(route('cafeteria.networks.update', network.id));
        } else {
            form.post(route('cafeteria.networks.store'));
        }
    }

    const title = network ? tt('editNetwork') : tt('createNetwork');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={tt('networksDescription')} backHref={network ? route('cafeteria.networks.show', network.id) : route('cafeteria.networks.index')} />}>
            <Head title={title} />
            <form onSubmit={submit} className="space-y-6">
                <FormSection title={tt('network')}>
                    <FormField label={tt('provider')} required error={form.errors.provider_id}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={form.data.provider_id} disabled={Boolean(network)} required onChange={(e) => form.setData('provider_id', e.target.value)}>
                                <SelectOptions items={providers.map((p) => ({ ...p, code: p.provider_code }))} placeholder={tt('selectPlaceholder')} />
                            </select>
                        )}
                    </FormField>
                    <FormField label={tt('code')} required error={form.errors.code}>
                        {({ id }) => <input id={id} className={inputCls} value={form.data.code} disabled={Boolean(network)} required onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}
                    </FormField>
                    <FormField label={tt('nameEn')} required error={form.errors.name_en}>
                        {({ id }) => <input id={id} className={inputCls} value={form.data.name_en} required onChange={(e) => form.setData('name_en', e.target.value)} />}
                    </FormField>
                    <FormField label={tt('nameAm')} error={form.errors.name_am}>
                        {({ id }) => <input id={id} className={inputCls} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />}
                    </FormField>
                    <FormField label={tt('status')} error={form.errors.status}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                <option value="active">{t('cafeteriaPolicy.statuses.active')}</option>
                                <option value="inactive">{t('cafeteriaPolicy.statuses.inactive')}</option>
                            </select>
                        )}
                    </FormField>
                    <FormField label={tt('description')} error={form.errors.description} className="md:col-span-2">
                        {({ id }) => <textarea id={id} rows={3} className={inputCls} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />}
                    </FormField>
                </FormSection>
                <div className="flex justify-end gap-3">
                    <Link href={route('cafeteria.networks.index')} className="rounded-[var(--radius-control)] border border-[color:var(--app-border)] px-4 py-2 text-sm font-medium">{tt('cancel')}</Link>
                    <button type="submit" disabled={form.processing} className="rounded-[var(--radius-control)] bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{tt('save')}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
