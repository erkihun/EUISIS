import { Plus, SearchIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
export default function TreeToolbar({
    query,
    onQueryChange,
    onExpandAll,
    onCollapseAll,
    onAddRelation,
    canAddRelation,
}: {
    query: string;
    onQueryChange: (value: string) => void;
    onExpandAll: () => void;
    onCollapseAll: () => void;
    onAddRelation: () => void;
    canAddRelation: boolean;
}) {
    const { t } = useLocale();

    return (
        <div className="flex flex-col gap-3 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4 lg:flex-row lg:items-center lg:justify-between">
            <div className="relative flex-1">
                <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" />
                <input
                    value={query}
                    onChange={(event) => onQueryChange(event.target.value)}
                    placeholder={t('hierarchyVersions.searchTree')}
                    aria-label={t('hierarchyVersions.searchTree')}
                    className={cx(inputClassName, 'w-full pl-10 pr-4')}
                />
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={onExpandAll}
                    title={t('hierarchyVersions.expandAll')}
                    aria-label={t('hierarchyVersions.expandAll')}
                    className={buttonClassName({ variant: 'outline' })}
                >
                    {t('hierarchyVersions.expandAll')}
                </button>
                <button
                    type="button"
                    onClick={onCollapseAll}
                    title={t('hierarchyVersions.collapseAll')}
                    aria-label={t('hierarchyVersions.collapseAll')}
                    className={buttonClassName({ variant: 'outline' })}
                >
                    {t('hierarchyVersions.collapseAll')}
                </button>
                {canAddRelation && (
                    <button
                        type="button"
                        onClick={onAddRelation}
                        title={t('hierarchyVersions.addRelation')}
                        aria-label={t('hierarchyVersions.addRelation')}
                        className={buttonClassName({ variant: 'primary' })}
                    >
                        <Plus className="h-4 w-4" />
                        {t('hierarchyVersions.addRelation')}
                    </button>
                )}
            </div>
        </div>
    );
}
