import PortalPage from '@/Components/employees/portal/PortalPage';
import Pager from '@/Components/dailyActivity/Pager';
import RequestTable from '@/Components/fieldWork/RequestTable';
import { panelCls, primaryBtn } from '@/Components/fieldWork/helpers';
import type { FieldWorkSummary, Paginated } from '@/Components/fieldWork/types';
import { useLocale } from '@/hooks/useLocale';
import { Link } from '@inertiajs/react';
import type { JSX } from 'react';

type Props = {
    mode: 'active' | 'history';
    hasEmployee: boolean;
    requests: Paginated<FieldWorkSummary> | null;
    can: { create: boolean };
};

/** My Field Work (open requests) and My Field Work History (closed ones). */
export default function MyFieldWork({ mode, hasEmployee, requests, can }: Props): JSX.Element {
    const { t } = useLocale();
    const history = mode === 'history';

    return (
        <PortalPage
            title={t(history ? 'fieldWork.my.historyTitle' : 'fieldWork.my.title')}
            description={t('fieldWork.notTransfer')}
            actions={can.create ? <Link href={route('employee.field-work.create')} className={primaryBtn}>{t('fieldWork.actions.new')}</Link> : undefined}
        >
            {!hasEmployee || !requests ? (
                <p className={`${panelCls} p-4 text-sm text-gray-600 dark:text-slate-300`}>{t('fieldWork.noEmployee')}</p>
            ) : (
                <div className="space-y-3">
                    <RequestTable
                        rows={requests.data}
                        emptyText={t(history ? 'fieldWork.my.historyEmpty' : 'fieldWork.my.empty')}
                        href={(row) => route('employee.field-work.show', row.id)}
                        showEmployee={false}
                    />
                    <Pager meta={requests.meta} links={requests.links} />
                </div>
            )}
        </PortalPage>
    );
}
