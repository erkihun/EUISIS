import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { FormRow, inputCls, primaryBtn, secondaryBtn, useEnumLabel } from '@/Components/grievances/ui';
import { useLocale } from '@/hooks/useLocale';
import { useForm } from '@inertiajs/react';
import { useEffect, useId, useState, type ReactNode } from 'react';
import type { Bilingual, HandlerOptions, HandlerRef } from '@/types/grievances';
import { nameOf } from '@/Components/grievances/ui';

export type AdminValue = string | number | boolean | null | File | string[] | number[];
export type AdminData = Record<string, AdminValue>;
export type Choice = { value: string; label: string };
export type AdminField = {
    key: string; label?: string; type?: 'text' | 'textarea' | 'number' | 'boolean' | 'date' | 'select' | 'lookup' | 'file' | 'numbers' | 'multiselect';
    required?: boolean; min?: number; max?: number; maxLength?: number; pattern?: string; accept?: string; help?: string;
    options?: Choice[]; lookup?: 'employees' | 'units' | 'positions' | 'users'; organizationKey?: string; initialLabel?: string;
    visible?: (data: AdminData) => boolean; clearOnChange?: string[]; enumGroup?: string;
};

/** Lookup results are scoped by the existing grievance endpoints; IDs are never typed. */
export function AdminLookup({ endpoint, organizationId, value, initialLabel, onChange, disabled, required, id }: {
    endpoint: NonNullable<AdminField['lookup']>; organizationId?: string; value: string; initialLabel?: string;
    onChange: (value: string) => void; disabled?: boolean; required?: boolean; id?: string;
}) {
    const { t, locale } = useLocale();
    const [query, setQuery] = useState('');
    const [choices, setChoices] = useState<Choice[]>([]);
    const [selected, setSelected] = useState<Choice | null>(value && initialLabel ? { value, label: initialLabel } : null);
    const [state, setState] = useState<'loading' | 'ready' | 'error'>('ready');
    useEffect(() => {
        if (disabled || (endpoint === 'units' && !organizationId)) { setChoices([]); return; }
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setState('loading');
            try {
                const params = new URLSearchParams({ q: query });
                if (organizationId) params.set('organization_id', organizationId);
                const response = await fetch(`${route(`grievances.lookup.${endpoint}`)}?${params}`, { headers: { Accept: 'application/json' }, signal: controller.signal });
                if (!response.ok) throw new Error('Lookup failed');
                const rows = await response.json() as Record<string, string | number | null>[];
                setChoices(rows.map(row => ({ value: String(row.id), label: String((locale === 'am' ? row.name_am || row.title_am || row.name : row.name_en || row.title_en) || row.name || row.name_en || row.title_en || '') + (row.employee_number ? ` (${row.employee_number})` : '') })));
                setState('ready');
            } catch { if (!controller.signal.aborted) setState('error'); }
        }, 250);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [endpoint, organizationId, query, locale, disabled]);
    const all = selected && !choices.some(choice => choice.value === selected.value) ? [selected, ...choices] : choices;
    return <div className="space-y-2">
        {endpoint !== 'units' && !disabled && <input aria-label={t('grievanceAdmin.searchLookup')} className={inputCls} value={query} onChange={event => setQuery(event.target.value)} placeholder={t('grievanceAdmin.searchLookup')} />}
        <select id={id} className={inputCls} value={value} required={required} disabled={disabled || (endpoint === 'units' && !organizationId)} onChange={event => { onChange(event.target.value); setSelected(all.find(choice => choice.value === event.target.value) ?? null); }}>
            <option value="">{t('grievances.common.select')}</option>
            {all.map(choice => <option key={choice.value} value={choice.value}>{choice.label}</option>)}
        </select>
        {state === 'loading' && <p role="status" className="text-xs text-gray-500">{t('grievances.common.loading')}</p>}
        {state === 'error' && <p role="alert" className="text-xs text-red-600">{t('grievanceAdmin.lookupError')}</p>}
        {state === 'ready' && !all.length && !disabled && <p className="text-xs text-gray-500">{t(endpoint === 'units' && !organizationId ? 'grievanceAdmin.chooseOrganization' : 'grievanceAdmin.noMatches')}</p>}
    </div>;
}

