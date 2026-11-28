import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import ConfirmationDialog from '@/Components/ConfirmationDialog';
import { CheckCircle, PencilIcon, Plus } from '@/Components/Icons';
import PageHeader from '@/Components/PageHeader';
import { AppActionMenu, Table, TableCard, TableTools, TBody, Td, Th, THead, Tr, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ActionErrors, Notice } from '@/modules/leave/components/Common';
import { LeavePopup } from '@/modules/leave/components/Popup';
import { badge, errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, textareaCls, useLeave } from '@/modules/leave/lib/helpers';
import type { LeaveCategory } from '@/modules/leave/types';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX, type ReactNode } from 'react';

interface TypeRow {
    id: string;
    code: string;
    name_en: string;
    name_am: string;
    category: LeaveCategory;
    day_unit: 'working' | 'calendar';
    pay_class: 'full' | 'none' | 'by_period';
    uses_balance: boolean;
    allows_half_day: boolean;
    allows_past_start: boolean;
    requires_supervisor_approval: boolean;
    requires_document: boolean;
    employee_can_request: boolean;
    gender: string | null;
    is_active: boolean;
    sort_order: number;
    is_system: boolean;
    in_use: boolean;
}

interface RuleRow {
    leave_type_id: string;
    base_days: number | null;
    increment_per_service_year: number | null;
    max_days: number | null;
    waiting_months: number | null;
    carry_over_mode: 'none' | 'automatic' | 'authorized';
    carry_over_years: number | null;
    max_days_per_request: number | null;
    max_days_per_year: number | null;
    settings: Record<string, number | string | null>;
}

interface RuleSet {
    id: string;
    name: string;
    legal_reference: string | null;
    notes: string | null;
    effective_from: string;
    effective_to: string | null;
    status: 'draft' | 'active' | 'retired';
    is_confirmed: boolean;
    figures_locked: boolean;
    rules: RuleRow[];
}

interface GeneralSettings {
    hr_confirmation_required: boolean;
    min_notice_days: number;
    schedule_enabled: boolean;
    schedule_requires_approval: boolean;
    schedule_auto_count: boolean;
}

interface Props {
    leaveTypes: TypeRow[];
    ruleSets: RuleSet[];
    settingKeys: string[];
    leaveSettings: GeneralSettings;
}

const CATEGORIES: LeaveCategory[] = ['annual', 'personal', 'sick', 'maternity', 'paternity', 'unpaid', 'special'];

/** The type-specific figures each kind of leave carries. */
const CATEGORY_SETTINGS: Partial<Record<LeaveCategory, string[]>> = {
    sick: ['window_months', 'max_months_in_window', 'long_window_years', 'max_months_in_long_window', 'full_pay_months', 'probation_months', 'probation_max_months', 'certificate_from_days'],
    maternity: ['prenatal_days', 'postnatal_days', 'miscarriage_days'],
};

const STATUS_TONE = { draft: 'warning', active: 'success', retired: 'neutral' } as const;
const blank = (value: number | string | null | undefined): string => (value === null || value === undefined ? '' : String(value));

/** A switch with the sentence that says what it does: the settings page is read by people who decide policy, not by programmers. */
function Toggle({ id, label, help, checked, disabled = false, onChange }: { id: string; label: string; help?: string; checked: boolean; disabled?: boolean; onChange: (value: boolean) => void }): JSX.Element {
    return (
        <label htmlFor={id} className={cx('flex items-start gap-3 rounded-control border border-(--app-border) px-3 py-2.5', disabled && 'opacity-60')}>
            <input id={id} type="checkbox" className="mt-1" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
            <span className="min-w-0">
                <span className="block text-sm font-medium text-(--app-foreground)">{label}</span>
                {help && <span className={cx(mutedCls, 'mt-0.5 block text-xs')}>{help}</span>}
            </span>
        </label>
    );
}

/** A titled card with one action, the frame every section of this page shares with the module's lists. */
function SectionCard({ title, description, action, children }: { title: string; description: string; action?: ReactNode; children: ReactNode }): JSX.Element {
    return (
        <TableCard>
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-(--app-border) px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold text-(--app-foreground)">{title}</h2>
                    <p className={cx(mutedCls, 'mt-0.5 text-xs')}>{description}</p>
                </div>
                {action}
            </div>
            {children}
        </TableCard>
    );
}

