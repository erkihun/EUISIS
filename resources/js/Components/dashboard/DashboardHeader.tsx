import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';

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
    /**
     * Still computed and permission-filtered server-side, and still asserted by
     * DashboardTest, but no longer drawn here.
     *
     * It used to render as a row of up to seven equally-weighted outline
     * buttons — Add Employee, Add Position, Issue ID Card, Verify, Organogram,
     * Reports, API Management. Every one of those is a top-level sidebar entry
     * that is always on screen, so the row bought nothing and cost the header
     * its entire right-hand side. If a compact "New…" menu is wanted later,
     * the data is already here.
     */
    quickActions: QuickAction[];
};

/**
 * One line: what this dashboard covers, and how fresh it is.
 *
 * The previous header spent three stacked description-list rows on scope,
 * today's date and the refresh time. Today's date is on the operator's own
 * screen clock, so it is gone; the other two are facts a reader needs exactly
 * once, so they sit inline after the title rather than under it.
 */
export default function DashboardHeader({ header }: { header: DashboardHeaderData }) {
    const { t } = useLocale();

    /* Stated explicitly so a narrowed dashboard is never mistaken for a
       system-wide one. */
    const scopeLabel = header.organizationName
        ? header.organizationName
        : header.globalAccess
          ? t('dashboard.scopeAllOrganizations')
          : `${t('dashboard.scopeAssignedOrganizations')} (${header.organizationCount})`;

    return (
        <div className="mb-4 flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 className="text-xl font-semibold text-gray-900 dark:text-slate-100">
                {t('dashboard.title')}
            </h1>

            <span aria-hidden="true" className="text-gray-300 dark:text-slate-700">
                /
            </span>

            <p className="text-sm font-medium text-gray-700 dark:text-slate-300">
                <span className="sr-only">{t('dashboard.scopeLabel')}: </span>
                {scopeLabel}
            </p>

            {header.generatedAt && (
                <p className="ms-auto text-xs text-gray-500 dark:text-slate-400">
                    {t('dashboard.lastRefreshed')}:{' '}
                    <LocalizedDateDisplay value={header.generatedAt} withTime />
                </p>
            )}
        </div>
    );
}
