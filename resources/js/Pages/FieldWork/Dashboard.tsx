import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import ManagementNav from '@/Components/fieldWork/ManagementNav';
import RequestTable from '@/Components/fieldWork/RequestTable';
import type { FieldWorkSummary, ManagementAbilities } from '@/Components/fieldWork/types';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

type Figures = Record<'team_members' | 'pending_approval' | 'approved_today' | 'in_field' | 'returning_today' | 'check_in_missing' | 'overdue' | 'supervisor_not_resolved' | 'completed_today' | 'gps_issues', number>;

type Props = {
    figures: Figures;
    awaitingApproval: FieldWorkSummary[];
    attention: FieldWorkSummary[];
    inField: FieldWorkSummary[];
    can: ManagementAbilities;
};

/**
 * Supervisor / HR dashboard. Every figure is a scoped SQL count from the
 * server (team coverage and/or organization scope); nothing is estimated.
 */
export default function FieldWorkDashboard({ figures, awaitingApproval, attention, inField, can }: Props): JSX.Element {
    const { t } = useLocale();
    const tiles: { key: keyof Figures; tone: 'primary' | 'success' | 'warning' | 'neutral'; href?: string }[] = [
        { key: 'team_members', tone: 'neutral' },
        { key: 'pending_approval', tone: 'primary', href: can.approvals ? route('field-work.pending') : undefined },
        { key: 'approved_today', tone: 'success' },
        { key: 'in_field', tone: 'primary', href: route('field-work.requests.index', { status: 'in_field' }) },
        { key: 'returning_today', tone: 'neutral' },
        { key: 'check_in_missing', tone: 'warning', href: route('field-work.overdue.index', { flag: 'check_in_missing' }) },
        { key: 'overdue', tone: 'warning', href: route('field-work.overdue.index', { flag: 'overdue' }) },
        { key: 'supervisor_not_resolved', tone: 'warning', href: route('field-work.requests.index', { flag: 'supervisor_not_resolved' }) },
        { key: 'completed_today', tone: 'success' },
        { key: 'gps_issues', tone: 'warning', href: route('field-work.requests.index', { flag: 'gps_issues' }) },
    ];
    const show = (row: FieldWorkSummary) => route('field-work.requests.show', row.id);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('fieldWork.management.dashboardTitle')} description={t('fieldWork.management.dashboardDescription')} />}>
            <Head title={t('fieldWork.management.dashboardTitle')} />
            <div className="space-y-4">
                <ManagementNav can={can} current="field-work.dashboard" />
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {tiles.map((tile) => {
                        const card = <StatCard label={t(`fieldWork.management.figures.${tile.key}`)} value={figures[tile.key]} tone={tile.tone} />;
                        return tile.href
                            ? <Link key={tile.key} href={tile.href} className="block rounded-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]">{card}</Link>
                            : <div key={tile.key}>{card}</div>;
                    })}
                </div>

                {can.approvals && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('fieldWork.management.awaiting')}</h2>
                        <RequestTable rows={awaitingApproval} emptyText={t('fieldWork.management.emptyApprovals')} href={show} />
                    </section>
                )}
                <section className="space-y-2">
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('fieldWork.management.attention')}</h2>
                    <RequestTable rows={attention} emptyText={t('fieldWork.management.emptyOverdue')} href={show} />
                </section>
                <section className="space-y-2">
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('fieldWork.management.inField')}</h2>
                    <RequestTable rows={inField} emptyText={t('fieldWork.management.emptyTeam')} href={show} />
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