/** How a request is decided and how the leave schedule works. */
function GeneralForm({ settings }: { settings: GeneralSettings }): JSX.Element {
    const { lt } = useLeave();
    const form = useForm({ ...settings });
    const d = form.data;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('leave.settings.general.update'), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-5 p-4">
            <div className="grid gap-5 lg:grid-cols-2">
                <fieldset className="space-y-3">
                    <legend className={cx(sectionTitleCls, 'mb-2 text-sm')}>{lt('settings.general.requests')}</legend>
                    <Toggle id="ls-hr" label={lt('settings.general.hrConfirmation')} help={lt('settings.general.hrConfirmationHelp')}
                        checked={d.hr_confirmation_required} onChange={(v) => form.setData('hr_confirmation_required', v)} />
                    <div className="rounded-control border border-(--app-border) px-3 py-2.5">
                        <label htmlFor="ls-notice" className="block text-sm font-medium text-(--app-foreground)">{lt('settings.general.notice')}</label>
                        <span className={cx(mutedCls, 'mt-0.5 block text-xs')}>{lt('settings.general.noticeHelp')}</span>
                        <input id="ls-notice" type="number" min="0" max="90" step="1" className={cx(inputCls, 'mt-2 max-w-32')} value={d.min_notice_days}
                            onChange={(e) => form.setData('min_notice_days', e.target.value === '' ? 0 : Number(e.target.value))} />
                        {form.errors.min_notice_days && <p className={errorCls}>{form.errors.min_notice_days}</p>}
                    </div>
                    <p className={cx(mutedCls, 'text-xs')}>{lt('settings.general.fixedRules')}</p>
                </fieldset>
                <fieldset className="space-y-3">
                    <legend className={cx(sectionTitleCls, 'mb-2 text-sm')}>{lt('settings.general.schedule')}</legend>
                    <Toggle id="ls-sched" label={lt('settings.general.scheduleEnabled')} help={lt('settings.general.scheduleEnabledHelp')}
                        checked={d.schedule_enabled} onChange={(v) => form.setData('schedule_enabled', v)} />
                    <Toggle id="ls-sched-approval" label={lt('settings.general.scheduleApproval')} help={lt('settings.general.scheduleApprovalHelp')} disabled={!d.schedule_enabled}
                        checked={d.schedule_requires_approval} onChange={(v) => form.setData('schedule_requires_approval', v)} />
                    <Toggle id="ls-sched-auto" label={lt('settings.general.scheduleAuto')} help={lt('settings.general.scheduleAutoHelp')} disabled={!d.schedule_enabled}
                        checked={d.schedule_auto_count} onChange={(v) => form.setData('schedule_auto_count', v)} />
                </fieldset>
            </div>
            <div className="flex justify-end">
                <button type="submit" className={primaryBtn} disabled={form.processing || !form.isDirty}>{lt('common.save')}</button>
            </div>
        </form>
    );
}

