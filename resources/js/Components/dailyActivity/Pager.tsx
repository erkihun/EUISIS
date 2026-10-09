import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { PageLink, PaginationMetaData } from './types';

/** Previous / next with a position readout. Compact on phones. */
export default function Pager({ meta, links }: { meta: PaginationMetaData; links: PageLink[] }) {
    const { t } = useLocale();

    if (meta.last_page <= 1) {
        return null;
    }

    const previous = links[0]?.url ?? null;
    const next = links[links.length - 1]?.url ?? null;
    const linkCls = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800';
    const disabledCls = 'rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-400 dark:border-slate-800 dark:text-slate-600';

    return (
        <nav className="flex items-center justify-between gap-3 pt-3 text-sm text-gray-600 dark:text-slate-400" aria-label="Pagination">
            <span>
                {meta.from ?? 0}–{meta.to ?? 0} / {meta.total}
            </span>
            <div className="flex gap-2">
                {previous ? <Link href={previous} preserveScroll className={linkCls}>{t('dailyActivities.actions.previous')}</Link> : <span className={disabledCls}>{t('dailyActivities.actions.previous')}</span>}
                {next ? <Link href={next} preserveScroll className={linkCls}>{t('dailyActivities.actions.next')}</Link> : <span className={disabledCls}>{t('dailyActivities.actions.next')}</span>}
            </div>
        </nav>
    );
}
