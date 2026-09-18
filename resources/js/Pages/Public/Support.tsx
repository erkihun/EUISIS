import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import PublicLayout from '@/Layouts/PublicLayout';
import { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement>;

function LifeBuoyIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <circle cx="12" cy="12" r="10" />
            <circle cx="12" cy="12" r="4" />
            <line x1="4.93" y1="4.93" x2="9.17" y2="9.17" />
            <line x1="14.83" y1="14.83" x2="19.07" y2="19.07" />
            <line x1="14.83" y1="9.17" x2="19.07" y2="4.93" />
            <line x1="4.93" y1="19.07" x2="9.17" y2="14.83" />
        </svg>
    );
}

export default function PublicSupport() {
    const { t } = useLocale();
    const { getString } = useSystemSettings();

    const email = getString('general.support_email');
    const phone = getString('general.support_phone');
    const helpUrl = getString('general.help_center_url');

    const hasContact = email || phone || helpUrl;

    return (
        <PublicLayout title={t('home.supportPageTitle')}>
            <div className="mx-auto max-w-lg px-4 py-12 sm:px-6">
                <div className="mb-8 flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-card bg-amber-600 text-white">
                        <LifeBuoyIcon className="h-5 w-5" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-slate-100">{t('home.supportPageTitle')}</h1>
                        <p className="text-sm text-gray-500 dark:text-slate-400">{t('home.supportPageSubtitle')}</p>
                    </div>
                </div>

                <div className="rounded-panel border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    {!hasContact ? (
                        <p className="text-sm text-gray-500 dark:text-slate-400">{t('home.supportNoContact')}</p>
                    ) : (
                        <dl className="space-y-4">
                            {email && (
                                <div>
                                    <dt className="text-xs font-semibold text-gray-400 dark:text-slate-500">{t('home.supportEmailLabel')}</dt>
                                    <dd className="mt-1">
                                        <a href={`mailto:${email}`} className="text-sm text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">{email}</a>
                                    </dd>
                                </div>
                            )}
                            {phone && (
                                <div>
                                    <dt className="text-xs font-semibold text-gray-400 dark:text-slate-500">{t('home.supportPhoneLabel')}</dt>
                                    <dd className="mt-1">
                                        <a href={`tel:${phone}`} className="text-sm text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">{phone}</a>
                                    </dd>
                                </div>
                            )}
                            {helpUrl && (
                                <div>
                                    <dt className="text-xs font-semibold text-gray-400 dark:text-slate-500">{t('home.supportHelpCenterLabel')}</dt>
                                    <dd className="mt-1">
                                        <a href={helpUrl} target="_blank" rel="noopener noreferrer" className="text-sm text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">{helpUrl}</a>
                                    </dd>
                                </div>
                            )}
                        </dl>
                    )}
                </div>
            </div>
        </PublicLayout>
    );
}
