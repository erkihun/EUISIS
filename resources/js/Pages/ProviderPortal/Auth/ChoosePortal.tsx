import { CardHeading, ICONS, Notice } from '@/Components/ProviderPortal/AuthFields';
import type { ProviderPortal, SessionNotice } from '@/Components/ProviderPortal/ProviderSignInForm';
import { useLocale } from '@/hooks/useLocale';
import ProviderAuthLayout from '@/Layouts/ProviderAuthLayout';
import { Link } from '@inertiajs/react';

const PORTALS: Array<{ portal: ProviderPortal; icon: string; tile: string; ring: string }> = [
    {
        portal: 'cafeteria',
        icon: ICONS.cup,
        tile: 'bg-linear-to-br from-orange-400 to-orange-600 shadow-orange-600/30',
        ring: 'hover:border-orange-400 focus-visible:ring-orange-500/40 dark:hover:border-orange-500/60',
    },
    {
        portal: 'transport',
        icon: ICONS.bus,
        tile: 'bg-linear-to-br from-sky-400 to-blue-600 shadow-sky-600/30',
        ring: 'hover:border-sky-400 focus-visible:ring-sky-500/40 dark:hover:border-sky-500/60',
    },
];

/**
 * /provider/portal/login — the address printed and bookmarked before each
 * service had its own sign-in. It asks which portal; the one this browser
 * last signed in to comes first.
 */
export default function ChoosePortal({
    status,
    sessionNotice,
    lastPortal,
}: {
    status?: string | null;
    sessionNotice?: SessionNotice | null;
    lastPortal?: ProviderPortal | null;
}) {
    const { t } = useLocale();
    const ordered = lastPortal === 'transport' ? [...PORTALS].reverse() : PORTALS;

    return (
        <ProviderAuthLayout title={t('providerPortal.choosePortalTitle')}>
            <CardHeading icon={ICONS.store} title={t('providerPortal.choosePortalTitle')} subtitle={t('providerPortal.choosePortalSubtitle')} />

            {status && <Notice tone="success">{status}</Notice>}
            {sessionNotice && <Notice tone="warning">{t(`auth.session.${sessionNotice}`)}</Notice>}

            <ul className="space-y-3">
                {ordered.map(({ portal, icon, tile, ring }) => (
                    <li key={portal}>
                        <Link
                            href={route(`provider.portal.${portal}.login`)}
                            className={`group flex items-center gap-4 rounded-card border border-gray-200 bg-white px-4 py-4 transition-colors focus:outline-hidden focus-visible:ring-2 dark:border-slate-700 dark:bg-slate-800/60 ${ring}`}
                        >
                            <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-panel text-white shadow-md ${tile}`}>
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" d={icon} />
                                </svg>
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="flex items-center gap-2">
                                    <span className="font-semibold text-gray-900 dark:text-white">{t(`providerPortal.choose_${portal}`)}</span>
                                    {lastPortal === portal && (
                                        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-slate-700 dark:text-slate-300">
                                            {t('providerPortal.lastUsed')}
                                        </span>
                                    )}
                                </span>
                                <span className="mt-0.5 block text-sm text-gray-500 dark:text-slate-400">{t(`providerPortal.choose_${portal}_hint`)}</span>
                            </span>
                            <svg className="h-4 w-4 shrink-0 text-gray-400 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                                <path strokeLinecap="round" strokeLinejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                        </Link>
                    </li>
                ))}
            </ul>

            <p className="mt-6 border-t border-gray-200 pt-5 text-center text-sm text-gray-500 dark:border-slate-800 dark:text-slate-400">
                {t('providerPortal.staffLoginPrompt')}{' '}
                <Link href={route('login')} className="font-medium text-orange-600 hover:text-orange-700 dark:text-orange-400 dark:hover:text-orange-300">
                    {t('providerPortal.staffLogin')}
                </Link>
            </p>
        </ProviderAuthLayout>
    );
}
