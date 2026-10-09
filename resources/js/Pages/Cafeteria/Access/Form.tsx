import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button, FormField } from '@euisis/ui';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { inputCls, SelectOptions } from '@/Components/Cafeteria/PolicyUi';

type Option = { id: string; code: string; name_en: string; name_am: string | null };
type NetworkOption = Option & { provider_id: string; status: string };
type CafeteriaOption = Option & { cafeteria_service_network_id: string | null; location_type: string | null; is_active: boolean };
type Exception = { cafeteria_id: string; is_allowed: boolean; effective_from?: string | null; effective_to?: string | null };

type Access = {
    id: string; organization_id: string; cafeteria_service_network_id: string; primary_cafeteria_id: string | null;
    allow_cross_location_usage: boolean; effective_from: string; effective_to: string | null; notes: string | null;
    status: string; location_exceptions: Exception[];
};

export default function AccessForm({ access, organizations, networks, cafeterias, defaults }: {
    access: Access | null;
    organizations: Option[];
    networks: NetworkOption[];
    cafeterias: CafeteriaOption[];
    defaults: { cafeteria_service_network_id?: string; effective_from?: string };
}) {
    const { t } = useLocale();
    const tt = (key: string) => t(`cafeteriaPolicy.${key}`);
    const form = useForm({
        organization_id: access?.organization_id ?? '',
        cafeteria_service_network_id: access?.cafeteria_service_network_id ?? defaults.cafeteria_service_network_id ?? '',
        primary_cafeteria_id: access?.primary_cafeteria_id ?? '',
        allow_cross_location_usage: access?.allow_cross_location_usage ?? false,
        effective_from: access?.effective_from ?? defaults.effective_from ?? '',
        effective_to: access?.effective_to ?? '',
        notes: access?.notes ?? '',
        location_exceptions: (access?.location_exceptions ?? []) as Exception[],
    });
    const locations = cafeterias.filter((c) => c.cafeteria_service_network_id === form.data.cafeteria_service_network_id);
    const setException = (index: number, patch: Partial<Exception>) =>
        form.setData('location_exceptions', form.data.location_exceptions.map((e, i) => (i === index ? { ...e, ...patch } : e)));

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => {
            const payload: Record<string, unknown> = { ...data, primary_cafeteria_id: data.primary_cafeteria_id || null, effective_to: data.effective_to || null };
            if (access) { delete payload.organization_id; delete payload.cafeteria_service_network_id; }
            return payload;
        });
        if (access) form.patch(route('cafeteria.access.update', access.id)); else form.post(route('cafeteria.access.store'));
    }

    const title = access ? tt('editAccess') : tt('grantAccess');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={tt('accessDescription')} backHref={route('cafeteria.access.index')} />}>
            <Head title={title} />
            <form onSubmit={submit} className="space-y-6">
                <FormSection title={tt('scope')}>
                    <FormField label={tt('organization')} required error={form.errors.organization_id}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={form.data.organization_id} disabled={Boolean(access)} required onChange={(e) => form.setData('organization_id', e.target.value)}>
                                <SelectOptions items={organizations} placeholder={tt('selectPlaceholder')} />
                            </select>
                        )}
                    </FormField>
                    <FormField label={tt('network')} required error={form.errors.cafeteria_service_network_id}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={form.data.cafeteria_service_network_id} disabled={Boolean(access)} required
                                onChange={(e) => form.setData((d) => ({ ...d, cafeteria_service_network_id: e.target.value, primary_cafeteria_id: '', location_exceptions: [] }))}>
                                <SelectOptions items={networks} placeholder={tt('selectPlaceholder')} />
                            </select>
                        )}
                    </FormField>
                    <FormField label={tt('primaryCafeteria')} description={tt('primaryCafeteriaHelp')} error={form.errors.primary_cafeteria_id}>
                        {({ id, describedBy }) => (
                            <select id={id} aria-describedby={describedBy} className={inputCls} value={form.data.primary_cafeteria_id} onChange={(e) => form.setData('primary_cafeteria_id', e.target.value)}>
                                <SelectOptions items={locations} placeholder={tt('none')} />
                            </select>
                        )}
                    </FormField>
                    <FormField label={tt('crossLocationUsage')} description={tt('crossLocationHelp')} error={form.errors.allow_cross_location_usage}>
                        {({ id, describedBy }) => (
                            <label className="flex items-center gap-2 text-sm">
                                <input id={id} aria-describedby={describedBy} type="checkbox" checked={form.data.allow_cross_location_usage} onChange={(e) => form.setData('allow_cross_location_usage', e.target.checked)} />
                                {tt('crossLocationUsage')}
                            </label>
                        )}
                    </FormField>
                </FormSection>

                <FormSection title={tt('locationExceptions')} description={tt('locationExceptionsHelp')} grid={false}>
                    <div className="space-y-3">
                        {form.data.location_exceptions.map((exception, index) => (
                            <div key={index} className="flex flex-wrap items-end gap-3">
                                <label className="min-w-56 flex-1 space-y-1 text-sm">
                                    <span>{tt('cafeteria')}</span>
                                    <select className={inputCls} value={exception.cafeteria_id} onChange={(e) => setException(index, { cafeteria_id: e.target.value })}>
                                        <SelectOptions items={locations} placeholder={tt('selectPlaceholder')} />
                                    </select>
                                </label>
                                <label className="space-y-1 text-sm">
                                    <span>{tt('status')}</span>
                                    <select className={inputCls} value={exception.is_allowed ? '1' : '0'} onChange={(e) => setException(index, { is_allowed: e.target.value === '1' })}>
                                        <option value="1">{tt('allowed')}</option>
                                        <option value="0">{tt('excluded')}</option>
                                    </select>
                                </label>
                                <Button type="button" size="sm" variant="ghost" onClick={() => form.setData('location_exceptions', form.data.location_exceptions.filter((_, i) => i !== index))}>×</Button>
                            </div>
                        ))}
                        {form.errors.location_exceptions && <p className="text-xs text-red-600">{form.errors.location_exceptions}</p>}
                        <Button type="button" size="sm" variant="outline" disabled={!form.data.cafeteria_service_network_id}
                            onClick={() => form.setData('location_exceptions', [...form.data.location_exceptions, { cafeteria_id: '', is_allowed: true }])}>{tt('addException')}</Button>
                    </div>
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
                    <Link href={route('cafeteria.access.index')} className="rounded-[var(--radius-control)] border border-[color:var(--app-border)] px-4 py-2 text-sm font-medium">{tt('cancel')}</Link>
                    <button type="submit" disabled={form.processing} className="rounded-[var(--radius-control)] bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{tt('save')}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
