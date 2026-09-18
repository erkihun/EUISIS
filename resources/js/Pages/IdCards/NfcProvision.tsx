import { FormEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useNfcTypeLabel } from '@/Components/Nfc/NfcStatusBadge';
import { filterInputCls } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';

type Props = {
    card: { id: string; card_number: string; status: string | null; expires_at: string | null };
    employee: {
        employee_number: string | null;
        full_name: string | null;
        organization: { name_en?: string | null; name_am?: string | null } | null;
    };
    /** What the configured adapter can really do — never assumed. */
    hardware: {
        adapter: string;
        secure_available: boolean;
        types: { value: string; available: boolean }[];
    };
};

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-gray-500 dark:text-slate-400">{label}</dt>
            <dd className="mt-0.5 text-sm text-gray-900 dark:text-slate-100">{children}</dd>
        </div>
    );
}

export default function NfcProvision({ card, employee, hardware }: Props) {
    const { t, locale } = useLocale();
    const typeLabel = useNfcTypeLabel();

    // Default to the strongest type the hardware can actually deliver.
    const form = useForm({
        credential_type: hardware.secure_available ? 'secure_smart_card' : 'ndef_reference',
        key_version: '',
        key_reference: '',
    });

    const organizationName = employee.organization
        ? ((locale === 'am'
              ? (employee.organization.name_am ?? employee.organization.name_en)
              : employee.organization.name_en) ?? '—')
        : '—';

    const needsKeyMaterial = form.data.credential_type !== 'ndef_reference';

    function submit(event: FormEvent) {
        event.preventDefault();

        form.post(route('nfc.provision', card.id), {
            preserveScroll: true,
            onSuccess: () => toast.success(t('nfc.provisionedPending')),
            onError: (errors) => toast.error(Object.values(errors)[0] ?? t('common.error')),
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title={t('nfc.provisionTitle')} />

            <PageHeader
                title={t('nfc.provisionTitle')}
                description={t('nfc.provisionSubtitle')}
                backHref={route('id-cards.show', card.id)}
            />

            <div className="grid max-w-4xl gap-4 lg:grid-cols-2">
                {/* Read-only context */}
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {t('nfc.linkedIdCard')}
                    </h2>
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('nfc.employee')}>{employee.full_name ?? '—'}</Field>
                        <Field label="#">{employee.employee_number ?? '—'}</Field>
                        <Field label={t('nfc.cardNumber')}>{card.card_number}</Field>
                        <Field label={t('nfc.cardStatus')}>
                            {card.status ? <StatusBadge status={card.status} /> : '—'}
                        </Field>
                        <Field label={t('nfc.organization')}>{organizationName}</Field>
                        <Field label={t('nfc.expiresAt')}>
                            <LocalizedDateDisplay value={card.expires_at} />
                        </Field>
                    </dl>

                    <p className="mt-4 rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:bg-blue-900/20 dark:text-blue-300">
                        {t('nfc.qrUnaffectedNote')}
                    </p>
                </section>

                {/* Provisioning form */}
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    {/*
                      * Reader status is reported from the bound adapter, so an
                      * operator is never shown a success state that did not
                      * happen. Without an adapter the only honest outcome is a
                      * pending record awaiting hardware personalisation.
                      */}
                    <div
                        className={`mb-4 rounded-card px-4 py-3 text-sm ${
                            hardware.secure_available
                                ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300'
                                : 'bg-amber-50 text-amber-900 dark:bg-amber-900/20 dark:text-amber-300'
                        }`}
                    >
                        <p className="font-medium">{t('nfc.hardwareTitle')}</p>
                        <p className="mt-1">
                            {hardware.secure_available ? t('nfc.hardwareAvailable') : t('nfc.hardwareUnavailable')}
                        </p>
                        {!hardware.secure_available && (
                            <p className="mt-2 text-xs">{t('nfc.hardwareUnavailableDetail')}</p>
                        )}
                    </div>

                    <form onSubmit={submit} className="space-y-4">
                        <label className="block">
                            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-200">
                                {t('nfc.selectCredentialType')}
                            </span>
                            <select
                                value={form.data.credential_type}
                                onChange={(e) => form.setData('credential_type', e.target.value)}
                                className={`${filterInputCls} w-full`}
                            >
                                {hardware.types.map((type) => (
                                    <option key={type.value} value={type.value} disabled={!type.available}>
                                        {typeLabel(type.value)}
                                        {type.available ? '' : ` — ${t('nfc.secureTypeUnavailable')}`}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.credential_type} className="mt-1" />
                        </label>

                        {needsKeyMaterial && (
                            <>
                                <label className="block">
                                    <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-200">
                                        {t('nfc.keyVersion')}
                                    </span>
                                    <input
                                        value={form.data.key_version}
                                        onChange={(e) => form.setData('key_version', e.target.value)}
                                        className={`${filterInputCls} w-full`}
                                    />
                                    <InputError message={form.errors.key_version} className="mt-1" />
                                </label>

                                <label className="block">
                                    <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-200">
                                        {t('nfc.keyReference')}
                                    </span>
                                    <input
                                        value={form.data.key_reference}
                                        onChange={(e) => form.setData('key_reference', e.target.value)}
                                        className={`${filterInputCls} w-full`}
                                    />
                                    <span className="mt-1 block text-xs text-gray-500 dark:text-slate-400">
                                        {t('nfc.keyReferenceHint')}
                                    </span>
                                    <InputError message={form.errors.key_reference} className="mt-1" />
                                </label>
                            </>
                        )}

                        {/* Lifecycle rejections arrive under an `nfc` key the
                            form data shape does not declare. */}
                        <InputError message={(form.errors as Record<string, string>).nfc} />

                        <button
                            type="submit"
                            disabled={form.processing}
                            className="w-full rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-50"
                        >
                            {t('nfc.provisionSubmit')}
                        </button>

                        <p className="text-center text-xs text-gray-500 dark:text-slate-400">
                            {t('nfc.waitingForHardware')}
                        </p>
                    </form>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
