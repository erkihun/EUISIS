import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { localizedName } from '@/utils/localizedName';
import { Empty, formatScore, Pill, Section, Table, tdCls, thCls } from '@/Components/performance/ui';

import { buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
type Movement = {
    id: string;
    from_organization_unit: { name_en: string; name_am: string | null } | null;
    to_organization_unit: { name_en: string; name_am: string | null } | null;
    moved_by: { name: string } | null;
    reason: string;
    moved_at: string;
};

type Named = { name_en: string; name_am: string | null };
type PositionService = Named & { id: string; service_no: string; description: string | null; is_active: boolean };
/** Derived from the plan's lineage on the server; a position never stores a goal. */
type ContributingGoal = { id: string; code: string; name_en: string; name_am: string | null; weight_percent: string; unit: string | null; unit_am: string | null; allocation_percent: string | null };
type PositionPlan = { id: string; title: string; status: string; version: number; organization: Named | null; unit: Named | null; cycle: Named | null; effective_from: string | null; effective_to: string | null; goals: ContributingGoal[] };
type Props = { position: any; movementHistory: Movement[]; positionServices: PositionService[]; currentPerformancePlans: PositionPlan[]; historicalPerformancePlans: PositionPlan[]; canViewPerformancePlans: boolean; canViewPositionServices: boolean };

export default function PositionsShow({ position, movementHistory, positionServices, currentPerformancePlans, historicalPerformancePlans, canViewPerformancePlans, canViewPositionServices }: Props) {
    const { t, locale } = useLocale();
    const standardName = localizedName(position.title_en, position.title_am, locale);

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('positions.index')}
                    title={`${position.job_position_code} · ${standardName}`}
                    actions={
                        <div className="flex gap-3">
                            {position.can?.move && <Link href={route('positions.move', position.id)} className={buttonClassName({ variant: 'primary' })}>{t('positions.move')}</Link>}
                            {position.can?.update && <Link href={route('positions.edit', position.id)} className={buttonClassName({ variant: 'primary' })}>{t('common.edit')}</Link>}
                            {position.can?.archive && position.is_active && <button type="button" onClick={() => router.delete(route('positions.archive', position.id))} className={buttonClassName({ variant: 'destructive' })}>{t('positions.archivePosition')}</button>}
                            {position.can?.restore && !position.is_active && <button type="button" onClick={() => router.post(route('positions.restore', position.id))} className={buttonClassName({ variant: 'primary' })}>{t('positions.restorePosition')}</button>}
                        </div>
                    }
                />
            }
        >
            <Head title={standardName} />
            <div className="grid gap-6 lg:grid-cols-3">
                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5 lg:col-span-2">
                    <div className="grid gap-4 md:grid-cols-2 text-sm">
                        <div><div className="text-xs text-[color:var(--app-muted-foreground)]">{t('positions.jobPositionCode')}</div><div className="mt-1 font-mono">{position.job_position_code}</div></div>
                        <div><div className="text-xs text-[color:var(--app-muted-foreground)]">{t('positions.oldCode')}</div><div className="mt-1 font-mono">{position.old_code ?? '—'}</div></div>
                        <div><div className="text-xs text-[color:var(--app-muted-foreground)]">{t('common.status')}</div><div className="mt-1"><StatusBadge status={position.is_active ? 'active' : 'inactive'} /></div></div>
                        <div><div className="text-xs text-[color:var(--app-muted-foreground)]">{t('positions.organization')}</div><div className="mt-1">{position.organization ? localizedName(position.organization.name_en, position.organization.name_am, locale) : '—'}</div></div>
                        <div><div className="text-xs text-[color:var(--app-muted-foreground)]">{t('positions.organizationUnit')}</div><div className="mt-1">{position.organization_unit ? localizedName(position.organization_unit.name_en, position.organization_unit.name_am, locale) : '—'}</div></div>
                        <div><div className="text-xs text-[color:var(--app-muted-foreground)]">{t('positions.gradeLevel')}</div><div className="mt-1">{position.grade_level ?? '—'}</div></div>
                        <div><div className="text-xs text-[color:var(--app-muted-foreground)]">{t('positions.standardName')}</div><div className="mt-1">{standardName}</div></div>
                        <div><div className="text-xs text-[color:var(--app-muted-foreground)]">{t('positions.bprName')}</div><div className="mt-1">{position.bpr_name ?? '—'}</div></div>
                        <div><div className="text-xs text-[color:var(--app-muted-foreground)]">{t('positions.occupation')}</div><div className="mt-1">{position.occupation ? localizedName(position.occupation.name_en, position.occupation.name_am, locale) : '—'}</div></div>
                        <div><div className="text-xs text-[color:var(--app-muted-foreground)]">{t('positions.createdAt')}</div><div className="mt-1"><LocalizedDateDisplay value={position.created_at} withTime /></div></div>
                        <div><div className="text-xs text-[color:var(--app-muted-foreground)]">{t('positions.updatedAt')}</div><div className="mt-1"><LocalizedDateDisplay value={position.updated_at} withTime /></div></div>
                    </div>
                </section>
                <aside className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <div className="text-xs text-[color:var(--app-muted-foreground)]">{t('common.count')}</div>
                    <div className="mt-1 text-2xl font-semibold text-[color:var(--app-foreground)]">{position.assignments_count ?? 0}</div>
                </aside>
            </div>

            {canViewPositionServices && <div className="mt-6"><Section title={t('positions.positionServices')}>
                {positionServices.length === 0 ? <Empty>{t('performance.dashboard.noData')}</Empty> : <Table head={<>
                    <th className={thCls}>{t('performance.fields.code')}</th><th className={thCls}>{t('performance.fields.titleEn')}</th>
                    <th className={thCls}>{t('performance.fields.description')}</th><th className={thCls}>{t('common.status')}</th>
                </>}>
                    {positionServices.map((service) => <tr key={service.id}>
                        <td className={tdCls}><Link className="text-blue-700 hover:underline dark:text-blue-300" href={route('position-services.edit', service.id)}>{service.service_no}</Link></td>
                        <td className={tdCls}>{localizedName(service.name_en, service.name_am, locale)}</td>
                        <td className={tdCls}>{service.description ?? '—'}</td>
                        <td className={tdCls}><StatusBadge status={service.is_active ? 'active' : 'inactive'} /></td>
                    </tr>)}
                </Table>}
            </Section></div>}
            {canViewPerformancePlans && <div className="mt-6 space-y-6">
                <PositionPlans title={t('positions.currentPerformancePlan')} plans={currentPerformancePlans} />
                <PositionPlans title={t('positions.historicalPerformancePlans')} plans={historicalPerformancePlans} />
            </div>}

            <section className="mt-6 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                <div className="border-b border-[color:var(--app-border)] px-5 py-4">
                    <h2 className="font-semibold text-[color:var(--app-foreground)]">{t('positions.movementHistory')}</h2>
                </div>
                {movementHistory.length === 0 ? (
                    <p className="px-5 py-6 text-sm text-[color:var(--app-muted-foreground)]">{t('positions.noMovementHistory')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={cx(tableHeadClassName, 'uppercase')}>
                                <tr>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('positions.currentOrganizationUnit')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('positions.targetOrganizationUnit')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('positions.moveReason')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('positions.movedBy')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('positions.movedAt')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {movementHistory.map((movement) => (
                                    <tr key={movement.id} className={tableRowClassName}>
                                        <td className={tableCellClassName}>{movement.from_organization_unit ? localizedName(movement.from_organization_unit.name_en, movement.from_organization_unit.name_am, locale) : '—'}</td>
                                        <td className={tableCellClassName}>{movement.to_organization_unit ? localizedName(movement.to_organization_unit.name_en, movement.to_organization_unit.name_am, locale) : '—'}</td>
                                        <td className={cx(tableCellClassName, 'max-w-md whitespace-pre-wrap')}>{movement.reason}</td>
                                        <td className={tableCellClassName}>{movement.moved_by?.name ?? '—'}</td>
                                        <td className={tableCellClassName}><LocalizedDateDisplay value={movement.moved_at} withTime /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </AuthenticatedLayout>
    );
}

function PositionPlans({ title, plans }: { title: string; plans: PositionPlan[] }) {
    const { t, locale } = useLocale();
    const name = (value: Named | null) => value ? localizedName(value.name_en, value.name_am, locale) : '—';

    return <Section title={title}>
        {plans.length === 0 ? <Empty>{t('performance.dashboard.noData')}</Empty> : <Table head={<>
            <th className={thCls}>{t('performance.fields.titleEn')}</th><th className={thCls}>{t('performance.fields.cycle')}</th>
            <th className={thCls}>{t('performance.fields.organization')}</th><th className={thCls}>{t('performance.fields.unit')}</th>
            <th className={thCls}>{t('performance.fields.version')}</th><th className={thCls}>{t('common.status')}</th>
            <th className={thCls}>{t('performance.fields.period')}</th><th className={thCls}>{t('positions.contributingGoals')}</th>
        </>}>
            {plans.map((plan) => <tr key={plan.id}>
                <td className={tdCls}><Link className="text-blue-700 hover:underline dark:text-blue-300" href={route('performance.plans.show', plan.id)}>{plan.title}</Link></td>
                <td className={tdCls}>{name(plan.cycle)}</td><td className={tdCls}>{name(plan.organization)}</td><td className={tdCls}>{name(plan.unit)}</td>
                <td className={tdCls}>{plan.version}</td><td className={tdCls}><Pill group="plan" value={plan.status} /></td>
                <td className={tdCls}><LocalizedDateDisplay value={plan.effective_from} /> – <LocalizedDateDisplay value={plan.effective_to} /></td>
                <td className={tdCls}>{plan.goals.length === 0 ? <span className="text-[color:var(--app-muted-foreground)]">{t('positions.noContributingGoals')}</span>
                    : <ul className="space-y-1">{plan.goals.map((goal) => <li key={goal.id}>
                        <span className="font-mono text-xs">{goal.code}</span> {localizedName(goal.name_en, goal.name_am, locale)}
                        {goal.allocation_percent !== null && <span className="block text-xs text-[color:var(--app-muted-foreground)]">
                            {localizedName(goal.unit, goal.unit_am, locale)} · {formatScore(goal.allocation_percent)}% / {formatScore(goal.weight_percent)}%
                        </span>}
                    </li>)}</ul>}</td>
            </tr>)}
        </Table>}
    </Section>;
}
