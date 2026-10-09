import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormField } from '@euisis/ui';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { inputCls, SelectOptions } from '@/Components/Cafeteria/PolicyUi';

type Option = { id: string; code: string; name_en: string; name_am: string | null };
type Assignment = {
    id: string; organization_id: string; provider_id: string; cafeteria_service_network_id: string | null; cafeteria_id: string | null;
    effective_from: string; effective_to: string | null; notes: string | null; status: string;
};

export default function AssignmentForm({ assignment, organizations, providers, networks, cafeterias, defaults }: {
    assignment: Assignment | null;
    organizations: Option[];
    providers: Array<{ id: string; provider_code: string; name_en: string; name_am: string | null }>;
    networks: Array<Option & { provider_id: string }>;
    cafeterias: Array<Option & { provider_id: string | null; cafeteria_service_network_id: string | null }>;
    defaults: { effective_from?: string };
}) {
    const { t } = useLocale();
    const tt = (key: string) => t(`cafeteriaPolicy.${key}`);
    const form = useForm({
        organization_id: assignment?.organization_id ?? '',
        provider_id: assignment?.provider_id ?? '',
        cafeteria_service_network_id: assignment?.cafeteria_service_network_id ?? '',
        cafeteria_id: assignment?.cafeteria_id ?? '',
        effective_from: assignment?.effective_from ?? defaults.effective_from ?? '',
        effective_to: assignment?.effective_to ?? '',
        notes: assignment?.notes ?? '',
    });
    const providerNetworks = networks.filter((n) => n.provider_id === form.data.provider_id);
    const networkCafeterias = cafeterias.filter((c) => c.provider_id === form.data.provider_id
        && (!form.data.cafeteria_service_network_id || c.cafeteria_service_network_id === form.data.cafeteria_service_network_id));

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => {
            const payload: Record<string, unknown> = { ...data, cafeteria_service_network_id: data.cafeteria_service_network_id || null, cafeteria_id: data.cafeteria_id || null, effective_to: data.effective_to || null };
            if (assignment) delete payload.organization_id;
            return payload;
        });
        if (assignment) form.patch(route('cafeteria.assignments.update', assignment.id)); else form.post(route('cafeteria.assignments.store'));
    }

    const title = assignment ? tt('editAssignment') : tt('createAssignment');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={tt('assignmentsDescription')} backHref={route('cafeteria.assignments.index')} />}>
            <Head title={title} />
            <form onSubmit={submit} className="space-y-6">
                <FormSection title={tt('scope')}>
                    <FormField label={tt('organization')} required error={form.errors.organization_id}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={form.data.organization_id} disabled={Boolean(assignment)} required onChange={(e) => form.setData('organization_id', e.target.value)}>
                                <SelectOptions items={organizations} placeholder={tt('selectPlaceholder')} />
                            </select>
                        )}
                    </FormField>
                    <FormField label={tt('provider')} required error={form.errors.provider_id}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={form.data.provider_id} required
                                onChange={(e) => form.setData((d) => ({ ...d, provider_id: e.target.value, cafeteria_service_network_id: '', cafeteria_id: '' }))}>
                                <SelectOptions items={providers.map((p) => ({ ...p, code: p.provider_code }))} placeholder={tt('selectPlaceholder')} />
                            </select>
                        )}
                    </FormField>
                    <FormField label={tt('network')} error={form.errors.cafeteria_service_network_id}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={form.data.cafeteria_service_network_id}
                                onChange={(e) => form.setData((d) => ({ ...d, cafeteria_service_network_id: e.target.value, cafeteria_id: '' }))}>
                                <SelectOptions items={providerNetworks} placeholder={tt('scopeProvider')} />
                            </select>
                        )}
                    </FormField>
                    <FormField label={tt('cafeteria')} error={form.errors.cafeteria_id}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={form.data.cafeteria_id} onChange={(e) => form.setData('cafeteria_id', e.target.value)}>
                                <SelectOptions items={networkCafeterias} placeholder={form.data.cafeteria_service_network_id ? tt('scopeNetwork') : tt('scopeProvider')} />
                            </select>
                        )}
                    </FormField>
                </FormSection>

                <FormSection title={tt('sectionPeriod')}>
                    <FormField label={tt('effectiveFrom')} required error={form.errors.effective_from}>
                        {({ id }) => <LocalizedDatePicker id={id} className={inputCls} value={form.data.effective_from} onChange={(v) => form.setData('effective_from', v)} />}
                    </FormField>
                    <FormField label={tt('effectiveTo')} error={form.errors.effective_to}>
                        {({ id }) => <LocalizedDatePicker id={id} className={inputCls} value={form.data.effective_to} onChange={(v) => form.setData('effective_to', v)} />}
                    </FormField>
                    <FormField label={tt('notes')} error={form.errors.notes} className="md:col-span-2">
                        {({ id }) => <textarea id={id} rows={2} className={inputCls} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />}
                    </FormField>
                </FormSection>

                <div className="flex justify-end gap-3">
                    <Link href={route('cafeteria.assignments.index')} className="rounded-[var(--radius-control)] border border-[color:var(--app-border)] px-4 py-2 text-sm font-medium">{tt('cancel')}</Link>
                    <button type="submit" disabled={form.processing} className="rounded-[var(--radius-control)] bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{tt('save')}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