/** One leave type, new or existing: its names, its kind, and every switch. */
function TypeForm({ type, nextOrder, onDone }: { type: TypeRow | null; nextOrder: number; onDone: () => void }): JSX.Element {
    const { lt } = useLeave();
    const form = useForm({
        code: type?.code ?? '', name_en: type?.name_en ?? '', name_am: type?.name_am ?? '', category: type?.category ?? ('special' as LeaveCategory),
        day_unit: type?.day_unit ?? 'working', pay_class: type?.pay_class ?? 'full', uses_balance: type?.uses_balance ?? false, gender: type?.gender ?? '',
        sort_order: type?.sort_order ?? nextOrder, allows_half_day: type?.allows_half_day ?? false, allows_past_start: type?.allows_past_start ?? false,
        requires_supervisor_approval: type?.requires_supervisor_approval ?? true, requires_document: type?.requires_document ?? false,
        employee_can_request: type?.employee_can_request ?? true, is_active: type?.is_active ?? true,
    });
    const d = form.data;
    const fixed = type?.in_use ?? false;
    const error = (field: keyof typeof d) => (form.errors[field] ? <p className={errorCls}>{form.errors[field]}</p> : null);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (type) form.put(route('leave.settings.types.update', type.id), options);
        else form.post(route('leave.settings.types.store'), options);
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            {fixed && <Notice tone="info">{lt('settings.type.inUse')}</Notice>}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div><label htmlFor="lt-am" className={labelCls}>{lt('settings.type.nameAm')}</label><input id="lt-am" className={inputCls} value={d.name_am} onChange={(e) => form.setData('name_am', e.target.value)} required maxLength={150} />{error('name_am')}</div>
                <div><label htmlFor="lt-en" className={labelCls}>{lt('settings.type.nameEn')}</label><input id="lt-en" className={inputCls} value={d.name_en} onChange={(e) => form.setData('name_en', e.target.value)} required maxLength={150} />{error('name_en')}</div>
                {!type && (
                    <div>
                        <label htmlFor="lt-code" className={labelCls}>{lt('settings.type.code')}</label>
                        <input id="lt-code" className={inputCls} value={d.code} onChange={(e) => form.setData('code', e.target.value.toLowerCase())} required maxLength={40} pattern="[a-z][a-z0-9_]*" />
                        <p className={cx(mutedCls, 'mt-1 text-xs')}>{lt('settings.type.codeHelp')}</p>{error('code')}
                    </div>
                )}
                <div>
                    <label htmlFor="lt-category" className={labelCls}>{lt('settings.type.category')}</label>
                    <select id="lt-category" className={inputCls} value={d.category} disabled={fixed} onChange={(e) => form.setData('category', e.target.value as LeaveCategory)}>
                        {CATEGORIES.map((category) => <option key={category} value={category}>{lt(`category.${category}`)}</option>)}
                    </select>
                    <p className={cx(mutedCls, 'mt-1 text-xs')}>{lt('settings.type.categoryHelp')}</p>{error('category')}
                </div>
                <div>
                    <label htmlFor="lt-unit" className={labelCls}>{lt('settings.type.dayUnit')}</label>
                    <select id="lt-unit" className={inputCls} value={d.day_unit} onChange={(e) => form.setData('day_unit', e.target.value as TypeRow['day_unit'])}>
                        <option value="working">{lt('common.unitWorking')}</option>
                        <option value="calendar">{lt('common.unitCalendar')}</option>
                    </select>
                </div>
                <div>
                    <label htmlFor="lt-pay" className={labelCls}>{lt('settings.type.pay')}</label>
                    <select id="lt-pay" className={inputCls} value={d.pay_class} onChange={(e) => form.setData('pay_class', e.target.value as TypeRow['pay_class'])}>
                        {(['full', 'none', 'by_period'] as const).map((pay) => <option key={pay} value={pay}>{lt(`settings.type.payClass.${pay}`)}</option>)}
                    </select>
                </div>
                <div>
                    <label htmlFor="lt-gender" className={labelCls}>{lt('settings.type.gender')}</label>
                    <select id="lt-gender" className={inputCls} value={d.gender} onChange={(e) => form.setData('gender', e.target.value)}>
                        <option value="">{lt('settings.type.genderAll')}</option>
                        <option value="female">{lt('settings.type.genderFemale')}</option>
                        <option value="male">{lt('settings.type.genderMale')}</option>
                    </select>
                </div>
                <div>
                    <label htmlFor="lt-order" className={labelCls}>{lt('settings.type.order')}</label>
                    <input id="lt-order" type="number" min="0" max="9999" className={inputCls} value={d.sort_order} onChange={(e) => form.setData('sort_order', e.target.value === '' ? 0 : Number(e.target.value))} />{error('sort_order')}
                </div>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Toggle id="lt-active" label={lt('settings.type.is_active')} help={lt('settings.type.is_activeHelp')} checked={d.is_active} onChange={(v) => form.setData('is_active', v)} />
                <Toggle id="lt-balance" label={lt('settings.type.uses_balance')} help={lt('settings.type.uses_balanceHelp')} disabled={fixed} checked={d.uses_balance} onChange={(v) => form.setData('uses_balance', v)} />
                <Toggle id="lt-request" label={lt('settings.type.employee_can_request')} help={lt('settings.type.employee_can_requestHelp')} checked={d.employee_can_request} onChange={(v) => form.setData('employee_can_request', v)} />
                <Toggle id="lt-supervisor" label={lt('settings.type.requires_supervisor_approval')} help={lt('settings.type.requires_supervisor_approvalHelp')} checked={d.requires_supervisor_approval} onChange={(v) => form.setData('requires_supervisor_approval', v)} />
                <Toggle id="lt-document" label={lt('settings.type.requires_document')} help={lt('settings.type.requires_documentHelp')} checked={d.requires_document} onChange={(v) => form.setData('requires_document', v)} />
                <Toggle id="lt-half" label={lt('settings.type.allows_half_day')} help={lt('settings.type.allows_half_dayHelp')} checked={d.allows_half_day} onChange={(v) => form.setData('allows_half_day', v)} />
                <Toggle id="lt-past" label={lt('settings.type.allows_past_start')} help={lt('settings.type.allows_past_startHelp')} checked={d.allows_past_start} onChange={(v) => form.setData('allows_past_start', v)} />
            </div>
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" className={secondaryBtn} onClick={onDone}>{lt('common.close')}</button>
                <button type="submit" className={primaryBtn} disabled={form.processing}>{lt(type ? 'common.save' : 'settings.types.create')}</button>
            </div>
        </form>
    );
}

