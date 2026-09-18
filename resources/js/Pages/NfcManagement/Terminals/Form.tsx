import { FormEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputError from '@/Components/InputError';
import { filterInputCls } from '@/Components/ui';
import { useTerminalTypeLabel } from '@/Components/Nfc/NfcStatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import NfcSubNav, { useLocalizedName, type NfcCapabilities } from '../Partials/NfcSubNav';

type TerminalDetail = {
    id: string;
    terminal_code: string;
    name: string;
    terminal_type: string;
    service_type: string | null;
    status: string;
    provider_id: string | null;
    cafeteria_provider_id: string | null;
    organization_id: string | null;
    external_application_id: string | null;
    certificate_fingerprint: string | null;
};

type Props = {
    terminal: TerminalDetail | null;
    types: string[];
    statuses: string[];
    organizations: { id: string; name_en: string; name_am: string | null }[];
    providers: { id: string; name: string }[];
    cafeteriaProviders: { id: string; name_en: string }[];
    applications: { id: string; name: string }[];
    serviceTypes: string[];
    can: NfcCapabilities;
};

const fieldCls = `${filterInputCls} w-full`;

function Row({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-200">{label}</span>
            {children}
            <InputError message={error} className="mt-1" />
        </label>
    );
}

export default function NfcTerminalForm({
    terminal,
    types,
    statuses,
    organizations,
    providers,
    cafeteriaProviders,
    applications,
    serviceTypes,
    can,
}: Props) {
    const { t } = useLocale();
    const terminalTypeLabel = useTerminalTypeLabel();
    const localizedName = useLocalizedName();
    const isEdit = terminal !== null;

    const form = useForm({
        terminal_code: terminal?.terminal_code ?? '',
        name: terminal?.name ?? '',
        terminal_type: terminal?.terminal_type ?? 'verification',
        status: terminal?.status ?? 'active',
        service_type: terminal?.service_type ?? '',
        external_application_id: terminal?.external_application_id ?? '',
        provider_id: terminal?.provider_id ?? '',
        cafeteria_provider_id: terminal?.cafeteria_provider_id ?? '',
        organization_id: terminal?.organization_id ?? '',
        certificate_reference: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => toast.success(t('nfc.terminalSaved')),
        };

        if (isEdit) {
            form.put(route('nfc-management.terminals.update', terminal.id), options);
        } else {
            form.post(route('nfc-management.terminals.store'), options);
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={isEdit ? t('nfc.editTerminal') : t('nfc.registerTerminal')} />

            <PageHeader
                title={isEdit ? t('nfc.editTerminal') : t('nfc.registerTerminal')}
                description={t('nfc.terminals')}
                backHref={route('nfc-management.terminals.index')}
            />

            <NfcSubNav can={can} current="terminals" />

            <form
                onSubmit={submit}
                className="max-w-3xl rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Row label={t('nfc.terminalCode')} error={form.errors.terminal_code}>
                        <input
                            value={form.data.terminal_code}
                            onChange={(e) => form.setData('terminal_code', e.target.value)}
                            className={`${fieldCls} font-mono`}
                            required
                        />
                    </Row>

                    <Row label={t('nfc.terminalName')} error={form.errors.name}>
                        <input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            className={fieldCls}
                            required
                        />
                    </Row>

                    <Row label={t('nfc.terminalType')} error={form.errors.terminal_type}>
                        <select
                            value={form.data.terminal_type}
                            onChange={(e) => form.setData('terminal_type', e.target.value)}
                            className={fieldCls}
                        >
                            {types.map((type) => (
                                <option key={type} value={type}>
                                    {terminalTypeLabel(type)}
                                </option>
                            ))}
                        </select>
                    </Row>

                    <Row label={t('nfc.status')} error={form.errors.status}>
                        <select
                            value={form.data.status}
                            onChange={(e) => form.setData('status', e.target.value)}
                            className={fieldCls}
                        >
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </select>
                    </Row>

                    <Row label={t('nfc.externalApplication')} error={form.errors.external_application_id}>
                        <select
                            value={form.data.external_application_id}
                            onChange={(e) => form.setData('external_application_id', e.target.value)}
                            className={fieldCls}
                            required
                        >
                            <option value="">—</option>
                            {applications.map((application) => (
                                <option key={application.id} value={application.id}>
                                    {application.name}
                                </option>
                            ))}
                        </select>
                    </Row>

                    <Row label={t('nfc.organization')} error={form.errors.organization_id}>
                        <select
                            value={form.data.organization_id}
                            onChange={(e) => form.setData('organization_id', e.target.value)}
                            className={fieldCls}
                        >
                            <option value="">—</option>
                            {organizations.map((organization) => (
                                <option key={organization.id} value={organization.id}>
                                    {localizedName(organization)}
                                </option>
                            ))}
                        </select>
                    </Row>

                    <Row label={t('nfc.serviceType')} error={form.errors.service_type}>
                        <select
                            value={form.data.service_type}
                            onChange={(e) => form.setData('service_type', e.target.value)}
                            className={fieldCls}
                        >
                            <option value="">—</option>
                            {serviceTypes.map((code) => (
                                <option key={code} value={code}>
                                    {code}
                                </option>
                            ))}
                        </select>
                    </Row>

                    <Row label={t('nfc.provider')} error={form.errors.provider_id}>
                        <select
                            value={form.data.provider_id}
                            onChange={(e) => form.setData('provider_id', e.target.value)}
                            className={fieldCls}
                        >
                            <option value="">—</option>
                            {providers.map((provider) => (
                                <option key={provider.id} value={provider.id}>
                                    {provider.name}
                                </option>
                            ))}
                        </select>
                    </Row>

                    {form.data.terminal_type === 'cafeteria' && (
                        <Row label={t('nfc.cafeteriaProvider')} error={form.errors.cafeteria_provider_id}>
                            <select
                                value={form.data.cafeteria_provider_id}
                                onChange={(e) => form.setData('cafeteria_provider_id', e.target.value)}
                                className={fieldCls}
                            >
                                <option value="">—</option>
                                {cafeteriaProviders.map((provider) => (
                                    <option key={provider.id} value={provider.id}>
                                        {provider.name_en}
                                    </option>
                                ))}
                            </select>
                        </Row>
                    )}

                    <Row label={t('nfc.certificateFingerprint')} error={form.errors.certificate_reference}>
                        <input
                            value={form.data.certificate_reference}
                            onChange={(e) => form.setData('certificate_reference', e.target.value)}
                            className={fieldCls}
                            placeholder={terminal?.certificate_fingerprint ?? ''}
                        />
                        <span className="mt-1 block text-xs text-gray-500 dark:text-slate-400">
                            {t('nfc.certificateHint')}
                        </span>
                    </Row>
                </div>

                <div className="mt-6 flex gap-3">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-50"
                    >
                        {t('common.save')}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
