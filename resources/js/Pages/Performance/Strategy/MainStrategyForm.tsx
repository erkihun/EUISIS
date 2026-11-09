import PageHeader from '@/Components/PageHeader';
import { FieldError, Problems, dangerLinkBtn, inputCls, labelCls, primaryBtn, secondaryBtn, smallBtn, textareaCls } from '@/Components/performance/ui';
import { boxCls, contextLine, off, ok, pct, units, wTable, wTd, wTh, type Context } from '@/Components/performance/strategy';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type StrategyInput = { key: string; id: string | null; title: string; description: string; weight_percent: string; goal_status: string | null };
type Props = {
    main: { id: string; title: string; description: string | null; weight_percent: string; strategies: { id: string; title: string; description: string | null; weight_percent: string; goal_status: string | null }[] } | null;
    remaining: string;
    context: Context;
};

let counter = 0;
const newKey = () => `row-${++counter}`;

/** WLMS "Create / Edit Main Strategy": the main strategy, then its strategies table with a live total. */
export default function MainStrategyForm({ main, context }: Props) {
    const { t, locale } = useLocale();
    const m = (key: string) => t(`performance.strategy.main.${key}`);
    const c = (key: string) => t(`performance.strategy.common.${key}`);
    const row = (weight = ''): StrategyInput => ({ key: newKey(), id: null, title: '', description: '', weight_percent: weight, goal_status: null });
    const form = useForm({
        cycle_id: context.cycle_id,
        organization_id: context.organization_id,
        title: main?.title ?? '',
        description: main?.description ?? '',
        weight_percent: main ? String(Number(main.weight_percent)) : '',
        // A new main strategy starts with two strategies at 50% each, as in WLMS.
        strategies: main
            ? main.strategies.map((s) => ({ key: newKey(), id: s.id, title: s.title, description: s.description ?? '', weight_percent: String(Number(s.weight_percent)), goal_status: s.goal_status }))
            : [row('50'), row('50')],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const total = form.data.strategies.reduce((sum, r) => sum + units(r.weight_percent), 0);
    const balanced = total === units(form.data.weight_percent);
    const list = route('performance.strategy.main.index', { cycle_id: context.cycle_id, organization_id: context.organization_id });
    const strategies = form.data.strategies;

    function setRow(index: number, field: 'title' | 'description' | 'weight_percent', value: string) {
        form.setData('strategies', strategies.map((r, i) => (i === index ? { ...r, [field]: value } : r)));
    }

    function removeAt(index: number) {
        const next = strategies.filter((_, i) => i !== index);
        form.setData('strategies', next.length > 0 ? next : [row()]);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => ({
            ...(main ? {} : { cycle_id: data.cycle_id, organization_id: data.organization_id }),
            title: data.title,
            description: data.description || null,
            weight_percent: data.weight_percent,
            strategies: data.strategies.map(({ id, title, description, weight_percent }) => ({ id, title, description: description || null, weight_percent })),
        }));
        if (main) form.put(route('performance.strategy.main.update', main.id));
        else form.post(route('performance.strategy.main.store'));
    }

    const heading = main ? m('editTitle') : m('createTitle');
    const lastRemovable = strategies.length > 1 && strategies[strategies.length - 1].goal_status === null;

    return (
        <AuthenticatedLayout header={<PageHeader title={heading} description={`${m('createSubtitle')} · ${contextLine(context, locale)}`}
            actions={<Link href={list} className={secondaryBtn}>{m('backToList')}</Link>} />}>
            <Head title={heading} />
            <form onSubmit={submit} className="grid min-w-0 gap-4 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                <div className="grid gap-3 md:grid-cols-2">
                    <div>
                        <label htmlFor="ms-title" className={labelCls}>{m('mainTitle')}</label>
                        <input id="ms-title" className={inputCls} value={form.data.title} maxLength={500} onChange={(e) => form.setData('title', e.target.value)} required />
                        <FieldError message={errors.title} />
                    </div>
                    <div>
                        <label htmlFor="ms-weight" className={labelCls}>{m('weight')}</label>
                        <div className="flex items-center gap-2">
                            <input id="ms-weight" type="number" step="0.01" min="0.01" max="100" className={inputCls} value={form.data.weight_percent} onChange={(e) => form.setData('weight_percent', e.target.value)} required />
                            <span>%</span>
                        </div>
                        <FieldError message={errors.weight_percent} />
                    </div>
                </div>

                <div>
                    <label htmlFor="ms-description" className={labelCls}>{m('description')}</label>
                    <textarea id="ms-description" rows={3} className={textareaCls} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    <FieldError message={errors.description} />
                </div>

                <div className={boxCls}>
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold">{m('strategies')}</h3>
                        <div className="flex gap-2">
                            <button type="button" className={smallBtn} onClick={() => form.setData('strategies', [...strategies, row()])}>{m('addStrategy')}</button>
                            <button type="button" className={smallBtn} disabled={!lastRemovable} onClick={() => form.setData('strategies', strategies.slice(0, -1))}>{m('removeStrategy')}</button>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className={`${wTable} min-w-[920px]`}>
                            <thead>
                                <tr>
                                    <th className={`${wTh} w-[70px]`}>{m('serialNo')}</th>
                                    <th className={wTh}>{m('strategyTitle')}</th>
                                    <th className={`${wTh} w-[180px]`}>{m('weight')}</th>
                                    <th className={wTh}>{m('description')}</th>
                                    <th className={`${wTh} w-[60px]`}><span className="sr-only">{c('delete')}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {strategies.map((r, index) => {
                                    // A goal under review or published fixes its strategy's title and weight.
                                    const locked = r.goal_status !== null && r.goal_status !== 'DRAFT';
                                    return (
                                        <tr key={r.key}>
                                            <td className={`${wTd} text-center font-medium`}>{index + 1}</td>
                                            <td className={wTd}>
                                                <input aria-label={`${m('strategyTitle')} ${index + 1}`} className={inputCls} value={r.title} maxLength={500} disabled={locked} onChange={(e) => setRow(index, 'title', e.target.value)} required />
                                                <FieldError message={errors[`strategies.${index}.title`]} />
                                            </td>
                                            <td className={wTd}>
                                                <div className="flex items-center gap-2">
                                                    <input aria-label={`${m('weight')} ${index + 1}`} type="number" step="0.01" min="0.01" max="100" className={inputCls} value={r.weight_percent} disabled={locked} onChange={(e) => setRow(index, 'weight_percent', e.target.value)} required />
                                                    <span>%</span>
                                                </div>
                                                <FieldError message={errors[`strategies.${index}.weight_percent`]} />
                                            </td>
                                            <td className={wTd}>
                                                <input aria-label={`${m('description')} ${index + 1}`} className={inputCls} value={r.description} maxLength={2000} onChange={(e) => setRow(index, 'description', e.target.value)} />
                                            </td>
                                            <td className={`${wTd} text-center`}>
                                                {r.goal_status === null && <button type="button" className={dangerLinkBtn} onClick={() => removeAt(index)}>{c('delete')}</button>}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td className={wTd} />
                                    <td className={`${wTd} font-semibold`}>{m('strategiesWeightTotal')}</td>
                                    <td className={`${wTd} text-center font-semibold tabular-nums ${balanced ? ok : off}`}>{pct(total / 10000)}%</td>
                                    <td className={`${wTd} text-xs text-(--app-muted-foreground)`} colSpan={2}>{m('strategiesWeightHint')}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <FieldError message={errors.strategies} />
                </div>

                <Problems title={t('performance.strategicGoals.readiness')} problems={[errors.cycle_id, errors.organization_id].filter((x): x is string => Boolean(x))} />

                <div className="flex items-center justify-end gap-2">
                    <Link href={list} className={secondaryBtn}>{c('cancel')}</Link>
                    <button type="submit" className={primaryBtn} disabled={form.processing}>{main ? c('update') : c('create')}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