export function AdminForm({ initial, fields, url, method = 'post', editable = true, onSaved, onCancel, children, submitLabel }: {
    initial: AdminData; fields: AdminField[]; url: string; method?: 'post' | 'patch' | 'put'; editable?: boolean;
    onSaved?: () => void; onCancel?: () => void; children?: ReactNode; submitLabel?: string;
}) {
    const { t } = useLocale();
    const label = useEnumLabel();
    const id = useId();
    const form = useForm<AdminData>(initial);
    const errors = form.errors as Record<string, string>;
    const change = (field: AdminField, value: AdminValue) => form.setData(data => ({ ...data, [field.key]: value, ...Object.fromEntries((field.clearOnChange ?? []).map(key => [key, ''])) }));
    return <form onSubmit={event => {
        event.preventDefault();
        form.transform(data => Object.fromEntries(Object.entries(data).map(([key, value]) => [key, value === '' ? null : value])));
        form.submit(method, url, { preserveScroll: true, onSuccess: () => { form.setDefaults(); onSaved?.(); } });
    }} className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">{fields.filter(field => !field.visible || field.visible(form.data)).map(field => {
            const fieldId = `${id}-${field.key}`;
            const value = form.data[field.key];
            const disabled = !editable || form.processing;
            const title = field.label ?? t(`grievanceAdmin.fields.${field.key}`);
            const fieldError = Object.entries(errors).filter(([key]) => key === field.key || key.startsWith(`${field.key}.`)).map(([, error]) => error).join(' ');
            const common = { id: fieldId, disabled, required: field.required, className: inputCls, 'aria-invalid': Boolean(fieldError), 'aria-describedby': fieldError ? `${fieldId}-error` : undefined };
            let control: ReactNode;
            if (field.type === 'boolean') control = <input {...common} className="h-5 w-5 rounded border-gray-300" type="checkbox" checked={Boolean(value)} onChange={event => change(field, event.target.checked)} />;
            else if (field.type === 'date') control = <LocalizedDatePicker id={fieldId} disabled={disabled} required={field.required} className={inputCls} value={String(value ?? '')} onChange={date => change(field, date)} />;
            else if (field.type === 'lookup') control = <AdminLookup id={fieldId} endpoint={field.lookup!} organizationId={field.organizationKey ? String(form.data[field.organizationKey] ?? '') : undefined} value={String(value ?? '')} initialLabel={field.initialLabel} disabled={disabled} required={field.required} onChange={selection => change(field, selection)} />;
            else if (field.type === 'textarea') control = <textarea {...common} rows={field.key === 'body_template' ? 10 : 3} maxLength={field.maxLength ?? 2000} value={String(value ?? '')} onChange={event => change(field, event.target.value)} />;
            else if (field.type === 'select' || field.type === 'multiselect') control = <select {...common} multiple={field.type === 'multiselect'} value={field.type === 'multiselect' ? (value as string[] ?? []) : String(value ?? '')} onChange={event => change(field, field.type === 'multiselect' ? Array.from(event.target.selectedOptions).map(option => option.value) : event.target.value)}>
                {field.type !== 'multiselect' && <option value="">{t(field.required ? 'grievances.common.select' : 'grievances.common.all')}</option>}
                {field.options?.map(option => <option key={option.value} value={option.value}>{field.enumGroup ? label(field.enumGroup, option.value) : option.label}</option>)}
            </select>;
            else if (field.type === 'file') control = <input {...common} type="file" accept={field.accept} onChange={event => change(field, event.target.files?.[0] ?? null)} />;
            else if (field.type === 'numbers') control = <Thresholds value={Array.isArray(value) ? value.map(Number) : []} onChange={items => change(field, items)} disabled={disabled} min={field.min ?? 1} max={field.max ?? 99} />;
            else control = <input {...common} type={field.type === 'number' ? 'number' : 'text'} step={field.type === 'number' ? 1 : undefined} min={field.min} max={field.max} maxLength={field.maxLength ?? 255} pattern={field.pattern} value={String(value ?? '')} onChange={event => change(field, field.type === 'number' ? (event.target.value === '' ? '' : Number(event.target.value)) : event.target.value)} />;
            return <div key={field.key} className={field.type === 'textarea' ? 'sm:col-span-2' : ''}><FormRow label={title} help={field.help}>{control}</FormRow>{fieldError && <p id={`${fieldId}-error`} role="alert" className="mt-1 text-xs text-red-600">{fieldError}</p>}</div>;
        })}</div>
        {children}
        {Object.keys(errors).length > 0 && <div role="alert" className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{Object.values(errors).map((error, index) => <p key={index}>{error}</p>)}</div>}
        {editable && <div className="flex items-center gap-3"><button className={primaryBtn} disabled={form.processing} type="submit">{submitLabel ?? t('grievances.common.save')}</button>{onCancel && <button className={secondaryBtn} type="button" onClick={onCancel}>{t('grievances.common.cancel')}</button>}{form.recentlySuccessful && <span role="status" className="text-sm text-emerald-700">{t('grievanceAdmin.saved')}</span>}</div>}
        {!editable && <p className="text-sm text-gray-500">{t('grievanceAdmin.readOnly')}</p>}
    </form>;
}