/** The figures of one rule set, one block per leave type. */
function RuleSetForm({ set, types, onDone }: { set: RuleSet; types: TypeRow[]; onDone: () => void }): JSX.Element {
    const { lt, name } = useLeave();
    const form = useForm({
        name: set.name, legal_reference: set.legal_reference ?? '', notes: set.notes ?? '', is_confirmed: set.is_confirmed, effective_from: set.effective_from,
        rules: types.map((type): RuleRow => set.rules.find((rule) => rule.leave_type_id === type.id) ?? {
            leave_type_id: type.id, base_days: null, increment_per_service_year: null, max_days: null, waiting_months: 0,
            carry_over_mode: 'none', carry_over_years: 0, max_days_per_request: null, max_days_per_year: null, settings: {},
        }),
    });
    const locked = set.figures_locked;
    const setRule = (index: number, patch: Partial<RuleRow>) => form.setData('rules', form.data.rules.map((rule, i) => (i === index ? { ...rule, ...patch } : rule)));
    const numberInput = (index: number, field: keyof RuleRow, label: string, step = '0.5') => (
        <div>
            <label htmlFor={`rs-${index}-${field}`} className={labelCls}>{label}</label>
            <input id={`rs-${index}-${field}`} type="number" min="0" step={step} className={inputCls} disabled={locked}
                value={blank(form.data.rules[index][field] as number | null)}
                onChange={(e) => setRule(index, { [field]: e.target.value === '' ? null : Number(e.target.value) } as Partial<RuleRow>)} />
        </div>
    );
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('leave.settings.rule-sets.update', set.id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            {locked && <Notice tone="info">{lt('settings.rules.locked')}</Notice>}
            <div className="grid gap-3 sm:grid-cols-2">
                <div><label htmlFor="rs-name" className={labelCls}>{lt('settings.rules.name')}</label><input id="rs-name" className={inputCls} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required maxLength={200} /></div>
                <div><span className={labelCls}>{lt('settings.rules.effectiveFrom')}</span><LocalizedDatePicker value={form.data.effective_from} disabled={locked} onChange={(v) => form.setData('effective_from', v)} /></div>
            </div>
            <div><label htmlFor="rs-ref" className={labelCls}>{lt('settings.rules.legalReference')}</label><input id="rs-ref" className={inputCls} value={form.data.legal_reference} onChange={(e) => form.setData('legal_reference', e.target.value)} maxLength={500} /></div>
            <div><label htmlFor="rs-notes" className={labelCls}>{lt('settings.rules.notes')}</label><textarea id="rs-notes" rows={2} className={textareaCls} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={2000} /></div>
            <Toggle id="rs-confirmed" label={lt('settings.rules.confirmed')} help={lt('settings.rules.confirmedHelp')} checked={form.data.is_confirmed} onChange={(v) => form.setData('is_confirmed', v)} />

            <div className="space-y-3">
                {types.map((type, index) => {
                    const rule = form.data.rules[index];
                    const keys = CATEGORY_SETTINGS[type.category] ?? [];

                    return (
                        <fieldset key={type.id} className={cx(panelCls, 'p-3')}>
                            <legend className="px-1 text-sm font-semibold text-(--app-foreground)">{name(type)}{!type.is_active && <span className={cx(badge('neutral'), 'ms-2')}>{lt('settings.types.inactive')}</span>}</legend>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                {type.uses_balance && (
                                    <>
                                        {numberInput(index, 'base_days', lt('settings.rules.baseDays'))}
                                        {type.category === 'annual' && numberInput(index, 'increment_per_service_year', lt('settings.rules.increment'))}
                                        {type.category === 'annual' && numberInput(index, 'max_days', lt('settings.rules.maxDays'))}
                                        {numberInput(index, 'waiting_months', lt('settings.rules.waitingMonths'), '1')}
                                        <div>
                                            <label htmlFor={`rs-${index}-carry`} className={labelCls}>{lt('settings.rules.carryMode')}</label>
                                            <select id={`rs-${index}-carry`} className={inputCls} disabled={locked} value={rule.carry_over_mode} onChange={(e) => setRule(index, { carry_over_mode: e.target.value as RuleRow['carry_over_mode'] })}>
                                                <option value="none">{lt('settings.rules.carry.none')}</option>
                                                <option value="authorized">{lt('settings.rules.carry.authorized')}</option>
                                                <option value="automatic">{lt('settings.rules.carry.automatic')}</option>
                                            </select>
                                        </div>
                                        {rule.carry_over_mode !== 'none' && numberInput(index, 'carry_over_years', lt('settings.rules.carryYears'), '1')}
                                    </>
                                )}
                                {!type.uses_balance && (
                                    <>
                                        {numberInput(index, 'max_days_per_request', lt('settings.rules.maxPerRequest'))}
                                        {numberInput(index, 'max_days_per_year', lt('settings.rules.maxPerYear'))}
                                    </>
                                )}
                                {keys.map((key) => (
                                    <div key={key}>
                                        <label htmlFor={`rs-${index}-${key}`} className={labelCls}>{lt(`settings.figures.${key}`)}</label>
                                        <input id={`rs-${index}-${key}`} type="number" min="0" step="1" className={inputCls} disabled={locked} value={blank(rule.settings?.[key])}
                                            onChange={(e) => setRule(index, { settings: { ...rule.settings, [key]: e.target.value === '' ? null : Number(e.target.value) } })} />
                                    </div>
                                ))}
                            </div>
                            {!type.uses_balance && keys.length === 0 && rule.max_days_per_request === null && rule.max_days_per_year === null && <p className={cx(mutedCls, 'mt-2 text-xs')}>{lt('settings.rules.noLimit')}</p>}
                        </fieldset>
                    );
                })}
            </div>

            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" className={secondaryBtn} onClick={onDone}>{lt('common.close')}</button>
                <button type="submit" className={primaryBtn} disabled={form.processing}>{lt('common.save')}</button>
            </div>
        </form>
    );
}

