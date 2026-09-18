import { useLocale } from '@/hooks/useLocale';
import PublicLayout from '@/Layouts/PublicLayout';
import { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement>;

function LayersIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <polygon points="12 2 2 7 12 12 22 7 12 2" />
            <polyline points="2 17 12 22 22 17" />
            <polyline points="2 12 12 17 22 12" />
        </svg>
    );
}

const SERVICE_KEYS = ['idCards', 'cafeteria', 'transfers', 'verification'] as const;

const SERVICE_COLORS = [
    'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/40 dark:text-blue-300',
    'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300',
    'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-900/60 dark:bg-violet-950/40 dark:text-violet-300',
    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300',
];

export default function PublicServices() {
    const { t } = useLocale();

    return (
        <PublicLayout title={t('home.servicesPageTitle')}>
            <div className="mx-auto max-w-4xl px-4 py-12 sm:px-6">
                <div className="mb-8 flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-card bg-violet-600 text-white">
                        <LayersIcon className="h-5 w-5" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-slate-100">{t('home.servicesPageTitle')}</h1>
                        <p className="text-sm text-gray-500 dark:text-slate-400">{t('home.servicesPageSubtitle')}</p>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    {SERVICE_KEYS.map((key, idx) => (
                        <div key={key} className={`rounded-panel border p-5 ${SERVICE_COLORS[idx]}`}>
                            <p className="font-semibold">{t(`home.service_${key}_title` as never) || key}</p>
                            <p className="mt-1 text-sm opacity-80">{t(`home.service_${key}_desc` as never) || ''}</p>
                        </div>
                    ))}
                </div>
            </div>
        </PublicLayout>
    );
}
