import CafeteriaProviderPortalLayout from '@/Layouts/CafeteriaProviderPortalLayout';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Provider = { id: string; code: string; name_en: string; name_am?: string | null };

export default function Settings({ providers, selected_provider_id }: { providers: Provider[]; selected_provider_id: string | null }) {
    const { t } = useLocale();
    return (
        <CafeteriaProviderPortalLayout title={t('providerPortal.settings')} providers={providers} selectedProviderId={selected_provider_id}>
            <Head title={t('providerPortal.settings')} />
            <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <p className="text-sm text-slate-500">{t('providerPortal.providerDetails')}</p>
            </div>
        </CafeteriaProviderPortalLayout>
    );
}
