import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { ListPagination, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { FollowUpCard } from '@/modules/reporting/components/FollowUpPanel';
import { ActionErrors } from '@/modules/reporting/components/Notices';
import { inputCls, labelCls, mutedCls, panelCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { FollowUpRow, Paginated } from '@/modules/reporting/types';
import { Head, Link, router } from '@inertiajs/react';
import type { JSX } from 'react';

interface Props {
    filters: { status: string; mine: boolean };
    rows: Paginated<FollowUpRow>;
}

/** Follow-up actions arising from reports, across the institutions in scope. */
export default function FollowUps({ filters, rows }: Props): JSX.Element {
    const { rt, name, label } = useReporting();
    const apply = (next: Partial<Props['filters']>) => router.get(route('reporting.follow-ups.index'), { ...filters, ...next, mine: (next.mine ?? filters.mine) ? 1 : undefined }, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={rt('followUps.title')} description={rt('followUps.pageDescription')} />}>
            <Head title={rt('followUps.title')} />
            <ActionErrors />
            <div className="space-y-4 pb-4">
                <div className={cx(panelCls, 'flex flex-wrap items-end gap-4 p-4')}>
                    <div>
                        <label htmlFor="fl-status" className={labelCls}>{rt('followUps.statusLabel')}</label>
                        <select id="fl-status" className={inputCls} value={filters.status} onChange={(e) => apply({ status: e.target.value })}>
                            <option value="active">{rt('followUps.status.active')}</option>
                            {['open', 'in_progress', 'done', 'closed', 'cancelled'].map((status) => <option key={status} value={status}>{rt(`followUps.status.${status}`)}</option>)}
                        </select>
                    </div>
                    <label className="flex items-center gap-2 pb-2 text-sm">
                        <input type="checkbox" checked={filters.mine} onChange={(e) => apply({ mine: e.target.checked })} />
                        {rt('followUps.mineOnly')}
                    </label>
                </div>

                {rows.data.length === 0 ? <EmptyState title={rt('followUps.emptyTitle')} description={rt('followUps.emptyDescription')} /> : (
                    <ul className="space-y-3">
                        {rows.data.map((action) => (
                            <li key={action.id} className={cx(panelCls, 'p-3')}>
                                {action.obligation && (
                                    <p className={cx(mutedCls, 'mb-2 text-xs')}>
                                        <Link href={route('reporting.obligations.show', action.obligation.id)} className="text-primary hover:underline">
                                            {name(action.obligation.organization)} · {name(action.obligation.report_type)} · {label(action.obligation.period)}
                                        </Link>
                                    </p>
                                )}
                                <FollowUpCard action={action} canClose={false} canUpdate />
                            </li>
                        ))}
                    </ul>
                )}
                <ListPagination meta={rows} />
            </div>
        </AuthenticatedLayout>
    );
}
