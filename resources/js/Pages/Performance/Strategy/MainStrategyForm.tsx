import PageHeader from '@/Components/PageHeader';
import { Plus } from '@/Components/Icons';
import { Field, FieldError, Pill, Problems, Section, Table, dangerLinkBtn, fill, formatScore, inputCls, nameOf, primaryBtn, secondaryBtn, smallBtn, tdCls, textareaCls, thCls } from '@/Components/performance/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Named = { id: string; name_en: string; name_am?: string | null };
type StrategyInput = { key: string; id: string | null; title: string; description: string; weight_percent: string; goal_status: string | null };
type Props = {
    main: { id: string; title: string; description: string | null; weight_percent: string; strategies: { id: string; title: string; description: string | null; weight_percent: string; goal_status: string | null }[] } | null;
    remaining: string;
    context: { organization_id: string; cycle_id: string; organization: Named | null; cycle: (Named & { code: string }) | null };
};

let counter = 0;
const newKey = () => `row-${++counter}`;
/** Weights summed in ten-thousandths, the stored scale, so 33.33 + 66.67 is exactly 100. */
const units = (value: string) => Math.round((parseFloat(value) || 0) * 10000);
const ok = 'text-emerald-700 dark:text-emerald-400';
const off = 'text-red-700 dark:text-red-400';

export default function MainStrategyForm({ main, remaining, context }: Props) {
    const { t, locale } = useLocale();
    const blankRow = (): StrategyInput => ({ key: newKey(), id: null, title: '', description: '', weight_percent: '', goal_status: null });
    const form = useForm({
        cycle_id: context.cycle_id,
        organization_id: context.organization_id,
        title: main?.title ?? '',
        description: main?.description ?? '',
        weight_percent: main ? String(Number(main.weight_percent)) : '',
        strategies: main
            ? main.strategies.map((s) => ({ key: newKey(), id: s.id, title: s.title, description: s.description ?? '', weight_percent: String(Number(s.weight_percent)), goal_status: s.goal_status }))
            : [blankRow()],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const total = form.data.strategies.reduce((sum, row) => sum + units(row.weight_percent), 0);
    const balanced = total === units(form.data.weight_percent) && total > 0;
    const overview = route('performance.strategy.index', { cycle_id: context.cycle_id, organization_id: context.organization_id });

    function setRow(index: number, field: 'title' | 'description' | 'weight_percent', value: string) {
        form.setData('strategies', form.data.strategies.map((row, i) => (i === index ? { ...row, [field]: value } : row)));
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

    const heading = main ? t('performance.strategy.editMain') : t('performance.strategy.addMain');

    return (
        <AuthenticatedLayout header={<PageHeader title={heading} description={`${nameOf(context.organization, locale)} · ${context.cycle?.code ?? ''} ${nameOf(context.cycle, locale)}`} backHref={overview} />}>
            <Head title={heading} />
            <form onSubmit={submit} className="min-w-0 space-y-6">
                <Section title={t('performance.strategy.mainStrategy')}>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('performance.strategy.mainStrategy')} htmlFor="ms-title" error={errors.title} className="sm:col-span-2">
                            <input id="ms-title" className={inputCls} value={form.data.title} maxLength={500} onChange={(e) => form.setData('title', e.target.value)} required />
                        </Field>
                        <Field label={t('performance.strategy.weight')} htmlFor="ms-weight" error={errors.weight_percent}
                            help={fill(t('performance.strategy.remainingHint'), { value: formatScore(remaining) })}>
                            <input id="ms-weight" type="number" step="0.01" min="0.01" max="100" className={inputCls} value={form.data.weight_percent} onChange={(e) => form.setData('weight_percent', e.target.value)} required />
                        </Field>
                        <Field label={t('performance.strategy.descriptionField')} htmlFor="ms-description" error={errors.description} className="sm:col-span-3">
                            <textarea id="ms-description" rows={3} className={textareaCls} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </Field>
                    </div>
                </Section>

                <Section flush title={t('performance.strategy.strategies')} description={t('performance.strategy.strategiesHint')}
                    actions={<button type="button" className={smallBtn} onClick={() => form.setData('strategies', [...form.data.strategies, blankRow()])}><Plus className="h-4 w-4" aria-hidden="true" />{t('performance.strategy.addStrategy')}</button>}>
                    <Table head={<>
                        <th className={`${thCls} w-16`}>{t('performance.strategy.serialNo')}</th>
                        <th className={thCls}>{t('performance.strategy.strategy')}</th>
                        <th className={`${thCls} w-40`}>{t('performance.strategy.weight')}</th>
                        <th className={thCls}>{t('performance.strategy.descriptionField')}</th>
                        <th className={`${thCls} w-24`}><span className="sr-only">{t('performance.strategy.removeRow')}</span></th>
                    </>}>
                        {form.data.strategies.map((row, index) => {
                            // A goal under review or published fixes its strategy's title and weight.
                            const locked = row.goal_status !== null && row.goal_status !== 'DRAFT';
                            return (
                                <tr key={row.key}>
                                    <td className={`${tdCls} tabular-nums`}>{index + 1}</td>
                                    <td className={tdCls}>
                                        <input aria-label={`${t('performance.strategy.strategy')} ${index + 1}`} className={inputCls} value={row.title} maxLength={500} disabled={locked} onChange={(e) => setRow(index, 'title', e.target.value)} required />
                                        {row.goal_status && <div className="mt-1"><Pill group="goal" value={row.goal_status} /></div>}
                                        <FieldError message={errors[`strategies.${index}.title`]} />
                                    </td>
                                    <td className={tdCls}>
                                        <input aria-label={`${t('performance.strategy.weight')} ${index + 1}`} type="number" step="0.01" min="0.01" max="100" className={inputCls} value={row.weight_percent} disabled={locked} onChange={(e) => setRow(index, 'weight_percent', e.target.value)} required />
                                        <FieldError message={errors[`strategies.${index}.weight_percent`]} />
                                    </td>
                                    <td className={tdCls}>
                                        <input aria-label={`${t('performance.strategy.descriptionField')} ${index + 1}`} className={inputCls} value={row.description} maxLength={2000} onChange={(e) => setRow(index, 'description', e.target.value)} />
                                    </td>
                                    <td className={`${tdCls} text-right`}>
                                        {form.data.strategies.length > 1 && row.goal_status === null && (
                                            <button type="button" className={dangerLinkBtn} onClick={() => form.setData('strategies', form.data.strategies.filter((_, i) => i !== index))}>{t('performance.strategy.removeRow')}</button>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                        <tr>
                            <td className={`${tdCls} font-semibold`} colSpan={2}>{t('performance.strategy.strategiesTotal')}</td>
                            <td className={`${tdCls} font-semibold tabular-nums ${balanced ? ok : off}`} colSpan={3}>
                                {formatScore(total / 10000)}% / {formatScore(form.data.weight_percent || 0)}%
                            </td>
                        </tr>
                    </Table>
                    <div className="px-5 pb-4"><FieldError message={errors.strategies} /></div>
                </Section>

                <Problems title={t('performance.strategicGoals.readiness')} problems={[errors.cycle_id, errors.organization_id].filter((m): m is string => Boolean(m))} />

                <div className="flex flex-wrap justify-end gap-2">
                    <Link href={overview} className={secondaryBtn}>{t('performance.actions.cancel')}</Link>
                    <button type="submit" className={primaryBtn} disabled={form.processing}>{main ? t('performance.actions.save') : t('performance.actions.create')}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
