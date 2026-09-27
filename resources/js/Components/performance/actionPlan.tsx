import { useLocale } from '@/hooks/useLocale';
import { gregorianIsoToEthiopian } from '@/lib/calendar/ethiopianCalendar';
import { nameOf, tableCls, titleOf } from '@/Components/performance/ui';
import { Fragment, type ReactNode } from 'react';

/*
 * The plan as an action-plan table (ድርጊት መርሃ ግብር): a row per strategic
 * goal with its weight, then a row per objective / KPI with number, weight,
 * unit, baseline and target, and the schedule by quarter and month.
 */

type PeriodTarget = { period_type: 'QUARTER' | 'MONTH'; period_number: number; target_value: string | null; target_numerator: string | null; target_denominator: string | null };
type PlanTarget = {
    id: string; target_value: string | null; target_numerator: string | null; target_denominator: string | null; baseline_value: string | null; weight: string;
    period_targets: PeriodTarget[];
    kpi: { code: string; name_en: string; name_am: string | null; unit: string | null; measurement?: string };
};
type PlanObjective = { id: string; code: string; title_en: string; title_am: string | null; weight: string; absolute_weight_percent: string | null; status: string; strategic_goal_id: string | null; targets: PlanTarget[] };
type Goal = { id: string; code: string; name_en: string; name_am: string | null; weight_percent: string };

/** Up to four decimals, no trailing zeros: 2.5, 40000, 11.7647. */
function num(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '';
    const n = Number(value);
    return Number.isFinite(n) ? n.toLocaleString(undefined, { maximumFractionDigits: 4 }) : String(value);
}

/**
 * Names of the cycle's twelve months. A cycle that starts on the first day of
 * an Ethiopian month (a fiscal year from ሐምሌ 1) is counted in Ethiopian
 * months, one that starts on the 1st of a Gregorian month in Gregorian months;
 * any other start date gets plain numbers, since its months have no name.
 */
export function useCycleMonths(start: string | null | undefined): string[] {
    const { t } = useLocale();
    const numbered = Array.from({ length: 12 }, (_, i) => `${t('performance.plans.monthShort')}${i + 1}`);
    if (!start) return numbered;

    const eth = gregorianIsoToEthiopian(start);
    if (eth && eth.day === 1 && eth.month <= 12) {
        // Twelve working months; ጳጉሜ belongs to the month before it.
        return Array.from({ length: 12 }, (_, i) => t(`calendar.monthsEth.${((eth.month - 1 + i) % 12) + 1}`));
    }
    const [, month, day] = start.split('-').map(Number);
    if (day === 1 && month >= 1 && month <= 12) {
        return Array.from({ length: 12 }, (_, i) => t(`calendar.months.${((month - 1 + i) % 12) + 1}`));
    }

    return numbered;
}

export function quarterLabel(t: (key: string) => string, n: number): string {
    return t('performance.plans.quarterN').replace(':n', String(n));
}

