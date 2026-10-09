import { useLocale } from '@/hooks/useLocale';
import type { TaskScores, TaskStandardView } from './types';

/*
 * Work execution register (የባለሞያ የእለት እቅድ ክንውን መመዝገቢያ): the read-only
 * standard an employee works against, and the performance scores.
 *
 * Scores shown while editing are a PREVIEW of the same formulas; the server
 * recalculates on every save and only its values are kept.
 */

/** A decimal string or number as a percent with up to two decimals. */
export function percent(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const number = Number(value);
    return Number.isFinite(number) ? `${(Math.round(number * 100) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 })}%` : '—';
}

/** Trailing zeros off a NUMERIC string ("10.0000" → "10"). */
export function plain(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const number = Number(value);
    return Number.isFinite(number) ? number.toLocaleString(undefined, { maximumFractionDigits: 4 }) : String(value);
}

/** Minutes as HH:MM. */
export function hoursMinutes(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined || minutes <= 0) return '—';
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

/** Same-day minutes between two HH:MM values; null when incomplete or not after start. */
export function minutesBetween(start: string | null | undefined, end: string | null | undefined): number | null {
    if (!start || !end) return null;
    const toMinutes = (value: string) => { const [h, m] = value.slice(0, 5).split(':').map(Number); return h * 60 + m; };
    const diff = toMinutes(end) - toMinutes(start);
    return diff > 0 ? diff : null;
}

/**
 * Preview of the form's formulas. Quantity and Quality = Actual / Plan × 100;
 * Time = Plan / Actual × 100; the task aggregate averages the measured
 * dimensions. Never capped, never divided by zero.
 */
export function previewScores(
    standard: TaskStandardView,
    actual: { quantity: string | number | null | undefined; minutes: number | null; quality: string | number | null | undefined },
    rule: 'applicable_average' | 'all_three' = 'applicable_average',
): TaskScores {
    const ratio = (top: number, bottom: number) => (bottom > 0 && top >= 0 ? (top / bottom) * 100 : null);
    const num = (value: string | number | null | undefined) => (value === null || value === undefined || value === '' ? null : Number(value));

    const parts: (number | null)[] = [];
    const quantity = standard.planned_quantity !== null && num(actual.quantity) !== null ? ratio(num(actual.quantity)!, Number(standard.planned_quantity)) : null;
    const time = standard.planned_time_minutes !== null && actual.minutes !== null ? ratio(Number(standard.planned_time_minutes), actual.minutes) : null;
    const quality = standard.planned_quality !== null && num(actual.quality) !== null ? ratio(num(actual.quality)!, Number(standard.planned_quality)) : null;

    if (standard.planned_quantity !== null) parts.push(quantity);
    if (standard.planned_time_minutes !== null) parts.push(time);
    if (standard.planned_quality !== null) parts.push(quality);

    const complete = parts.length > 0 && parts.every((part) => part !== null) && (rule === 'applicable_average' || parts.length === 3);
    const task = complete ? (parts as number[]).reduce((sum, part) => sum + part, 0) / parts.length : null;
    const text = (value: number | null) => (value === null ? null : String(value));

    return { quantity: text(quantity), time: text(time), quality: text(quality), task: text(task) };
}

/** The standard measure and BPR plan, read-only. */
export function StandardSummary({ standard }: { standard: TaskStandardView }) {
    const { t } = useLocale();
    const rows: [string, string][] = [];

    if (standard.standard_measure) rows.push([t('dailyActivities.work.standardMeasure'), standard.standard_measure]);
    if (standard.planned_quantity !== null) rows.push([t('dailyActivities.work.planQuantity'), `${plain(standard.planned_quantity)} ${standard.quantity_unit ?? ''}`.trim()]);
    if (standard.planned_time_minutes !== null) rows.push([t('dailyActivities.work.planTime'), `${plain(standard.planned_time_minutes)} ${t('dailyActivities.work.minutes')}`]);
    if (standard.planned_quality !== null) rows.push([t('dailyActivities.work.planQuality'), `${plain(standard.planned_quality)} ${standard.quality_unit ?? ''}`.trim()]);
    if (standard.planned_quality !== null && standard.quality_measure) rows.push([t('dailyActivities.work.qualityMeasure'), standard.quality_measure]);
    if (standard.bpr_reference) rows.push([t('dailyActivities.work.bprReference'), standard.bpr_reference]);

    return (
        <div className="rounded-lg border border-[color:var(--color-primary)]/20 bg-[color:var(--color-primary)]/5 p-3">
            <p className="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs font-semibold text-gray-700 dark:text-slate-200">
                <span>{t('dailyActivities.work.standardTitle')}</span>
                {standard.version_no !== null && <span className="font-normal text-gray-500 dark:text-slate-400">{t('dailyActivities.work.version')} {standard.version_no}</span>}
            </p>
            <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[10rem_1fr]">
                {rows.map(([label, value]) => (
                    <div key={label} className="contents">
                        <dt className="text-gray-500 dark:text-slate-400">{label}</dt>
                        <dd className="break-words text-gray-900 dark:text-slate-100">{value}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

/** Quantity, time, quality and aggregate performance. */
export function ScoreRow({ scores, preview = false }: { scores: TaskScores; preview?: boolean }) {
    const { t } = useLocale();
    const cells: [string, string | null][] = [
        [t('dailyActivities.work.quantityScore'), scores.quantity],
        [t('dailyActivities.work.timeScore'), scores.time],
        [t('dailyActivities.work.qualityScore'), scores.quality],
    ];

    return (
        <div aria-live={preview ? 'polite' : undefined}>
            <p className="mb-1 text-xs text-gray-500 dark:text-slate-400">{t(preview ? 'dailyActivities.work.previewNote' : 'dailyActivities.work.performance')}</p>
            <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {cells.filter(([, value]) => value !== null).map(([label, value]) => (
                    <div key={label} className="rounded-lg bg-gray-50 px-3 py-2 dark:bg-slate-800/60">
                        <dt className="text-xs text-gray-500 dark:text-slate-400">{label}</dt>
                        <dd className="text-sm font-semibold tabular-nums text-gray-900 dark:text-slate-100">{percent(value)}</dd>
                    </div>
                ))}
                <div className="rounded-lg bg-[color:var(--color-primary)]/10 px-3 py-2">
                    <dt className="text-xs text-gray-600 dark:text-slate-300">{t('dailyActivities.work.taskScore')}</dt>
                    <dd className="text-sm font-bold tabular-nums text-[color:var(--color-primary)]">{scores.task === null ? t('dailyActivities.work.incomplete') : percent(scores.task)}</dd>
                </div>
            </dl>
        </div>
    );
}
