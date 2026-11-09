import PageHeader from '@/Components/PageHeader';
import { Plus } from '@/Components/Icons';
import { dangerLinkBtn, linkBtn, primaryBtn } from '@/Components/performance/ui';
import { BalanceBadge, Card, ContextFilter, GivenWeight, ok, off, pct, truncate, wTable, wTd, wTh, type Can, type Cycle, type Filters, type MainRow, type Named } from '@/Components/performance/strategy';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    mains: MainRow[]; summary: { main_count: number; strategy_count: number; main_total: string } | null;
    filters: Filters; cycles: Cycle[]; organizations: Named[]; can: Can;
};

/** WLMS "Main Strategies" list: counts, then each main strategy with its strategies' given weight and share. */
export default function MainStrategies({ mains, summary, filters, cycles, organizations, can }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const m = (key: string) => t(`performance.strategy.main.${key}`);
    const c = (key: string) => t(`performance.strategy.common.${key}`);
    const contextSelected = filters.cycle_id !== '' && filters.organization_id !== '';

    async function remove(main: MainRow) {
        const { confirmed } = await confirm({ title: m('deleteConfirm'), description: main.title, confirmLabel: c('delete'), cancelLabel: c('cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('performance.strategy.main.destroy', main.id), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={m('title')} description={m('subtitle')}
            actions={can.create && contextSelected ? <Link href={route('performance.strategy.main.create', filters)} className={primaryBtn}><Plus className="h-4 w-4" aria-hidden="true" />{m('add')}</Link> : undefined} />}>
            <Head title={m('title')} />
            <div className="min-w-0 space-y-4">
                <ContextFilter routeName="performance.strategy.main.index" filters={filters} cycles={cycles} organizations={organizations} />

                {summary && (
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Card label={m('totalMain')}>{summary.main_count}</Card>
                        <Card label={m('totalStrategies')}>{summary.strategy_count}</Card>
                        <Card label={m('mainWeightTotal')}><span className={units100(summary.main_total) ? ok : off}>{pct(summary.main_total)}%</span></Card>
                    </div>
                )}

                {contextSelected && (
                    <div className="overflow-x-auto rounded-panel border border-(--app-border) bg-(--app-surface)">
                        <table className={`${wTable} min-w-[900px]`}>
                            <thead>
                                <tr>
                                    <th className={wTh}>{m('mainTitle')}</th>
                                    <th className={`${wTh} w-28`}>{m('weight')}</th>
                                    <th className={wTh}>{m('strategies')}</th>
                                    <th className={`${wTh} w-44`}>{m('strategiesWeightTotal')}</th>
                                    <th className={`${wTh} w-40`}>{c('actions')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {mains.length === 0 && <tr><td colSpan={5} className={`${wTd} text-center text-(--app-muted-foreground)`}>{m('noData')}</td></tr>}
                                {mains.map((main) => (
                                    <tr key={main.id}>
                                        <td className={wTd}>
                                            <p className="font-medium">{main.number}. {main.title}</p>
                                            <p className="text-xs text-(--app-muted-foreground)">{truncate(main.description, 80) || c('none')}</p>
                                        </td>
                                        <td className={`${wTd} font-semibold tabular-nums`}>{pct(main.weight_percent)}%</td>
                                        <td className={wTd}>
                                            {main.strategies.length === 0 ? c('none') : (
                                                <div className="space-y-1 text-xs">
                                                    {main.strategies.map((s) => (
                                                        <div key={s.id} className="flex flex-wrap justify-between gap-2">
                                                            <span>{s.order_no}. {truncate(s.title, 70)}</span>
                                                            <GivenWeight given={s.weight_percent} share={s.normalized} label={m('givenWeight')} />
                                                        </div>
                                                    ))}
                                                    <div className="flex flex-wrap justify-between gap-2 border-t border-(--app-border) pt-1 font-semibold">
                                                        <span>{m('strategiesWeightTotal')}:</span>
                                                        <GivenWeight given={main.strategies_total} share={main.normalized_total} label={m('givenWeight')} />
                                                    </div>
                                                </div>
                                            )}
                                        </td>
                                        <td className={wTd}>
                                            <p className="font-semibold tabular-nums">{pct(main.normalized_total)}%</p>
                                            <BalanceBadge balanced={main.balanced} labels={{ balanced: m('balanced'), needsFix: m('needsFix') }} />
                                        </td>
                                        <td className={wTd}>
                                            <div className="flex flex-wrap gap-3">
                                                <Link href={route('performance.strategy.main.show', main.id)} className={linkBtn}>{c('view')}</Link>
                                                {can.update && <Link href={route('performance.strategy.main.edit', main.id)} className={linkBtn}>{c('edit')}</Link>}
                                                {can.delete && <button type="button" className={dangerLinkBtn} onClick={() => remove(main)}>{c('delete')}</button>}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

const units100 = (value: string) => Math.round(Number(value) * 100) === 10000;
