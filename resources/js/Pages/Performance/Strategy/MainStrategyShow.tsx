import PageHeader from '@/Components/PageHeader';
import { dangerBtn, primaryBtn, secondaryBtn } from '@/Components/performance/ui';
import { BalanceBadge, GivenWeight, contextLine, pct, wTable, wTd, wTh, type Can, type Context, type MainRow } from '@/Components/performance/strategy';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router } from '@inertiajs/react';

/** WLMS "Main Strategy Detail". */
export default function MainStrategyShow({ main, can, context }: { main: MainRow; can: Can; context: Context }) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const m = (key: string) => t(`performance.strategy.main.${key}`);
    const c = (key: string) => t(`performance.strategy.common.${key}`);
    const list = route('performance.strategy.main.index', { cycle_id: context.cycle_id, organization_id: context.organization_id });

    async function remove() {
        const { confirmed } = await confirm({ title: m('deleteConfirm'), description: main.title, confirmLabel: c('delete'), cancelLabel: c('cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('performance.strategy.main.destroy', main.id));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={m('showTitle')} description={`${main.title} · ${contextLine(context, locale)}`}
            actions={<Link href={list} className={secondaryBtn}>{m('backToList')}</Link>} />}>
            <Head title={m('showTitle')} />
            <div className="min-w-0 space-y-4">
                <div className="grid gap-3 rounded-panel border border-(--app-border) bg-(--app-surface) p-4 text-sm sm:grid-cols-3">
                    <div><span className="font-medium">{m('mainTitle')}:</span> {main.number}. {main.title}</div>
                    <div><span className="font-medium">{m('weight')}:</span> <span className="tabular-nums">{pct(main.weight_percent)}%</span></div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{m('strategiesWeightTotal')}:</span>
                        <GivenWeight given={main.strategies_total} share={main.normalized_total} label={m('givenWeight')} />
                        <BalanceBadge balanced={main.balanced} labels={{ balanced: m('balanced'), needsFix: m('needsFix') }} />
                    </div>
                </div>

                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-4">
                    <h2 className="text-sm font-semibold">{m('description')}</h2>
                    <p className="mt-1 whitespace-pre-line text-sm text-(--app-muted-foreground)">{main.description || c('none')}</p>
                </section>

                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-4">
                    <h2 className="mb-2 text-sm font-semibold">{m('strategies')}</h2>
                    <div className="overflow-x-auto">
                        <table className={`${wTable} min-w-[720px]`}>
                            <thead>
                                <tr>
                                    <th className={`${wTh} w-16`}>{m('serialNo')}</th>
                                    <th className={wTh}>{m('strategyTitle')}</th>
                                    <th className={`${wTh} w-56`}>{m('weight')}</th>
                                    <th className={wTh}>{m('description')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {main.strategies.length === 0 && <tr><td colSpan={4} className={`${wTd} text-center`}>{m('noData')}</td></tr>}
                                {main.strategies.map((s) => (
                                    <tr key={s.id}>
                                        <td className={`${wTd} text-center`}>{s.order_no}</td>
                                        <td className={wTd}>{s.title}</td>
                                        <td className={wTd}><GivenWeight given={s.weight_percent} share={s.normalized} label={m('givenWeight')} /></td>
                                        <td className={wTd}>{s.description || c('none')}</td>
                                    </tr>
                                ))}
                            </tbody>
                            {main.strategies.length > 0 && (
                                <tfoot>
                                    <tr>
                                        <td className={wTd} />
                                        <td className={`${wTd} font-semibold`}>{m('strategiesWeightTotal')}</td>
                                        <td className={`${wTd} font-semibold`}><GivenWeight given={main.strategies_total} share={main.normalized_total} label={m('givenWeight')} /></td>
                                        <td className={wTd} />
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                </section>

                <div className="flex justify-end gap-2">
                    {can.update && <Link href={route('performance.strategy.main.edit', main.id)} className={primaryBtn}>{c('edit')}</Link>}
                    {can.delete && <button type="button" className={dangerBtn} onClick={remove}>{c('delete')}</button>}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
