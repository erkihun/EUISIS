import { errorCls, inputCls, labelCls, panelCls, scrollCls, secondaryBtn, tableCls, tdCls, textareaCls, thCls, useSm } from '@/Components/serviceMonitoring/helpers';
import type { FormDescription, PrefillRow } from '@/types/serviceMonitoring';
import { router, useForm } from '@inertiajs/react';
import { useId, type JSX } from 'react';
import SaveIndicator from './SaveIndicator';
import { useAutosave } from './useAutosave';

interface Props {
    submissionId: string;
    definition: FormDescription;
    values: Record<string, number | null>;
    prefill: Record<string, PrefillRow>;
    narrative: string | null;
    driverListEmpty: boolean;
    notices: Record<string, string>;
    editable: boolean;
}

type Section2Form = { values: Record<string, string>; narrative: string; override_reasons: Record<string, string> };

/**
 * Section II (D18, D19): one input per field in form order, the system figure
 * beside the three prefilled ones, an override reason when the reported
 * figure differs, and the special-support narrative. Autosaves.
 */
export default function Section2Panel({ submissionId, definition, values, prefill, narrative, driverListEmpty, notices, editable }: Props): JSX.Element {
    const { sm } = useSm();
    const id = useId();
    const fields = definition.section_2.fields;
    const form = useForm<Section2Form>({
        values: Object.fromEntries(fields.map((f) => [f.key, values[f.key] === null || values[f.key] === undefined ? '' : String(values[f.key])])),
        narrative: narrative ?? '',
        override_reasons: Object.fromEntries(Object.entries(prefill).map(([k, p]) => [k, p.override_reason ?? ''])),
    });

    const state = useAutosave(form.isDirty, editable, () => new Promise<void>((resolve) => {
        form.transform((d) => ({ ...d, values: Object.fromEntries(Object.entries(d.values).map(([k, v]) => [k, v === '' ? null : Number(v)])) }));
        form.put(route('service-monitoring.submissions.section2', submissionId), {
            preserveScroll: true, preserveState: true,
            onSuccess: () => form.setDefaults(),
            onFinish: () => resolve(),
        });
    }));

    const differs = (key: string): boolean => {
        const p = prefill[key];
        return !!p && p.system_value !== null && !fields.find((f) => f.key === key)?.reference && form.data.values[key] !== '' && Number(form.data.values[key]) !== p.system_value;
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-semibold text-(--app-foreground)">{definition.section_2.title}</p>
                <div className="flex items-center gap-3">
                    {editable && <SaveIndicator state={state} />}
                    {editable && <button type="button" className={secondaryBtn} onClick={() => router.post(route('service-monitoring.submissions.section2.prefill', submissionId), {}, { preserveScroll: true })}>{sm('edit.refreshPrefill')}</button>}
                </div>
            </div>
            {driverListEmpty && <p className="rounded-panel border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">{notices.driver_list_empty}</p>}
            {definition.section_2.notes.map((n, i) => <p key={i} className="text-xs text-(--app-muted-foreground)">{n}</p>)}

            <div className={scrollCls}>
                <table className={tableCls}>
                    <thead>
                        <tr>
                            <th scope="col" className={thCls}>{definition.section_2.institution_column}</th>
                            <th scope="col" className={thCls}>{sm('edit.section2.reported')}</th>
                            <th scope="col" className={thCls}>{sm('common.notes')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {fields.map((f) => {
                            const p = prefill[f.key];
                            const inputId = `${id}-${f.key}`;
                            const error = form.errors[`values.${f.key}` as keyof typeof form.errors] ?? form.errors[`override_reasons.${f.key}` as keyof typeof form.errors];
                            return (
                                <tr key={f.key}>
                                    <th scope="row" className={`${thCls} font-normal`}>
                                        <label htmlFor={inputId}>{f.group_label ? `${f.group_label} — ` : ''}{f.label}</label>
                                        <p className="text-xs text-(--app-muted-foreground)">{f.aggregation === 'flow' ? sm('edit.section2.flow') : sm('edit.section2.stock')}{f.newly_organized ? ` · ${sm('edit.section2.newlyOrganized')}` : ''}</p>
                                    </th>
                                    <td className={tdCls}>
                                        <input id={inputId} type="number" min={0} inputMode="numeric" className={`${inputCls} max-w-32`} disabled={!editable}
                                            value={form.data.values[f.key]} aria-invalid={error ? true : undefined}
                                            onChange={(e) => form.setData('values', { ...form.data.values, [f.key]: e.target.value })} />
                                        {error && <p className={errorCls}>{error}</p>}
                                    </td>
                                    <td className={tdCls}>
                                        {p && (
                                            <div className="space-y-1">
                                                <span className="inline-block rounded bg-(--app-surface-muted) px-2 py-0.5 text-xs">
                                                    {p.system_value === null ? sm('edit.section2.noSystem') : sm('edit.section2.system', { value: p.system_value })}
                                                </span>
                                                <p className="text-xs text-(--app-muted-foreground)">{notices[f.key === 'cafeteria_count' ? 'cafeterias' : f.key]}</p>
                                                {differs(f.key) && (
                                                    <div>
                                                        <label htmlFor={`${inputId}-reason`} className={labelCls}>{sm('edit.section2.overrideTitle')}</label>
                                                        <p className="text-xs text-(--app-muted-foreground)">{sm('edit.section2.overrideHint', { value: p.system_value ?? '' })}</p>
                                                        <textarea id={`${inputId}-reason`} rows={2} minLength={10} className={textareaCls} disabled={!editable}
                                                            value={form.data.override_reasons[f.key] ?? ''} onChange={(e) => form.setData('override_reasons', { ...form.data.override_reasons, [f.key]: e.target.value })} />
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <div className={`${panelCls} p-3`}>
                <label htmlFor={`${id}-narrative`} className={labelCls}>{definition.section_2.narrative}</label>
                <textarea id={`${id}-narrative`} rows={4} className={textareaCls} disabled={!editable} value={form.data.narrative} onChange={(e) => form.setData('narrative', e.target.value)} />
                {(form.errors as Record<string, string>).section_2 && <p className={errorCls}>{(form.errors as Record<string, string>).section_2}</p>}
            </div>
        </div>
    );
}
