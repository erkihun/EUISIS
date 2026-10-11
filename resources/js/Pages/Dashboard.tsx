import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

export default function Dashboard() {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-[color:var(--app-foreground)]">
                    {t('nav.dashboard')}
                </h2>
            }
        >
            <Head title={t('nav.dashboard')} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-[color:var(--app-surface)] shadow-sm sm:rounded-lg">
                        <div className="p-6 text-[color:var(--app-foreground)]">
                            {t('auth.loggedIn')}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
