import PageHeader from '@/Components/PageHeader';
import { ListPagination } from '@/Components/ui';
import ActionItemCard from '@/Components/serviceMonitoring/ActionItemCard';
import { inputCls, labelCls, panelCls, useSm } from '@/Components/serviceMonitoring/helpers';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { ActionItemRow, ActionItemStatus, Paginated } from '@/types/serviceMonitoring';
import { Head, router } from '@inertiajs/react';
import type { JSX } from 'react';

/** Action items across visits, overdue first (D22). */
export default function ActionItemsIndex({ items, filters, statuses }: { items: Paginated<ActionItemRow>; filters: { status: string | null; overdue: boolean }; statuses: ActionItemStatus[] }): JSX.Element {
    const { sm } = useSm();
    const filter = (changes: Record<string, string | number>) => router.get(route('service-monitoring.action-items.index'), { status: filters.status ?? '', overdue: filters.overdue ? 1 : 0, ...changes }, { preserveState: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('actionItems.title')} description={sm('actionItems.description')} />}>
            <Head title={sm('actionItems.title')} />
            <div className="space-y-4">
                <div className={`${panelCls} flex flex-wrap items-end gap-3 p-3`}>
                    <div>
                        <label htmlFor="sm-ai-status" className={labelCls}>{sm('common.status')}</label>
                        <select id="sm-ai-status" className={inputCls} value={filters.status ?? ''} onChange={(e) => filter({ status: e.target.value })}>
                            <option value="">{sm('common.all')}</option>
                            {statuses.map((s) => <option key={s} value={s}>{sm(`enums.action_item_status.${s}`)}</option>)}
                        </select>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={filters.overdue} onChange={(e) => filter({ overdue: e.target.checked ? 1 : 0 })} />
                        {sm('actionItems.overdueOnly')}
                    </label>
                </div>
                {items.data.length === 0 ? <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('actionItems.empty')}</p> : (
                    <ul className={`${panelCls} divide-y divide-(--app-border)`}>{items.data.map((i) => <ActionItemCard key={i.id} item={i} />)}</ul>
                )}
                <ListPagination meta={items} />
            </div>
        </AuthenticatedLayout>
    );
}
