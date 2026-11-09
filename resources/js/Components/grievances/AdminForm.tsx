import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { FormRow, inputCls, primaryBtn, secondaryBtn, textareaCls, useEnumLabel } from '@/Components/grievances/ui';
import { useLocale } from '@/hooks/useLocale';
import { useForm } from '@inertiajs/react';
import { createContext, useContext, useEffect, useId, useState, type ReactNode } from 'react';
import type { Bilingual, HandlerOptions, HandlerRef } from '@/types/grievances';
import { nameOf } from '@/Components/grievances/ui';

export type AdminValue = string | number | boolean | null | File | string[] | number[];
export type AdminData = Record<string, AdminValue>;
export type Choice = { value: string; label: string };
export type AdminField = {
    key: string; label?: string; type?: 'text' | 'textarea' | 'number' | 'boolean' | 'date' | 'select' | 'lookup' | 'file' | 'numbers' | 'multiselect';
    required?: boolean; min?: number; max?: number; maxLength?: number; pattern?: string; accept?: string; help?: string;
    options?: Choice[]; lookup?: 'employees' | 'units' | 'positions' | 'users'; organizationKey?: string; unitKey?: string; initialLabel?: string;
    /** Extra fixed query parameters for a lookup, e.g. who to leave out. */
    lookupParams?: Record<string, string>;
    /** A lookup whose empty value means "all" (e.g. a committee serving all staff): offered as the first choice. */
    emptyLabel?: string;
    visible?: (data: AdminData) => boolean; clearOnChange?: string[]; enumGroup?: string;
    /** Shown but not editable, e.g. the organization a letterhead belongs to. */
    disabled?: boolean;
};

/**
 * Set by a container that hosts a form (the settings drawer): it learns when
 * the form has unsaved edits, and pins the actions to its bottom edge.
 */
export const FormHostContext = createContext<{ onDirtyChange?: (dirty: boolean) => void; stickyActions?: boolean; requestClose?: () => void }>({});

