import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useTerminalTypeLabel } from '@/Components/Nfc/NfcStatusBadge';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import NfcSubNav, { useLocalizedName, type NfcCapabilities } from '../Partials/NfcSubNav';

type Props = {
    terminal: {
        id: string;
        terminal_code: string;
        name: string;
        terminal_type: string;
        service_type: string | null;
        status: string;
        last_seen_at: string | null;
        created_at: string | null;
        created_by: string | null;
        certificate_fingerprint: string | null;
        provider: { id: string; name: string } | null;
        cafeteria_provider: { id: string; name_en: string } | null;
        organization: { id: string; name_en?: string | null; name_am?: string | null } | null;
        external_application: { id: string; name: string } | null;
    };
    can: NfcCapabilities;
};

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-gray-500 dark:text-slate-400">{label}</dt>
            <dd className="mt-0.5 text-sm text-gray-900 dark:text-slate-100">{children}</dd>
        </div>
    );
}

export default function NfcTerminalShow({ terminal, can }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const terminalTypeLabel = useTerminalTypeLabel();
    const localizedName = useLocalizedName();

    async function remove() {
        const { confirmed } = await confirm({
            title: t('nfc.confirmDeleteTerminalTitle'),
            description: t('nfc.confirmDeleteTerminalBody'),
            confirmLabel: t('nfc.revokeTerminal'),
            variant: 'danger',
        });

        if (!confirmed) return;

        router.delete(route('nfc-management.terminals.destroy', terminal.id), {
            onSuccess: () => toast.success(t('nfc.terminalRevoked')),
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title={terminal.terminal_code} />

            <PageHeader
                title={terminal.name}
                description={terminal.terminal_code}
                backHref={route('nfc-management.terminals.index')}
                actions={
                    <div className="flex gap-2">
                        {can.updateTerminals && (
                            <Link
                                href={route('nfc-management.terminals.edit', terminal.id)}
                                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                {t('common.edit')}
                            </Link>
                        )}
                        {can.deleteTerminals && (
                            <button
                                type="button"
                                onClick={remove}
                                className="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20"
                            >
                                {t('nfc.revokeTerminal')}
                            </button>
                        )}
                    </div>
                }
            />

            <NfcSubNav can={can} current="terminals" />

            <section className="max-w-3xl rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('nfc.terminal')}</h2>
                    <StatusBadge status={terminal.status} />
                </div>

                <dl className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('nfc.terminalCode')}>
                        <span className="font-mono text-xs">{terminal.terminal_code}</span>
                    </Field>
                    <Field label={t('nfc.terminalType')}>{terminalTypeLabel(terminal.terminal_type)}</Field>
                    <Field label={t('nfc.serviceType')}>{terminal.service_type ?? '—'}</Field>
                    <Field label={t('nfc.organization')}>{localizedName(terminal.organization) || '—'}</Field>
                    <Field label={t('nfc.provider')}>{terminal.provider?.name ?? '—'}</Field>
                    <Field label={t('nfc.cafeteriaProvider')}>{terminal.cafeteria_provider?.name_en ?? '—'}</Field>
                    <Field label={t('nfc.externalApplication')}>{terminal.external_application?.name ?? '—'}</Field>
                    <Field label={t('nfc.lastSeen')}>
                        <LocalizedDateDisplay value={terminal.last_seen_at} withTime fallback={t('nfc.neverSeen')} />
                    </Field>
                    <Field label={t('nfc.certificateFingerprint')}>
                        <span className="font-mono text-xs">{terminal.certificate_fingerprint ?? '—'}</span>
                    </Field>
                    <Field label={t('nfc.provisionedBy')}>{terminal.created_by ?? '—'}</Field>
                </dl>

                <p className="mt-4 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-slate-950 dark:text-slate-400">
                    {t('nfc.certificateHint')}
                </p>
            </section>
        </AuthenticatedLayout>
    );
}
