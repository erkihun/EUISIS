import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import StatCard from '@/Components/StatCard';
import UserAvatar from '@/Components/UserAvatar';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    AppTabNav, Button, FilterField, FilterPanel, ListPagination, Select, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { usePerformanceText } from '@/court/i18n/usePerformanceText';
import type { PerformanceIndexProps } from '@/court/types/performance';
import { CriterionDialog } from '../PerformanceSettings/Partials/CriterionForm';
import { CategoryDialog } from '../PerformanceSettings/Partials/CategoryForm';
import { PERIOD_TYPES, STATUS_TONES, formatScore, overallTextClass } from './score';

/**
 * The performance evaluations register: the totals, a tab per status, the
 * member and period filters, each evaluation with its weighted score, and
 * (with settings.manage) the Add Criterion / Add Category dialogs.
 */
export default function PerformanceIndex({ evaluations, users, categories, stats, filters, can, returnUrl }: PerformanceIndexProps) {
    const { p } = usePerformanceText();
    const [userId, setUserId] = useState(filters.user_id);
    const [periodType, setPeriodType] = useState(filters.period_type);
    const [criterionOpen, setCriterionOpen] = useState(false);
    const [categoryOpen, setCategoryOpen] = useState(false);
    const title = p('title');
    const na = p('not_available');

    const query = (overrides: Partial<PerformanceIndexProps['filters']>): Record<string, string> => {
        const merged = { ...filters, ...overrides };
        return Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== ''));
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(route('court.performance-evaluations.index'), query({ user_id: userId, period_type: periodType }), { preserveState: true });
    };

    const tabs = [
        { key: '', label: p('tabs.all'), count: stats.total },
        { key: 'draft', label: p('statuses.draft'), count: stats.draft },
        { key: 'submitted', label: p('statuses.submitted'), count: stats.submitted },
        { key: 'reviewed', label: p('statuses.reviewed'), count: stats.reviewed },
    ].map((tab) => ({ ...tab, href: route('court.performance-evaluations.index', query({ status: tab.key })) }));

    const actions = (
        <>
            {can.manageSettings && (
                <>
                    <Button variant="outline" onClick={() => setCriterionOpen(true)}>{p('settings.actions.add_criterion')}</Button>
                    <Button variant="outline" onClick={() => setCategoryOpen(true)}>{p('settings.actions.add_category')}</Button>
                </>
            )}
            {can.create && <Link href={route('court.performance-evaluations.create')} className={buttonClassName({})}>{p('actions.new')}</Link>}
        </>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={title} actions={actions} />}>
            <Head title={title} />

            <div className="space-y-4">
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <StatCard label={p('stats.total')} value={stats.total} />
                    <StatCard label={p('stats.draft')} value={stats.draft} tone="warning" />
                    <StatCard label={p('stats.submitted')} value={stats.submitted} tone="primary" />
                    <StatCard label={p('stats.reviewed')} value={stats.reviewed} tone="success" />
                    <StatCard label={p('stats.avg_score')} value={`${stats.avg_score}%`} tone="primary" />
                </div>

                <AppTabNav items={tabs} current={filters.status} label={p('fields.status')} />

                <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.performance-evaluations.index'))}
                    hasActiveFilters={Boolean(filters.status || filters.user_id || filters.period_type)} resetLabel={p('filters.clear')} label={title}>
                    <FilterField label={p('fields.member')}>
                        <Select value={userId} onChange={(event) => setUserId(event.target.value)}>
                            <option value="">{p('filters.all_members')}</option>
                            {users.map((user) => <option key={user.id} value={String(user.id)}>{user.name}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={p('fields.period_type')}>
                        <Select value={periodType} onChange={(event) => setPeriodType(event.target.value)}>
                            <option value="">{p('filters.all_periods')}</option>
                            {PERIOD_TYPES.map((period) => <option key={period} value={period}>{p(`periods.${period}`)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{p('fields.member')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{p('fields.period')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{p('fields.type')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-center')}>{p('fields.score')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-center')}>{p('fields.status')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{p('fields.evaluator')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{p('fields.date')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-right')}>{p('fields.actions')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {evaluations.data.map((evaluation) => (
                                    <tr key={evaluation.id} className={tableRowClassName}>
                                        <td className={tableCellClassName}>
                                            <div className="flex items-center gap-2.5">
                                                <UserAvatar src={evaluation.member_avatar_url} name={evaluation.member_name ?? '?'} size={32} />
                                                <span className="font-medium text-(--app-foreground)">{evaluation.member_name ?? na}</span>
                                            </div>
                                        </td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}>
                                            <LocalizedDateDisplay value={evaluation.period_start} fallback={na} /> – <LocalizedDateDisplay value={evaluation.period_end} fallback={na} />
                                        </td>
                                        <td className={tableCellClassName}><span className={badgeClassName('neutral')}>{p(`periods.${evaluation.period_type}`)}</span></td>
                                        <td className={cx(tableCellClassName, 'text-center')}>
                                            <span className={cx('text-lg font-extrabold tabular-nums', overallTextClass(evaluation.overall_score))}>{formatScore(evaluation.overall_score)}%</span>
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-center')}>
                                            <span className={badgeClassName(STATUS_TONES[evaluation.status] ?? 'neutral')}>{p(`statuses.${evaluation.status}`)}</span>
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{evaluation.evaluator_name ?? na}</td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-xs text-(--app-muted-foreground)')}>
                                            <LocalizedDateDisplay value={evaluation.created_at} fallback={na} />
                                        </td>
                                        <td className={tableCellClassName}>
                                            <div className="flex items-center justify-end gap-1.5">
                                                <Link href={route('court.performance-evaluations.show', evaluation.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{p('actions.view')}</Link>
                                                {evaluation.status !== 'reviewed' && can.update && (
                                                    <Link href={route('court.performance-evaluations.edit', evaluation.id)} className={buttonClassName({ variant: 'secondary', size: 'sm' })}>{p('actions.edit')}</Link>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {evaluations.data.length === 0 && (
                        <EmptyState
                            title={p('empty.evaluations')}
                            action={can.create ? <Link href={route('court.performance-evaluations.create')} className={buttonClassName({ variant: 'link' })}>{p('actions.create_first')}</Link> : undefined}
                        />
                    )}
                    <ListPagination meta={evaluations} />
                </div>
            </div>

            {can.manageSettings && (
                <>
                    <CriterionDialog open={criterionOpen} criterion={null} categories={categories} returnUrl={returnUrl} onClose={() => setCriterionOpen(false)} />
                    <CategoryDialog open={categoryOpen} returnUrl={returnUrl} onClose={() => setCategoryOpen(false)} />
                </>
            )}
        </AuthenticatedLayout>
    );
}