/** Accessible on/off switch (role="switch"). */
export function Switch({ id, checked, onChange, disabled, label, describedBy }: { id?: string; checked: boolean; onChange: (value: boolean) => void; disabled?: boolean; label: string; describedBy?: string }) {
    return (
        <button id={id} type="button" role="switch" aria-checked={checked} aria-label={label} aria-describedby={describedBy} disabled={disabled} onClick={() => onChange(!checked)}
            className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${checked ? 'bg-primary' : 'bg-gray-300 dark:bg-slate-600'}`}>
            <span className={`inline-block h-5 w-5 transform rounded-full bg-white shadow-sm transition-transform ${checked ? 'translate-x-[22px]' : 'translate-x-0.5'}`} />
        </button>
    );
}

/**
 * One searchable field (combobox): type to search the existing grievance
 * lookup endpoints, pick a suggestion, and the picked label stays in the
 * field. IDs are never typed. Units are fetched per organization and
 * filtered as you type; the other endpoints search server-side.
 */
export function AdminLookup({ endpoint, organizationId, unitId, extraParams, value, initialLabel, emptyLabel, onChange, disabled, required, id }: {
    endpoint: NonNullable<AdminField['lookup']>; organizationId?: string; unitId?: string; extraParams?: Record<string, string>; value: string; initialLabel?: string; emptyLabel?: string;
    onChange: (value: string) => void; disabled?: boolean; required?: boolean; id?: string;
}) {
    const { t, locale } = useLocale();
    const listId = useId();
    const [query, setQuery] = useState('');
    const [choices, setChoices] = useState<Choice[]>([]);
    const [selected, setSelected] = useState<Choice | null>(value && initialLabel ? { value, label: initialLabel } : null);
    const [state, setState] = useState<'loading' | 'ready' | 'error'>('ready');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(-1);
    const needsOrganization = endpoint === 'units' && !organizationId;
    // A stable key for the extra parameters, so a new object each render does not refetch.
    const extraKey = JSON.stringify(extraParams ?? {});
    const serverSearch = endpoint !== 'units';
    // The parent reset the value (saved form, clearOnChange): drop the stale label.
    useEffect(() => { if (!value) { setSelected(null); } }, [value]);
    useEffect(() => {
        if (disabled || needsOrganization || !open) { return; }
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setState('loading');
            try {
                const params = new URLSearchParams({ q: serverSearch ? query : '' });
                if (organizationId) params.set('organization_id', organizationId);
                if (unitId) params.set('unit_id', unitId);
                Object.entries(extraParams ?? {}).forEach(([key, val]) => params.set(key, val));
                const response = await fetch(`${route(`grievances.lookup.${endpoint}`)}?${params}`, { headers: { Accept: 'application/json' }, signal: controller.signal });
                if (!response.ok) throw new Error('Lookup failed');
                const rows = await response.json() as Record<string, string | number | null>[];
                setChoices(rows.map(row => {
                    const unit = locale === 'am' ? row.unit_am || row.unit_en : row.unit_en || row.unit_am;
                    return { value: String(row.id), label: String((locale === 'am' ? row.name_am || row.title_am || row.name : row.name_en || row.title_en) || row.name || row.name_en || row.title_en || '') + (row.employee_number ? ` (${row.employee_number})` : '') + (unit ? ` · ${unit}` : '') };
                }));
                setState('ready');
            } catch { if (!controller.signal.aborted) setState('error'); }
        }, 250);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [endpoint, organizationId, unitId, extraKey, query, locale, disabled, open, needsOrganization, serverSearch]);
    const needle = query.trim().toLowerCase();
    const matches = serverSearch || !needle ? choices : choices.filter(choice => choice.label.toLowerCase().includes(needle));
    // The "all" choice (empty value) comes first while nothing is typed.
    const visible = emptyLabel && !needle ? [{ value: '', label: emptyLabel }, ...matches] : matches;
    const pick = (choice: Choice) => { setSelected(choice.value ? choice : null); onChange(choice.value); setQuery(''); setOpen(false); setActive(-1); };
    const clear = () => { setSelected(null); onChange(''); setQuery(''); setActive(-1); };
    const inactive = disabled || needsOrganization;
    const showList = open && !inactive;
    return <div className="relative">
        <div className="relative">
            <input id={id} role="combobox" aria-expanded={showList} aria-controls={listId} aria-autocomplete="list" aria-activedescendant={showList && active >= 0 ? `${listId}-${active}` : undefined}
                autoComplete="off" className={`${inputCls} ${selected && !inactive ? 'pr-9' : ''}`} value={selected ? selected.label : query} required={required} disabled={inactive}
                placeholder={needsOrganization ? t('grievanceAdmin.chooseOrganization') : emptyLabel ?? t('grievanceAdmin.searchLookup')}
                onChange={event => { if (selected) { setSelected(null); onChange(''); } setQuery(event.target.value); setOpen(true); setActive(-1); }}
                onFocus={() => setOpen(true)} onClick={() => setOpen(true)} onBlur={() => { setOpen(false); setActive(-1); }}
                onKeyDown={event => {
                    if (event.key === 'ArrowDown') { event.preventDefault(); setOpen(true); setActive(index => Math.min(index + 1, visible.length - 1)); }
                    else if (event.key === 'ArrowUp') { event.preventDefault(); setActive(index => Math.max(index - 1, 0)); }
                    else if (event.key === 'Enter' && showList && active >= 0 && visible[active]) { event.preventDefault(); pick(visible[active]); }
                    else if (event.key === 'Escape') { setOpen(false); setActive(-1); }
                }} />
            {selected && !inactive && <button type="button" aria-label={t('grievanceAdmin.clearLookup')} className="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-(--app-muted-foreground) hover:text-(--app-foreground)" onMouseDown={event => event.preventDefault()} onClick={clear}>×</button>}
        </div>
        {showList && <ul id={listId} role="listbox" className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-(--app-border) bg-(--app-surface) py-1 shadow-lg">
            {state === 'loading' && !visible.length && <li role="status" className="px-3 py-2 text-xs text-(--app-muted-foreground)">{t('grievances.common.loading')}</li>}
            {state === 'error' && <li role="alert" className="px-3 py-2 text-xs text-red-600">{t('grievanceAdmin.lookupError')}</li>}
            {state === 'ready' && !visible.length && <li className="px-3 py-2 text-xs text-(--app-muted-foreground)">{t('grievanceAdmin.noMatches')}</li>}
            {visible.map((choice, index) => <li key={choice.value || 'all'} id={`${listId}-${index}`} role="option" aria-selected={choice.value === value}
                className={`cursor-pointer px-3 py-2 text-sm text-(--app-foreground) hover:bg-(--app-surface-muted) ${index === active ? 'bg-(--app-surface-muted)' : ''} ${choice.value ? '' : 'font-medium'}`}
                onMouseDown={event => { event.preventDefault(); pick(choice); }} onMouseEnter={() => setActive(index)}>{choice.label}</li>)}
        </ul>}
    </div>;
}

export function AdminForm({ initial, fields, url, method = 'post', editable = true, onSaved, onCancel, children, submitLabel }: {
    initial: AdminData; fields: AdminField[]; url: string; method?: 'post' | 'patch' | 'put'; editable?: boolean;
    onSaved?: () => void; onCancel?: () => void; children?: ReactNode; submitLabel?: string;
}) {
    const { t } = useLocale();
    const label = useEnumLabel();
    const id = useId();
    const host = useContext(FormHostContext);
    const form = useForm<AdminData>(initial);
    const errors = form.errors as Record<string, string>;
    const { onDirtyChange } = host;
    useEffect(() => { onDirtyChange?.(form.isDirty); }, [form.isDirty, onDirtyChange]);
    const change = (field: AdminField, value: AdminValue) => form.setData(data => ({ ...data, [field.key]: value, ...Object.fromEntries((field.clearOnChange ?? []).map(key => [key, ''])) }));
    return <form onSubmit={event => {
        event.preventDefault();
        form.transform(data => Object.fromEntries(Object.entries(data).map(([key, value]) => [key, value === '' ? null : value])));
        form.submit(method, url, { preserveScroll: true, onSuccess: () => { form.setDefaults(); onDirtyChange?.(false); onSaved?.(); } });
    }} className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">{fields.filter(field => !field.visible || field.visible(form.data)).map(field => {
            const fieldId = `${id}-${field.key}`;
            const value = form.data[field.key];
            const disabled = !editable || form.processing || Boolean(field.disabled);
            const title = field.label ?? t(`grievanceAdmin.fields.${field.key}`);
            const fieldError = Object.entries(errors).filter(([key]) => key === field.key || key.startsWith(`${field.key}.`)).map(([, error]) => error).join(' ');
            const common = { id: fieldId, disabled, required: field.required, className: inputCls, 'aria-invalid': Boolean(fieldError), 'aria-describedby': fieldError ? `${fieldId}-error` : undefined };
            let control: ReactNode;
            if (field.type === 'boolean') {
                // A switch row: label and help on the left, the switch on the right.
                return <div key={field.key} className="flex items-start justify-between gap-4 rounded-md border border-(--app-border) px-3 py-2.5 sm:col-span-2">
                    <span className="min-w-0">
                        <span id={`${fieldId}-label`} className="block text-sm font-medium text-(--app-foreground)">{title}</span>
                        {field.help && <span id={`${fieldId}-help`} className="block text-xs text-(--app-muted-foreground)">{field.help}</span>}
                        {fieldError && <span id={`${fieldId}-error`} role="alert" className="mt-1 block text-xs text-red-600">{fieldError}</span>}
                    </span>
                    <Switch id={fieldId} label={title} describedBy={field.help ? `${fieldId}-help` : undefined} checked={Boolean(value)} disabled={disabled} onChange={checked => change(field, checked)} />
                </div>;
            }
            if (field.type === 'date') control = <LocalizedDatePicker id={fieldId} disabled={disabled} required={field.required} className={inputCls} value={String(value ?? '')} onChange={date => change(field, date)} />;
            else if (field.type === 'lookup') control = <AdminLookup id={fieldId} endpoint={field.lookup!} organizationId={field.organizationKey ? String(form.data[field.organizationKey] ?? '') : undefined} unitId={field.unitKey ? String(form.data[field.unitKey] ?? '') : undefined} extraParams={field.lookupParams} value={String(value ?? '')} initialLabel={field.initialLabel} emptyLabel={field.emptyLabel} disabled={disabled} required={field.required} onChange={selection => change(field, selection)} />;
            else if (field.type === 'textarea') control = <textarea {...common} className={textareaCls} rows={field.key === 'body_template' ? 10 : 3} maxLength={field.maxLength ?? 2000} value={String(value ?? '')} onChange={event => change(field, event.target.value)} />;
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
        {Object.keys(errors).length > 0 && <div role="alert" className="rounded-sm border border-red-200 bg-red-50 p-3 text-sm text-red-700">{Object.values(errors).map((error, index) => <p key={index}>{error}</p>)}</div>}
        {editable && <div className={`flex items-center gap-3 ${host.stickyActions ? 'sticky -bottom-5 flex-row-reverse justify-start z-10 -mx-6 -mb-5 border-t border-(--app-border) bg-(--app-surface) px-6 py-3' : ''}`}><button className={primaryBtn} disabled={form.processing} type="submit">{submitLabel ?? t('grievances.common.save')}</button>{onCancel && <button className={secondaryBtn} type="button" onClick={host.requestClose ?? onCancel}>{t('grievances.common.cancel')}</button>}{form.recentlySuccessful && <span role="status" className="text-sm text-emerald-700">{t('grievanceAdmin.saved')}</span>}</div>}
        {!editable && <p className="text-sm text-(--app-muted-foreground)">{t('grievanceAdmin.readOnly')}</p>}
    </form>;
}

function Thresholds({ value, onChange, min, max, disabled }: { value: number[]; onChange: (value: number[]) => void; min: number; max: number; disabled: boolean }) {
    const { t } = useLocale();
    return <div className="space-y-2">{value.map((number, index) => <div className="flex gap-2" key={index}><input aria-label={t('grievanceAdmin.threshold')} className={inputCls} type="number" min={min} max={max} step={1} required disabled={disabled} value={number} onChange={event => onChange(value.map((old, at) => at === index ? Number(event.target.value) : old))} /><button className={secondaryBtn} disabled={disabled} type="button" onClick={() => onChange(value.filter((_, at) => at !== index))}>{t('grievanceAdmin.remove')}</button></div>)}<button type="button" className={secondaryBtn} disabled={disabled} onClick={() => onChange([...value, min])}>{t('grievances.common.add')}</button></div>;
}

export const choices = (values: string[]): Choice[] => values.map(value => ({ value, label: value }));
export const today = () => { const now = new Date(); return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`; };

export function handlerFields({ prefix = '', options, locale, title, organizationLabel, required = false, allowOrganization = false, initial, allLabels }: {
    prefix?: string; options: Pick<HandlerOptions, 'handler_types' | 'organizations' | 'handler_units'> & { committees: Bilingual[]; external_authorities: Bilingual[] };
    locale: string; title: string; organizationLabel: string; required?: boolean; allowOrganization?: boolean; initial?: HandlerRef | null;
    /** Offer an "All" choice (every institution / committee / team) — for a route's source. */
    allLabels?: { organization?: string; committee?: string; team?: string };
}): AdminField[] {
    const typeKey = `${prefix}handler_type`, idKey = `${prefix}handler_id`, orgKey = `${prefix}lookup_organization_id`;
    const list = (rows: Bilingual[]) => rows.map(row => ({ value: row.id!, label: nameOf(row, locale) }));
    const units = options.handler_units;
    // With designated units listed, Team and Directorate are separate choices (both are units underneath).
    const types = options.handler_types.filter(type => (allowOrganization || type !== 'organization') && !(units && type === 'organization_unit'));
    if (units) types.splice(Math.max(types.indexOf('committee') + 1, 0), 0, 'team', 'directorate');
    const result: AdminField[] = [{ key: typeKey, label: title, type: 'select', options: choices(types), enumGroup: 'handler_type', required, clearOnChange: [idKey, orgKey] }];
    const all = (label: string | undefined) => (label ? [{ value: 'all', label }] : []);
    for (const [type, rows, allLabel] of [['organization', options.organizations, allLabels?.organization], ['committee', options.committees, allLabels?.committee], ['external_authority', options.external_authorities, undefined]] as const) {
        result.push({ key: idKey, label: title, type: 'select', required, options: [...all(allLabel), ...list(rows)], visible: data => data[typeKey] === type });
    }
    // ADR 0029/0030: pick designated Teams, or the one Grievance Directorate, directly.
    if (units) {
        const unitList = (kind: string) => units.filter(unit => unit.kind === kind).map(unit => ({ value: unit.id!, label: `${nameOf(unit, locale)} — ${nameOf(unit.organization, locale)}` }));
        result.push({ key: idKey, label: title, type: 'select', required, options: [...all(allLabels?.team), ...unitList('team')], visible: data => data[typeKey] === 'team' });
        result.push({ key: idKey, label: title, type: 'select', required, options: unitList('directorate'), visible: data => data[typeKey] === 'directorate' });
        return result;
    }
    result.push({ key: orgKey, label: organizationLabel, type: 'select', options: list(options.organizations), visible: data => data[typeKey] === 'organization_unit', clearOnChange: [idKey] });
    result.push({ key: idKey, label: title, type: 'lookup', lookup: 'units', organizationKey: orgKey, initialLabel: initial ? nameOf(initial, locale) : undefined, required, visible: data => data[typeKey] === 'organization_unit' });
    return result;
}
