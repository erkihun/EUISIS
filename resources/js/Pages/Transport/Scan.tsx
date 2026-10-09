import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import TransportScanPanel from '@/Components/transport/TransportScanPanel';
import { useLocale } from '@/hooks/useLocale';

export default function Scan({ providers = [], routes = [], trips = [] }: { providers?: any[]; routes?: any[]; trips?: any[] }) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout>
            <div className="space-y-4">
                <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-100">{t('transport.scan_id')}</h1>
                <TransportScanPanel providers={providers} routes={routes} trips={trips} scanRouteName="transport.scan.store" />
            </div>
        </AuthenticatedLayout>
    );
}
