import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Building2, RefreshIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import type { ReactNode } from 'react';

export type QuickAction = {
    key: string;
    routeName: string;
    params: Record<string, string> | never[];
};

export type DashboardHeaderData = {
    generatedAt: string | null;
    organizationName: string | null;
    organizationCount: number;
    globalAccess: boolean;
    quickActions: QuickAction[];
};

/** A restrained page masthead: purpose, scope, key actions and freshness. */
export default function DashboardHeader({
    header,
    refreshing,
    onRefresh,
    filters,
}: {
    header: DashboardHeaderData;
    refreshing: boolean;
    onRefresh: () => void;
    filters?: ReactNode;
}) {
    const { t } = useLocale();

    const scopeLabel = header.organizationName
        ? header.organizationName
        : header.globalAccess
          ? t('dashboard.scopeAllOrganizations')
          : `${t('dashboard.scopeAssignedOrganizations')} (${header.organizationCount})`;

    return (
        <header className="mb-5">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    <h1 className="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                        {t('dashboard.title')}
                    </h1>
                    <p className="mt-2 flex items-start gap-2 text-sm text-gray-500 dark:text-slate-400">
                        <Building2 className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        <span className="sr-only">{t('dashboard.scopeLabel')}: </span>
                        <span className="min-w-0 break-words">{scopeLabel}</span>
                    </p>
                </div>

                <div className="flex w-full min-w-0 flex-wrap items-center gap-3 sm:w-auto lg:justify-end">
                    {filters}
                    {header.generatedAt && (
                        <p className="me-2 text-xs leading-relaxed text-gray-500 dark:text-slate-400">
                            <span className="block text-gray-500 dark:text-slate-400">
                                {t('dashboard.lastRefreshed')}
                            </span>
                            <LocalizedDateDisplay value={header.generatedAt} withTime />
                        </p>
                    )}

                    <button
                        type="button"
                        onClick={onRefresh}
                        disabled={refreshing}
                        className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-700 transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] disabled:cursor-wait disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                        aria-label={refreshing ? t('common.loading') : t('dashboard.refresh')}
                    >
                        <RefreshIcon className={`h-3.5 w-3.5 ${refreshing ? 'animate-spin' : ''}`} aria-hidden="true" />
                        <span className="sr-only">{refreshing ? t('common.loading') : t('dashboard.refresh')}</span>
                    </button>
                </div>
            </div>
        </header>
    );
}
