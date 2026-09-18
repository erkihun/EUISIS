import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

const labels: Record<string, string> = {
    total: 'transport.reportTotal',
    accepted: 'transport.accepted',
    rejected: 'transport.rejected',
};

export default function Index({ summary = {} }: { summary?: Record<string, number> }) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('transport.reports')} />}
        >
            <Head title={t('transport.reports')} />

            <div className="grid gap-4 sm:grid-cols-3">
                {Object.entries(summary).map(([key, value]) => (
                    <div
                        key={key}
                        className="overflow-hidden rounded-card border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
                    >
                        <p className="text-xs font-semibold text-gray-500 dark:text-slate-400">
                            {t(labels[key] ?? key)}
                        </p>
                        <p className="mt-2 text-3xl font-bold text-gray-900 dark:text-slate-50">{value}</p>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
