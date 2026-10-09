import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import TransportProviderStatusBadge from '@/Components/transport/TransportProviderStatusBadge';
import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

export default function Show({ provider }: { provider: any }) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout>
            <div className="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div className="flex items-center justify-between gap-3">
                    <h1 className="text-lg font-semibold">{provider.name_en}</h1>
                    <TransportProviderStatusBadge status={provider.status} />
                </div>
                <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt className="text-slate-500">{t('transport.providerCode')}</dt><dd>{provider.provider_code}</dd></div>
                    <div><dt className="text-slate-500">{t('transport.licenseNumber')}</dt><dd>{provider.profile?.license_number ?? '-'}</dd></div>
                    <div><dt className="text-slate-500">{t('transport.organization')}</dt><dd>{provider.assigned_scope_type === 'citywide' ? t('transport.allOrganizations') : provider.assigned_organization?.name_en ?? '-'}</dd></div>
                    <div><dt className="text-slate-500">{t('transport.scope')}</dt><dd>{provider.assigned_scope_type === 'citywide' ? t('transport.scopeCitywide') : provider.assigned_scope_type === 'subtree' ? t('transport.scopeSubtree') : t('transport.scopeSelf')}</dd></div>
                    <div><dt className="text-slate-500">{t('transport.contactPerson')}</dt><dd>{provider.contact_person ?? '-'}</dd></div>
                    <div><dt className="text-slate-500">{t('transport.email')}</dt><dd>{provider.email ?? '-'}</dd></div>
                </dl>
                <Link href={route('transport.providers.edit', provider.id)} className="mt-4 inline-flex rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold dark:border-slate-700">{t('transport.edit')}</Link>
            </div>
        </AuthenticatedLayout>
    );
}