/** A draft for a new proclamation or directive, started from the figures of an existing rule set. */
function NewRuleSetForm({ ruleSets, onDone }: { ruleSets: RuleSet[]; onDone: () => void }): JSX.Element {
    const { lt } = useLeave();
    const form = useForm({ name: '', legal_reference: '', effective_from: '', copy_from: ruleSets.find((set) => set.status === 'active')?.id ?? ruleSets[0]?.id ?? '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('leave.settings.rule-sets.store'), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <p className={mutedCls}>{lt('settings.rules.newHelp')}</p>
            <div className="grid gap-3 sm:grid-cols-2">
                <div><label htmlFor="rs-new-name" className={labelCls}>{lt('settings.rules.name')}</label><input id="rs-new-name" className={inputCls} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required maxLength={200} /></div>
                <div><label htmlFor="rs-new-ref" className={labelCls}>{lt('settings.rules.legalReference')}</label><input id="rs-new-ref" className={inputCls} value={form.data.legal_reference} onChange={(e) => form.setData('legal_reference', e.target.value)} maxLength={500} /></div>
                <div><span className={labelCls}>{lt('settings.rules.effectiveFrom')}</span><LocalizedDatePicker value={form.data.effective_from} onChange={(v) => form.setData('effective_from', v)} /></div>
                <div>
                    <label htmlFor="rs-new-copy" className={labelCls}>{lt('settings.rules.copyFrom')}</label>
                    <select id="rs-new-copy" className={inputCls} value={form.data.copy_from} onChange={(e) => form.setData('copy_from', e.target.value)}>
                        <option value="">{lt('settings.rules.copyNothing')}</option>
                        {ruleSets.map((set) => <option key={set.id} value={set.id}>{set.name}</option>)}
                    </select>
                </div>
            </div>
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" className={secondaryBtn} onClick={onDone}>{lt('common.close')}</button>
                <button type="submit" className={primaryBtn} disabled={form.processing || !form.data.effective_from}>{lt('settings.rules.create')}</button>
            </div>
        </form>
    );
}

type Popup = { kind: 'type'; type: TypeRow | null } | { kind: 'ruleSet'; set: RuleSet } | { kind: 'newRuleSet' };

/**
 * Leave settings: the one page that controls the module end to end. How a
 * request is decided and how the leave schedule works; the leave types and
 * every switch of each; and the rule sets holding the figures of the law.
 * Laid out as the module's lists are: titled cards, a row menu, and popups.
 */
export default function Settings({ leaveTypes, ruleSets, leaveSettings }: Props): JSX.Element {
    const { lt, name } = useLeave();
    const [popup, setPopup] = useState<Popup | null>(null);
    const [activating, setActivating] = useState<RuleSet | null>(null);
    const close = () => setPopup(null);
    const unconfirmed = ruleSets.some((set) => set.status === 'active' && !set.is_confirmed);
    const nextOrder = Math.max(0, ...leaveTypes.map((type) => type.sort_order)) + 10;
    const yes = <CheckCircle className="h-4 w-4 text-green-600 dark:text-green-400" aria-hidden />;
    const flag = (on: boolean, label: string) => (on ? <span className="inline-flex items-center gap-1 text-xs text-(--app-foreground)">{yes}{label}</span> : null);
    const addButton = (label: string, onClick: () => void) => (
        <button type="button" className={cx(secondaryBtn, 'gap-2')} onClick={onClick}><Plus className="h-4 w-4" aria-hidden />{label}</button>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('settings.title')} description={lt('settings.description')} />}>
            <Head title={lt('settings.title')} />
            {popup === null && <ActionErrors except={['min_notice_days']} />}
            <div className="space-y-4 pb-4">
                {unconfirmed && <Notice tone="warning">{lt('settings.unconfirmed')}</Notice>}

                <SectionCard title={lt('settings.general.title')} description={lt('settings.general.description')}>
                    <GeneralForm settings={leaveSettings} />
                </SectionCard>

                <SectionCard title={lt('settings.types.title')} description={lt('settings.types.description')} action={addButton(lt('settings.types.add'), () => setPopup({ kind: 'type', type: null }))}>
                    <TableTools id="leave-settings-types"><Table>
                        <THead>
                            <Tr>
                                <Th>{lt('table.type')}</Th><Th>{lt('settings.type.category')}</Th><Th>{lt('settings.type.dayUnit')}</Th><Th>{lt('settings.type.pay')}</Th>
                                <Th>{lt('settings.types.switches')}</Th><Th>{lt('table.status')}</Th><Th className="text-end">{lt('table.actions')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {leaveTypes.map((type) => (
                                <Tr key={type.id}>
                                    <Td>
                                        <button type="button" className="text-start font-medium text-primary hover:underline" onClick={() => setPopup({ kind: 'type', type })}>{name(type)}</button>
                                        <div className={cx(mutedCls, 'text-xs')}>{type.code}</div>
                                    </Td>
                                    <Td>{lt(`category.${type.category}`)}</Td>
                                    <Td>{lt(type.day_unit === 'working' ? 'common.unitWorking' : 'common.unitCalendar')}</Td>
                                    <Td>{lt(`settings.type.payClass.${type.pay_class}`)}</Td>
                                    <Td>
                                        <div className="flex flex-col gap-1">
                                            {flag(type.uses_balance, lt('settings.types.flag.balance'))}
                                            {flag(type.requires_supervisor_approval, lt('settings.types.flag.supervisor'))}
                                            {flag(type.requires_document, lt('settings.types.flag.document'))}
                                            {flag(type.allows_half_day, lt('settings.types.flag.halfDay'))}
                                            {flag(type.allows_past_start, lt('settings.types.flag.pastStart'))}
                                            {flag(!type.employee_can_request, lt('settings.types.flag.notRequestable'))}
                                        </div>
                                    </Td>
                                    <Td><span className={badge(type.is_active ? 'success' : 'neutral')}>{lt(type.is_active ? 'settings.types.active' : 'settings.types.inactive')}</span></Td>
                                    <Td className="whitespace-nowrap text-end">
                                        <div className="flex justify-end">
                                            <AppActionMenu items={[{ label: lt('settings.types.edit'), onClick: () => setPopup({ kind: 'type', type }), icon: <PencilIcon className="h-4 w-4" /> }]} />
                                        </div>
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table></TableTools>
                </SectionCard>

                <SectionCard title={lt('settings.rules.title')} description={lt('settings.rules.description')} action={addButton(lt('settings.rules.newTitle'), () => setPopup({ kind: 'newRuleSet' }))}>
                    <TableTools id="leave-settings-rule-sets"><Table>
                        <THead>
                            <Tr>
                                <Th>{lt('settings.rules.name')}</Th><Th>{lt('settings.rules.legalReference')}</Th><Th>{lt('settings.rules.period')}</Th>
                                <Th>{lt('table.status')}</Th><Th className="text-end">{lt('table.actions')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {ruleSets.map((set) => (
                                <Tr key={set.id}>
                                    <Td><button type="button" className="text-start font-medium text-primary hover:underline" onClick={() => setPopup({ kind: 'ruleSet', set })}>{set.name}</button></Td>
                                    <Td><span className="line-clamp-2 text-xs">{set.legal_reference ?? '—'}</span></Td>
                                    <Td><LocalizedDateDisplay value={set.effective_from} /> – {set.effective_to ? <LocalizedDateDisplay value={set.effective_to} /> : lt('settings.rules.openEnded')}</Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-1">
                                            <span className={badge(STATUS_TONE[set.status])}>{lt(`settings.rules.status.${set.status}`)}</span>
                                            {!set.is_confirmed && <span className={badge('warning')}>{lt('settings.rules.notConfirmed')}</span>}
                                        </div>
                                    </Td>
                                    <Td className="whitespace-nowrap text-end">
                                        <div className="flex justify-end">
                                            <AppActionMenu items={[
                                                { label: lt('settings.rules.edit'), onClick: () => setPopup({ kind: 'ruleSet', set }), icon: <PencilIcon className="h-4 w-4" /> },
                                                { label: lt('settings.rules.activate'), onClick: () => setActivating(set), show: set.status === 'draft', icon: <CheckCircle className="h-4 w-4" /> },
                                            ]} />
                                        </div>
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table></TableTools>
                </SectionCard>
            </div>

            <LeavePopup show={popup !== null} onClose={close}
                title={popup === null ? '' : popup.kind === 'type' ? lt(popup.type ? 'settings.types.edit' : 'settings.types.add') : popup.kind === 'ruleSet' ? lt('settings.rules.edit') : lt('settings.rules.newTitle')}
                subtitle={popup?.kind === 'type' && popup.type ? name(popup.type) : popup?.kind === 'ruleSet' ? popup.set.name : null}>
                <ActionErrors except={['code', 'name_en', 'name_am', 'category', 'sort_order']} />
                {popup?.kind === 'type' && <TypeForm key={popup.type?.id ?? 'new'} type={popup.type} nextOrder={nextOrder} onDone={close} />}
                {popup?.kind === 'ruleSet' && <RuleSetForm key={popup.set.id} set={popup.set} types={leaveTypes} onDone={close} />}
                {popup?.kind === 'newRuleSet' && <NewRuleSetForm ruleSets={ruleSets} onDone={close} />}
            </LeavePopup>

            <ConfirmationDialog open={activating !== null} onClose={() => setActivating(null)} variant="warning"
                title={lt('settings.rules.activateTitle')} description={lt('settings.rules.activateDescription')} confirmLabel={lt('settings.rules.activate')}
                onConfirm={() => activating && router.post(route('leave.settings.rule-sets.activate', activating.id), {}, { preserveScroll: true, onFinish: () => setActivating(null) })} />
        </AuthenticatedLayout>
    );
}