function Thresholds({ value, onChange, min, max, disabled }: { value: number[]; onChange: (value: number[]) => void; min: number; max: number; disabled: boolean }) {
    const { t } = useLocale();
    return <div className="space-y-2">{value.map((number, index) => <div className="flex gap-2" key={index}><input aria-label={t('grievanceAdmin.threshold')} className={inputCls} type="number" min={min} max={max} step={1} required disabled={disabled} value={number} onChange={event => onChange(value.map((old, at) => at === index ? Number(event.target.value) : old))} /><button className={secondaryBtn} disabled={disabled} type="button" onClick={() => onChange(value.filter((_, at) => at !== index))}>{t('grievanceAdmin.remove')}</button></div>)}<button type="button" className={secondaryBtn} disabled={disabled} onClick={() => onChange([...value, min])}>{t('grievances.common.add')}</button></div>;
}

export const choices = (values: string[]): Choice[] => values.map(value => ({ value, label: value }));
export const today = () => { const now = new Date(); return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`; };

export function handlerFields({ prefix = '', options, locale, title, required = false, allowOrganization = false, initial }: {
    prefix?: string; options: Pick<HandlerOptions, 'handler_types' | 'organizations' | 'committees' | 'external_authorities'>;
    locale: string; title: string; required?: boolean; allowOrganization?: boolean; initial?: HandlerRef | null;
}): AdminField[] {
    const typeKey = `${prefix}handler_type`, idKey = `${prefix}handler_id`, orgKey = `${prefix}lookup_organization_id`;
    const list = (rows: Bilingual[]) => rows.map(row => ({ value: row.id!, label: nameOf(row, locale) }));
    const result: AdminField[] = [{ key: typeKey, label: title, type: 'select', options: choices(options.handler_types.filter(type => allowOrganization || type !== 'organization')), enumGroup: 'handler_type', required, clearOnChange: [idKey, orgKey] }];
    for (const [type, rows] of [['organization', options.organizations], ['committee', options.committees], ['external_authority', options.external_authorities]] as const) {
        result.push({ key: idKey, label: title, type: 'select', required, options: list(rows), visible: data => data[typeKey] === type });
    }
    result.push({ key: orgKey, label: locale === 'am' ? 'ተቋም' : 'Organization', type: 'select', options: list(options.organizations), visible: data => data[typeKey] === 'organization_unit', clearOnChange: [idKey] });
    result.push({ key: idKey, label: title, type: 'lookup', lookup: 'units', organizationKey: orgKey, initialLabel: initial ? nameOf(initial, locale) : undefined, required, visible: data => data[typeKey] === 'organization_unit' });
    return result;
}
