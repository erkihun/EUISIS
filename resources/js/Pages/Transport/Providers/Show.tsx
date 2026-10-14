import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { Button, Card, ConfirmDialog, StatusBadge, buttonClassName } from '@euisis/ui';
import { useState } from 'react';
import { useTransportLabels } from '@/Components/transport/labels';
import type { TransportProvider } from '@/types/transport';

export default function Show({ provider, can }: { provider: TransportProvider; can: { update: boolean; delete: boolean } }) {
    const { t, name, label, tone } = useTransportLabels();
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const rows: [string, string][] = [
        [t('transport.providerCode'), provider.provider_code],
        [t('transport.scope'), label('scopeLabels', provider.assigned_scope_type)],
        [t('transport.organization'), provider.assigned_scope_type === 'citywide' ? t('transport.allOrganizations') : name(provider.assigned_organization)],
        [t('transport.licenseNumber'), provider.profile?.license_number || '—'],
        [t('transport.registrationNumber'), provider.profile?.registration_number || '—'],
        [t('transport.contactPerson'), provider.contact_person || '—'],
        [t('transport.phone'), provider.phone_number || '—'],
        [t('transport.email'), provider.email || '—'],
        [t('transport.address'), provider.address || '—'],
        [t('transport.serviceAreaEn'), provider.profile?.service_area_description_en || '—'],
        [t('transport.serviceAreaAm'), provider.profile?.service_area_description_am || '—'],
    ];

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={name(provider)}
                    backHref={route('transport.providers.index')}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.update && <Link href={route('transport.providers.edit', provider.id)} className={buttonClassName({ variant: 'outline' })}>{t('transport.edit')}</Link>}
                            {can.delete && <Button variant="destructiveOutline" onClick={() => setConfirming(true)}>{t('transport.deleteProvider')}</Button>}
                        </div>
                    }
                />
            }
        >
            <Head title={name(provider)} />
            <Card>
                <div className="mb-4"><StatusBadge tone={tone(provider.status)}>{label('statuses', provider.status)}</StatusBadge></div>
                <dl className="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    {rows.map(([term, value]) => (
                        <div key={term} className="min-w-0">
                            <dt className="text-[color:var(--app-muted-foreground)]">{term}</dt>
                            <dd className="mt-0.5 break-words">{value}</dd>
                        </div>
                    ))}
                </dl>
            </Card>
            <ConfirmDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={() => router.delete(route('transport.providers.destroy', provider.id), { onStart: () => setDeleting(true), onFinish: () => { setDeleting(false); setConfirming(false); } })}
                title={t('transport.deleteProvider')}
                description={t('transport.deleteProviderConfirm')}
                confirmLabel={t('transport.delete')}
                cancelLabel={t('transport.cancel')}
                destructive
                loading={deleting}
            />
        </AuthenticatedLayout>
    );
}
