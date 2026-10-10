import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { PageLink, PaginationMetaData } from './types';

import { buttonClassName } from '@/Components/ui';
/** Previous / next with a position readout. Compact on phones. */
export default function Pager({ meta, links }: { meta: PaginationMetaData; links: PageLink[] }) {
    const { t } = useLocale();

    if (meta.last_page <= 1) {
        return null;
    }

    const previous = links[0]?.url ?? null;
    const next = links[links.length - 1]?.url ?? null;
    const linkCls = buttonClassName({ variant: 'outline', size: 'sm' });
    const disabledCls = 'rounded-lg border border-[color:var(--app-border)] px-3 py-1.5 text-sm text-[color:var(--app-muted-foreground)]';

    return (
        <nav className="flex items-center justify-between gap-3 pt-3 text-sm text-[color:var(--app-muted-foreground)]" aria-label="Pagination">
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
