import { errorCls, inputCls, labelCls, panelCls, scrollCls, tdCls, thCls, useSm } from '@/Components/serviceMonitoring/helpers';
import type { FormDescription } from '@/types/serviceMonitoring';
import { useForm } from '@inertiajs/react';
import { useId, type JSX } from 'react';
import SaveIndicator from './SaveIndicator';
import { useAutosave } from './useAutosave';

const COUNTS = ['main_above', 'main_at', 'main_below', 'sub_above', 'sub_at', 'sub_below'] as const;

interface Props {
    submissionId: string;
    definition: FormDescription;
    totals: { main_total: number | null; sub_total: number | null };
    values: Record<string, number | string | null>;
    minText: number;
    editable: boolean;
}

/** Section IV (D21): above / at / below standard for main and sub services, with the running sum check. Autosaves. */
export default function Section4Panel({ submissionId, definition, totals, values, minText, editable }: Props): JSX.Element {
    const { sm } = useSm();
    const id = useId();
    const c = definition.section_4.columns;
    const form = useForm<Record<string, string>>({
        ...Object.fromEntries(COUNTS.map((k) => [k, values[k] === null || values[k] === undefined ? '' : String(values[k])])),
        below_reasons: String(values.below_reasons ?? ''),
        actions_taken: String(values.actions_taken ?? ''),
    });

    const state = useAutosave(form.isDirty, editable, () => new Promise<void>((resolve) => {
        form.transform((d) => ({ ...Object.fromEntries(COUNTS.map((k) => [k, d[k] === '' ? null : Number(d[k])])), below_reasons: d.below_reasons || null, actions_taken: d.actions_taken || null }));
        form.put(route('service-monitoring.submissions.section4', submissionId), { preserveScroll: true, preserveState: true, onSuccess: () => form.setDefaults(), onFinish: () => resolve() });
    }));

    const n = (k: string) => Number(form.data[k] || 0);
    const sums = { main: n('main_above') + n('main_at') + n('main_below'), sub: n('sub_above') + n('sub_at') + n('sub_below') };
    const below = n('main_below') + n('sub_below') > 0;

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-semibold text-(--app-foreground)">{definition.section_4.title}</p>
                {editable && <SaveIndicator state={state} />}
            </div>
            <div className={scrollCls}>
                <table className="min-w-full border-collapse">
                    <thead>
                        <tr>
                            <th scope="col" className={thCls} />
                            <th scope="col" className={thCls}>{c.main_total} / {c.sub_total}</th>
                            <th scope="col" className={thCls}>{c.above}</th>
                            <th scope="col" className={thCls}>{c.at}</th>
                            <th scope="col" className={thCls}>{c.below}</th>
                            <th scope="col" className={thCls}>{sm('common.status')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {(['main', 'sub'] as const).map((level) => {
                            const total = level === 'main' ? totals.main_total : totals.sub_total;
                            const ok = total !== null && sums[level] === total;
                            return (
                                <tr key={level}>
                                    <th scope="row" className={thCls}>{level === 'main' ? c.main_group : c.sub_group}</th>
                                    <td className={`${tdCls} tabular-nums`}>{total ?? '—'}</td>
                                    {(['above', 'at', 'below'] as const).map((part) => {
                                        const key = `${level}_${part}`;
                                        return (
                                            <td key={key} className={tdCls}>
                                                <input type="number" min={0} aria-label={`${level === 'main' ? c.main_group : c.sub_group}: ${c[part]}`} className={`${inputCls} max-w-24`} disabled={!editable}
                                                    value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} />
                                            </td>
                                        );
                                    })}
                                    <td className={`${tdCls} text-xs ${ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-400'}`} aria-live="polite">
                                        {ok ? sm('edit.section4.sumOk', { total: total ?? 0 }) : sm('edit.section4.sumWrong', { sum: sums[level], total: total ?? '—' })}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <div className={`${panelCls} grid gap-3 p-3 sm:grid-cols-2`}>
                {below && <p className="text-xs text-amber-800 sm:col-span-2 dark:text-amber-200">{sm('edit.section4.belowRequired', { min: minText })}</p>}
                {(['below_reasons', 'actions_taken'] as const).map((key) => (
                    <div key={key}>
                        <label htmlFor={`${id}-${key}`} className={labelCls}>{c[key]}</label>
                        <textarea id={`${id}-${key}`} rows={3} className={inputCls} disabled={!editable} required={below} minLength={below ? minText : undefined}
                            value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} />
                        {form.errors[key] && <p className={errorCls}>{form.errors[key]}</p>}
                    </div>
                ))}
            </div>
        </div>
    );
}
