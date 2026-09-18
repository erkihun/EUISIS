import { Plus, SearchIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';

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
        <div className="flex flex-col gap-3 rounded-panel border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 lg:flex-row lg:items-center lg:justify-between">
            <div className="relative flex-1">
                <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                <input
                    value={query}
                    onChange={(event) => onQueryChange(event.target.value)}
                    placeholder={t('hierarchyVersions.searchTree')}
                    aria-label={t('hierarchyVersions.searchTree')}
                    className="w-full rounded-card border border-gray-300 bg-white py-2.5 pl-10 pr-4 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500"
                />
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={onExpandAll}
                    title={t('hierarchyVersions.expandAll')}
                    aria-label={t('hierarchyVersions.expandAll')}
                    className="rounded-card border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    {t('hierarchyVersions.expandAll')}
                </button>
                <button
                    type="button"
                    onClick={onCollapseAll}
                    title={t('hierarchyVersions.collapseAll')}
                    aria-label={t('hierarchyVersions.collapseAll')}
                    className="rounded-card border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    {t('hierarchyVersions.collapseAll')}
                </button>
                {canAddRelation && (
                    <button
                        type="button"
                        onClick={onAddRelation}
                        title={t('hierarchyVersions.addRelation')}
                        aria-label={t('hierarchyVersions.addRelation')}
                        className="inline-flex items-center gap-1.5 rounded-card bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                    >
                        <Plus className="h-4 w-4" />
                        {t('hierarchyVersions.addRelation')}
                    </button>
                )}
            </div>
        </div>
    );
}