export function ActionPlanTable({ objectives, goals, cycleStart }: { objectives: PlanObjective[]; goals: Goal[]; cycleStart: string | null | undefined }) {
    const { t, locale } = useLocale();
    const months = useCycleMonths(cycleStart);
    const active = objectives.filter((o) => o.status !== 'REJECTED');
    const known = new Set(goals.map((g) => g.id));
    const groups: { goal: Goal | null; objectives: PlanObjective[] }[] = [
        ...goals.map((goal) => ({ goal, objectives: active.filter((o) => o.strategic_goal_id === goal.id) })).filter((g) => g.objectives.length > 0),
        { goal: null, objectives: active.filter((o) => !o.strategic_goal_id || !known.has(o.strategic_goal_id)) },
    ].filter((g) => g.objectives.length > 0);
    const withGoals = groups.some((g) => g.goal !== null);

    const th = 'border border-gray-200 px-2 py-2 text-center align-middle font-semibold dark:border-slate-700';
    const td = 'border border-gray-200 px-2 py-2 align-top dark:border-slate-700';
    const cell = `${td} text-center tabular-nums whitespace-nowrap`;
    const isPercent = (target: PlanTarget) => target.kpi.measurement === 'PERCENTAGE';
    const value = (target: PlanTarget, v: string | null, numerator?: string | null, denominator?: string | null) =>
        numerator !== null && numerator !== undefined && numerator !== '' ? `${num(numerator)} / ${num(denominator)}` : v === null || v === '' ? '' : `${num(v)}${isPercent(target) ? '%' : ''}`;
    const unit = (target: PlanTarget) => target.kpi.unit || (target.kpi.measurement ? t(`performance.enums.measurement.${target.kpi.measurement}`) : '');
    const dash = <span className="text-gray-300 dark:text-slate-600">—</span>;

    function schedule(target: PlanTarget | null): ReactNode {
        const rows = target?.period_targets ?? [];
        const monthly = rows.filter((r) => r.period_type === 'MONTH');
        if (target && monthly.length === 0 && rows.some((r) => r.period_type === 'QUARTER')) {
            return [1, 2, 3, 4].map((q) => {
                const row = rows.find((r) => r.period_type === 'QUARTER' && r.period_number === q);
                return <td key={`q${q}`} colSpan={3} className={cell}>{row ? value(target, row.target_value, row.target_numerator, row.target_denominator) : dash}</td>;
            });
        }
        return months.map((_, i) => {
            const row = monthly.find((r) => r.period_number === i + 1);
            return <td key={`m${i}`} className={cell}>{target && row ? value(target, row.target_value, row.target_numerator, row.target_denominator) : dash}</td>;
        });
    }

    function row(key: string, code: string, title: string, weight: string | number | null, target: PlanTarget | null, strong = false) {
        return (
            <tr key={key} className={strong ? 'bg-gray-50/60 dark:bg-slate-900/60' : undefined}>
                <td className={`${td} whitespace-nowrap font-mono text-xs`}>{code}</td>
                <td className={`${td} min-w-[16rem] ${strong ? 'font-medium' : ''}`}>{title}</td>
                <td className={cell}>{num(weight)}</td>
                <td className={`${td} whitespace-nowrap text-center`}>{target ? unit(target) : ''}</td>
                <td className={cell}>{target ? value(target, target.baseline_value) : ''}</td>
                <td className={`${cell} font-semibold`}>{target ? value(target, target.target_value, target.target_numerator, target.target_denominator) : ''}</td>
                {schedule(target)}
            </tr>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className={`${tableCls} border-collapse text-xs`}>
                <thead className="bg-gray-50 text-gray-600 dark:bg-slate-950 dark:text-slate-300">
                    <tr>
                        <th rowSpan={3} className={th}>{t('performance.plans.actionPlanNo')}</th>
                        <th rowSpan={3} className={`${th} text-left`}>{t('performance.plans.actionPlanItem')}</th>
                        <th rowSpan={3} className={th}>{t('performance.fields.weight')}</th>
                        <th rowSpan={3} className={th}>{t('performance.plans.actionPlanUnit')}</th>
                        <th rowSpan={3} className={th}>{t('performance.fields.baseline')}</th>
                        <th rowSpan={3} className={th}>{t('performance.fields.target')}</th>
                        <th colSpan={12} className={th}>{t('performance.plans.actionPlanSchedule')}</th>
                    </tr>
                    <tr>{[1, 2, 3, 4].map((q) => <th key={q} colSpan={3} className={th}>{quarterLabel(t, q)}</th>)}</tr>
                    <tr>{months.map((m, i) => <th key={i} className={`${th} whitespace-nowrap font-medium`}>{m}</th>)}</tr>
                </thead>
                <tbody>
                    {groups.map(({ goal, objectives: list }) => (
                        <Fragment key={goal?.id ?? 'other'}>
                            {withGoals && (
                                <tr className="bg-[color:var(--color-primary)]/10">
                                    <td className={`${td} whitespace-nowrap font-mono text-xs font-semibold`}>{goal?.code ?? ''}</td>
                                    <td colSpan={17} className={`${td} font-semibold text-gray-900 dark:text-slate-100`}>
                                        {goal ? `${nameOf(goal, locale)} (${num(goal.weight_percent)}%)` : t('performance.plans.actionPlanOther')}
                                    </td>
                                </tr>
                            )}
                            {list.map((objective) => {
                                const weight = objective.absolute_weight_percent ?? objective.weight;
                                if (objective.targets.length === 1) {
                                    return row(objective.id, objective.code, titleOf(objective, locale), weight, objective.targets[0]);
                                }
                                // A heading row for the objective, then one row per KPI with its share of the weight.
                                return (
                                    <Fragment key={objective.id}>
                                        {row(objective.id, objective.code, titleOf(objective, locale), weight, null, true)}
                                        {objective.targets.map((target) => row(target.id, target.kpi.code, nameOf(target.kpi, locale), Number(weight) * Number(target.weight) / 100, target))}
                                    </Fragment>
                                );
                            })}
                        </Fragment>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
