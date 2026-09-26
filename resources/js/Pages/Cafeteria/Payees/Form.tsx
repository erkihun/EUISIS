import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormField } from '@euisis/ui';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { inputCls } from '@/Components/Cafeteria/PolicyUi';

type Payee = {
    id: string; provider_code: string; name_en: string; name_am: string | null; contact_person: string | null;
    phone_number: string | null; email: string | null; address: string | null; status: string; settlement_details: string | null;
};

export default function PayeeForm({ provider }: { provider: Payee | null }) {
    const { t } = useLocale();
    const tt = (key: string) => t(`cafeteriaPolicy.${key}`);
    const form = useForm({
        provider_code: provider?.provider_code ?? '',
        name_en: provider?.name_en ?? '',
        name_am: provider?.name_am ?? '',
        contact_person: provider?.contact_person ?? '',
        phone_number: provider?.phone_number ?? '',
        email: provider?.email ?? '',
        address: provider?.address ?? '',
        status: provider?.status ?? 'active',
        settlement_details: provider?.settlement_details ?? '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        if (provider) {
            form.transform(({ provider_code: _code, ...rest }) => rest);
            form.patch(route('cafeteria.payees.update', provider.id));
        } else {
            form.post(route('cafeteria.payees.store'));
        }
    }

    const title = provider ? `${tt('edit')} · ${provider.name_en}` : `${tt('create')} · ${tt('provider')}`;
    const field = (key: keyof typeof form.data, text: string, props: Record<string, unknown> = {}) => (
        <FormField label={text} error={form.errors[key]} required={Boolean(props.required)}>
            {({ id }) => <input id={id} className={inputCls} value={String(form.data[key] ?? '')} onChange={(e) => form.setData(key, e.target.value)} {...props} />}
        </FormField>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('cafeteria.payees.index')} />}>
            <Head title={title} />
            <form onSubmit={submit} className="space-y-6">
                <FormSection title={tt('provider')}>
                    {field('provider_code', tt('providerCode'), { required: true, disabled: Boolean(provider) })}
                    <FormField label={tt('status')}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                <option value="active">{t('cafeteriaPolicy.statuses.active')}</option>
                                <option value="inactive">{t('cafeteriaPolicy.statuses.inactive')}</option>
                            </select>
                        )}
                    </FormField>
                    {field('name_en', tt('nameEn'), { required: true })}
                    {field('name_am', tt('nameAm'))}
                </FormSection>
                <FormSection title={tt('contactSection')}>
                    {field('contact_person', tt('contactPerson'))}
                    {field('phone_number', tt('phoneNumber'))}
                    {field('email', tt('email'), { type: 'email' })}
                    {field('address', tt('location'))}
                    <FormField label={tt('navSettlements')} error={form.errors.settlement_details} className="md:col-span-2">
                        {({ id }) => <textarea id={id} rows={3} className={inputCls} value={form.data.settlement_details} onChange={(e) => form.setData('settlement_details', e.target.value)} />}
                    </FormField>
                </FormSection>
                <div className="flex justify-end gap-3">
                    <Link href={route('cafeteria.payees.index')} className="rounded-[var(--radius-control)] border border-[color:var(--app-border)] px-4 py-2 text-sm font-medium">{tt('cancel')}</Link>
                    <button type="submit" disabled={form.processing} className="rounded-[var(--radius-control)] bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{tt('save')}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
