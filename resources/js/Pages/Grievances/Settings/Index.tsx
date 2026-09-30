import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import { AdminForm, choices, handlerFields, today, type AdminField } from '@/Components/grievances/AdminForm';
import { GPill, HandlerName, Table, fmt, inputCls, nameOf, primaryBtn, secondaryBtn, tdCls, thCls, useEnumLabel } from '@/Components/grievances/ui';
import { AlertTriangle, CheckCircle, Plus, ShieldCheck, TagsIcon, X, XCircle } from '@/Components/Icons';
import DateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { SettingField, SettingsIndexProps } from '@/types/grievances';

/*
 * Grievance Management → Settings (design: Claude Design canvas
 * "Grievance Settings redesign"). Readiness checklist, grouped section nav
 * with counts, policy grouped into cards with a sticky save bar, and lists
 * edited in a side drawer. Every save is validated and audited server-side.
 */

type SectionId = 'policy' | 'categories' | 'reason_codes' | 'approval_rules' | 'external_authorities' | 'delegations' | 'templates' | 'letterheads' | 'seals';
const GROUPS: { id: string; sections: SectionId[] }[] = [
    { id: 'policy', sections: ['policy'] },
    { id: 'case_data', sections: ['categories', 'reason_codes'] },
    { id: 'workflow', sections: ['approval_rules', 'external_authorities', 'delegations'] },
    { id: 'correspondence', sections: ['templates', 'letterheads', 'seals'] },
];
const ALL = GROUPS.flatMap(g => g.sections);

/** Policy settings grouped into cards; `confirm` marks placeholder values the policy owner must confirm. */
const POLICY_GROUPS: { id: string; keys: string[]; confirm?: boolean }[] = [
    { id: 'intake', keys: ['intake_review_enabled', 'allow_rejection_at_intake', 'withdrawal_requires_approval_after_review'] },
    { id: 'committee', keys: ['committee_min_members', 'committee_max_members', 'committee_require_writer', 'quorum_rule', 'quorum_fixed_count'], confirm: true },
    { id: 'decisions', keys: ['appeal_enabled', 'auto_close_after_appeal_window', 'allow_self_approval', 'voting_enabled', 'dissent_enabled', 'unit_staff_see_all_cases'] },
    { id: 'deadlines', keys: ['work_week_days', 'due_soon_days'] },
    { id: 'evidence', keys: ['evidence_max_size_kb', 'evidence_allowed_extensions'] },
    { id: 'records', keys: ['retention_years', 'report_min_group_size', 'sms_notices_enabled'], confirm: true },
];
const WEEKDAYS = ['1', '2', '3', '4', '5', '6', '7'];

type PolicyValue = string | number | boolean | string[];

export default function Index(props: SettingsIndexProps) {
    const { t, locale } = useLocale();
    const a = (k: string) => t(`grievanceAdmin.${k}`);
    const [section, setSection] = useState<SectionId>(ALL.includes(props.tab as SectionId) ? props.tab as SectionId : 'policy');
    const go = (id: SectionId) => {
        setSection(id);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', id);
        window.history.replaceState(window.history.state, '', url);
    };

    const activeCategories = props.categories.filter(c => c.is_active).length;
    const activeRules = props.approvalRules.filter(r => r.is_active && r.requires_approval).length;
    const activeSeals = props.seals.filter(s => s.status === 'active').length;
    const counts: Record<SectionId, number | null> = {
        policy: null, categories: activeCategories, reason_codes: props.reasonCodes.length, approval_rules: activeRules,
        external_authorities: props.externalAuthorities.length, delegations: props.delegations.filter(d => d.status === 'active').length,
        templates: props.templates.length, letterheads: props.letterheads.length, seals: activeSeals,
    };
    /** error = blocks employees; warning = a feature is unavailable until configured. */
    const severity: Partial<Record<SectionId, 'error' | 'warning'>> = {
        ...(activeCategories === 0 ? { categories: 'error' as const } : {}),
        ...(activeRules === 0 ? { approval_rules: 'warning' as const } : {}),
        ...(activeSeals === 0 ? { seals: 'warning' as const } : {}),
        ...(props.letterheads.length === 0 ? { letterheads: 'warning' as const } : {}),
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={a('settings')} description={a('settings_description')}
            actions={<span className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"><ShieldCheck className="h-3.5 w-3.5" aria-hidden="true" />{props.can.update ? a('can_edit') : a('readOnly')}</span>} />}>
            <Head title={a('settings')} />
            <div className="min-w-0 space-y-5 pb-24">
                <Readiness props={props} severity={severity} counts={counts} go={go} />

                {/* Phone: the section nav becomes one select. */}
                <label className="block lg:hidden">
                    <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">{a('section')}</span>
                    <select className={inputCls} value={section} onChange={e => go(e.target.value as SectionId)}>
                        {ALL.map(id => <option key={id} value={id}>{a(`tab_${id}`)}{counts[id] !== null ? ` (${counts[id]})` : ''}</option>)}
                    </select>
                </label>

                <div className="grid items-start gap-5 lg:grid-cols-[248px_minmax(0,1fr)]">
                    <nav aria-label={a('sections')} className="sticky top-4 hidden space-y-4 rounded-panel border border-gray-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900 lg:block">
                        {GROUPS.map(group => (
                            <div key={group.id} className="space-y-0.5">
                                <p className="px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">{a(`group_${group.id}`)}</p>
                                {group.sections.map(id => {
                                    const active = id === section;
                                    const sev = severity[id];
                                    return (
                                        <button key={id} type="button" onClick={() => go(id)} aria-current={active ? 'page' : undefined}
                                            className={`flex w-full items-center justify-between gap-2 rounded-md px-2.5 py-2 text-left text-sm ${active ? 'bg-[color:var(--color-primary-50,#eef1fa)] font-semibold text-[color:var(--color-primary)] dark:bg-slate-800' : 'text-gray-800 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-800/60'}`}>
                                            <span>{a(`tab_${id}`)}</span>
                                            {counts[id] !== null && (sev === 'error'
                                                ? <span className="rounded-full bg-red-700 px-1.5 text-[11px] font-bold text-white" title={a('needs_setup')}>{counts[id]}</span>
                                                : <span className={`text-xs ${sev === 'warning' ? 'font-semibold text-amber-700 dark:text-amber-400' : 'text-gray-500 dark:text-slate-400'}`}>{counts[id]}</span>)}
                                        </button>
                                    );
                                })}
                            </div>
                        ))}
                    </nav>

                    <main className="min-w-0">
                        {section === 'policy' && <PolicyForm props={props} />}
                        {section === 'categories' && <CategoriesSection props={props} />}
                        {section === 'reason_codes' && <ReasonCodesSection props={props} />}
                        {section === 'approval_rules' && <ApprovalRulesSection props={props} />}
                        {section === 'external_authorities' && <ExternalAuthoritiesSection props={props} />}
                        {section === 'delegations' && <DelegationsSection props={props} />}
                        {section === 'templates' && <TemplatesSection props={props} />}
                        {section === 'letterheads' && <LetterheadsSection props={props} />}
                        {section === 'seals' && <SealsSection props={props} />}
                    </main>
                </div>
            </div>
        </AuthenticatedLayout>
    );

    function Readiness({ props: p, severity: sev, counts: n, go: open }: { props: SettingsIndexProps; severity: typeof severity; counts: typeof counts; go: (id: SectionId) => void }) {
        const items: { id: SectionId; ok: boolean; sev?: 'error' | 'warning'; title: string; body: string }[] = [
            { id: 'categories', ok: !sev.categories, sev: sev.categories, title: sev.categories ? a('ready_categories_missing') : fmt(a('ready_categories_ok'), { n: n.categories ?? 0 }), body: sev.categories ? a('ready_categories_help') : a('ready_categories_ok_help') },
            { id: 'approval_rules', ok: !sev.approval_rules, sev: sev.approval_rules, title: sev.approval_rules ? a('ready_rules_missing') : fmt(a('ready_rules_ok'), { n: n.approval_rules ?? 0 }), body: sev.approval_rules ? a('ready_rules_help') : a('ready_rules_ok_help') },
            { id: 'letterheads', ok: !sev.letterheads, sev: sev.letterheads, title: sev.letterheads ? a('ready_letterheads_missing') : fmt(a('ready_letterheads_ok'), { n: n.letterheads ?? 0 }), body: sev.letterheads ? a('ready_letterheads_help') : a('ready_letterheads_ok_help') },
            { id: 'seals', ok: !sev.seals, sev: sev.seals, title: sev.seals ? a('ready_seals_missing') : fmt(a('ready_seals_ok'), { n: n.seals ?? 0 }), body: sev.seals ? a('ready_seals_help') : a('ready_seals_ok_help') },
        ];
        const ready = items.filter(i => i.ok).length + (p.templates.length ? 1 : 0) + (p.reasonCodes.length ? 1 : 0);
        const total = items.length + 2;
        if (ready === total) return null;
        return (
            <section aria-labelledby="readiness" className="space-y-3 rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div className="flex items-baseline justify-between gap-3">
                    <h2 id="readiness" className="text-[15px] font-semibold text-gray-900 dark:text-slate-100">{a('readiness')}</h2>
                    <span className="text-sm text-gray-600 dark:text-slate-400">{fmt(a('readiness_count'), { ready, total })}</span>
                </div>
                <div className="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-800" role="progressbar" aria-valuemin={0} aria-valuemax={total} aria-valuenow={ready} aria-label={a('readiness')}>
                    <div className="h-full bg-[color:var(--color-primary)]" style={{ width: `${(100 * ready) / total}%` }} />
                </div>
                <div className="grid gap-2.5 sm:grid-cols-2 xl:grid-cols-4">
                    {items.map(item => {
                        const tone = item.ok ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/60 dark:bg-emerald-950/20'
                            : item.sev === 'error' ? 'border-red-200 bg-red-50 dark:border-red-900/60 dark:bg-red-950/20' : 'border-amber-200 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/20';
                        const Icon = item.ok ? CheckCircle : item.sev === 'error' ? XCircle : AlertTriangle;
                        const iconTone = item.ok ? 'text-emerald-700 dark:text-emerald-400' : item.sev === 'error' ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400';
                        return (
                            <button key={item.id} type="button" onClick={() => open(item.id)} className={`flex items-start gap-2.5 rounded-card border p-3 text-left ${tone}`}>
                                <Icon className={`mt-0.5 h-[18px] w-[18px] shrink-0 ${iconTone}`} aria-hidden="true" />
                                <span className="min-w-0"><span className="block text-[13px] font-semibold text-gray-900 dark:text-slate-100">{item.title}</span><span className="block text-xs text-gray-700 dark:text-slate-300">{item.body}</span></span>
                            </button>
                        );
                    })}
                </div>
            </section>
        );
    }
}

// ── Shared pieces ────────────────────────────────────────────────────────────

function Toggle({ id, checked, onChange, disabled, label }: { id?: string; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean; label: string }) {
    return (
        <button id={id} type="button" role="switch" aria-checked={checked} aria-label={label} disabled={disabled} onClick={() => onChange(!checked)}
            className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2 disabled:opacity-50 ${checked ? 'bg-[color:var(--color-primary)]' : 'bg-gray-300 dark:bg-slate-600'}`}>
            <span className={`inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform ${checked ? 'translate-x-[22px]' : 'translate-x-0.5'}`} />
        </button>
    );
}

function ConfirmBadge() {
    const { t } = useLocale();
    return <span className="rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300">{t('grievanceAdmin.confirm_policy')}</span>;
}

function Card({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
    return (
        <section className="space-y-4 rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center justify-between gap-3"><h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</h3>{actions}</div>
            {children}
        </section>
    );
}

/** Right-hand drawer used for every add/edit form (Esc and the backdrop close it). */
function Drawer({ title, open, onClose, children }: { title: string; open: boolean; onClose: () => void; children: ReactNode }) {
    const { t } = useLocale();
    const panel = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!open) return;
        const previous = document.activeElement as HTMLElement | null;
        panel.current?.querySelector<HTMLElement>('input, select, textarea, button')?.focus();
        const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('keydown', onKey);
        return () => { document.removeEventListener('keydown', onKey); previous?.focus(); };
    }, [open, onClose]);
    if (!open) return null;
    return (
        <div className="fixed inset-0 z-50">
            <div className="absolute inset-0 bg-slate-900/30" onClick={onClose} aria-hidden="true" />
            <div ref={panel} role="dialog" aria-modal="true" aria-label={title} className="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col border-l border-gray-200 bg-white shadow-2xl dark:border-slate-800 dark:bg-slate-900">
                <div className="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-slate-800">
                    <h2 className="text-[17px] font-semibold text-gray-900 dark:text-slate-100">{title}</h2>
                    <button type="button" onClick={onClose} aria-label={t('grievances.common.close')} className="flex h-9 w-9 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-800"><X className="h-4 w-4" /></button>
                </div>
                <div className="flex-1 overflow-y-auto px-6 py-5">{children}</div>
            </div>
        </div>
    );
}

/** A section: heading, description, New button, the table (or an empty state) and the edit drawer. */
function ListSection<T extends { id: string }>({ id, rows, head, cells, form, canEdit, emptyIcon, emptyTitle, emptyBody, emptyTone = 'neutral', intro }: {
    id: string; rows: T[]; head: string[]; cells: (row: T) => ReactNode[]; form: (row: T | null, close: () => void) => ReactNode;
    canEdit: boolean; emptyIcon?: ReactNode; emptyTitle?: string; emptyBody?: string; emptyTone?: 'neutral' | 'danger'; intro?: ReactNode;
}) {
    const { t } = useLocale();
    const a = (k: string) => t(`grievanceAdmin.${k}`);
    const [editing, setEditing] = useState<T | 'new' | null>(null);
    const close = () => setEditing(null);
    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-slate-100">{a(`tab_${id}`)}</h2>
                    <p className="max-w-2xl text-sm text-gray-600 dark:text-slate-400">{a(`intro_${id}`)}</p>
                </div>
                {canEdit && <button type="button" className={primaryBtn} onClick={() => setEditing('new')}><Plus className="h-4 w-4" aria-hidden="true" />{a(`new_${id}`)}</button>}
            </div>
            {intro}
            <section className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                {rows.length ? (
                    <Table head={<>{[...head, ''].map((h, i) => <th key={i} className={thCls}>{h ? a(`fields.${h}`) : <span className="sr-only">{t('grievances.common.actions')}</span>}</th>)}</>}>
                        {rows.map(row => (
                            <tr key={row.id} className="hover:bg-gray-50/60 dark:hover:bg-slate-800/40">
                                {cells(row).map((cell, i) => <td key={i} className={tdCls}>{cell}</td>)}
                                <td className={`${tdCls} text-right`}>{canEdit && <button type="button" className="text-sm font-semibold text-[color:var(--color-primary)] hover:underline" onClick={() => setEditing(row)}>{t('grievances.common.edit')}</button>}</td>
                            </tr>
                        ))}
                    </Table>
                ) : (
                    <div className="flex flex-col items-center gap-2.5 px-6 py-14 text-center">
                        <div className={`flex h-12 w-12 items-center justify-center rounded-full ${emptyTone === 'danger' ? 'bg-red-50 text-red-700 dark:bg-red-950/30 dark:text-red-400' : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'}`}>{emptyIcon ?? <TagsIcon className="h-5 w-5" />}</div>
                        <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{emptyTitle ?? a('empty')}</h3>
                        {emptyBody && <p className="max-w-md text-sm text-gray-600 dark:text-slate-400">{emptyBody}</p>}
                    </div>
                )}
            </section>
            <Drawer title={editing === 'new' ? a(`new_${id}`) : a(`edit_${id}`)} open={editing !== null} onClose={close}>
                {editing !== null && form(editing === 'new' ? null : editing, close)}
            </Drawer>
        </div>
    );
}

function StatusPill({ active }: { active: boolean }) {
    return <GPill group="committee_status" value={active ? 'active' : 'inactive'} />;
}

// ── Policy ───────────────────────────────────────────────────────────────────

function PolicyForm({ props }: { props: SettingsIndexProps }) {
    const { t, locale } = useLocale();
    const a = (k: string) => t(`grievanceAdmin.${k}`);
    const fields = props.fields as SettingField[];
    const byKey = useMemo(() => Object.fromEntries(fields.map(f => [f.key, f])), [fields]);
    const initial = useMemo(() => Object.fromEntries(fields.map(f => [f.key, (f.type === 'multiselect' ? (Array.isArray(f.value) ? f.value.map(String) : []) : f.value ?? '') as PolicyValue])), [fields]);
    const form = useForm<Record<string, PolicyValue>>(initial);
    const editable = props.can.update;
    const dirty = Object.keys(initial).filter(k => JSON.stringify(form.data[k]) !== JSON.stringify(initial[k])).length;
    const set = (k: string, v: PolicyValue) => form.setData(k, v);
    const labelOf = (f: SettingField) => (locale === 'am' ? f.label_am : f.label_en);
    const helpOf = (f: SettingField) => (locale === 'am' ? f.description_am : f.description_en) ?? '';
    const err = (k: string) => (form.errors as Record<string, string>)[k];

    const control = (f: SettingField) => {
        const id = `policy-${f.key}`;
        if (f.type === 'boolean') {
            return (
                <div key={f.key} className="flex items-start justify-between gap-4">
                    <label htmlFor={id} className="min-w-0"><span className="block text-sm font-medium text-gray-900 dark:text-slate-100">{labelOf(f)}</span>{helpOf(f) && <span className="block text-xs text-gray-600 dark:text-slate-400">{helpOf(f)}</span>}</label>
                    <Toggle id={id} label={labelOf(f)} checked={Boolean(form.data[f.key])} disabled={!editable} onChange={v => set(f.key, v)} />
                </div>
            );
        }
        if (f.key === 'work_week_days') {
            const days = (form.data[f.key] as string[]) ?? [];
            return (
                <fieldset key={f.key} className="space-y-2">
                    <legend className="mb-2 text-sm font-medium text-gray-900 dark:text-slate-100">{labelOf(f)}</legend>
                    <div className="flex flex-wrap gap-1.5">
                        {WEEKDAYS.map(d => {
                            const on = days.includes(d);
                            return <button key={d} type="button" aria-pressed={on} disabled={!editable} onClick={() => set(f.key, on ? days.filter(x => x !== d) : [...days, d].sort())}
                                className={`h-9 min-w-11 rounded-md px-2 text-[13px] font-semibold ${on ? 'bg-[color:var(--color-primary)] text-white' : 'border border-gray-300 text-gray-600 dark:border-slate-600 dark:text-slate-300'}`}>{a(`weekday_${d}`)}</button>;
                        })}
                    </div>
                    <p className="text-xs text-gray-600 dark:text-slate-400">{a('weekday_help')}</p>
                    {err(f.key) && <p className="text-xs text-red-600">{err(f.key)}</p>}
                </fieldset>
            );
        }
        if (f.type === 'multiselect') {
            const values = (form.data[f.key] as string[]) ?? [];
            return (
                <fieldset key={f.key} className="space-y-2">
                    <legend className="mb-2 text-sm font-medium text-gray-900 dark:text-slate-100">{labelOf(f)}</legend>
                    <div className="flex flex-wrap gap-1.5">
                        {(f.options ?? []).map(o => {
                            const on = values.includes(o);
                            return <button key={o} type="button" aria-pressed={on} disabled={!editable} onClick={() => set(f.key, on ? values.filter(x => x !== o) : [...values, o])}
                                className={`h-8 rounded-full px-3 text-xs font-semibold ${on ? 'bg-[color:var(--color-primary-50,#eef1fa)] text-[color:var(--color-primary)] ring-1 ring-[color:var(--color-primary-200,#bcc7eb)] dark:bg-slate-800' : 'border border-dashed border-gray-300 text-gray-500 dark:border-slate-600'}`}>{o}</button>;
                        })}
                    </div>
                    {helpOf(f) && <p className="text-xs text-gray-600 dark:text-slate-400">{helpOf(f)}</p>}
                    {err(f.key) && <p className="text-xs text-red-600">{err(f.key)}</p>}
                </fieldset>
            );
        }
        const input = f.type === 'select'
            ? <select id={id} className={inputCls} disabled={!editable} value={String(form.data[f.key] ?? '')} onChange={e => set(f.key, e.target.value)}>{(f.options ?? []).map(o => <option key={o} value={o}>{a(`option_${f.key}_${o}`) === `grievanceAdmin.option_${f.key}_${o}` ? o : a(`option_${f.key}_${o}`)}</option>)}</select>
            : <input id={id} type={f.type === 'integer' ? 'number' : 'text'} className={`${inputCls} ${f.type === 'integer' ? 'max-w-32' : ''}`} disabled={!editable} value={String(form.data[f.key] ?? '')} onChange={e => set(f.key, f.type === 'integer' ? (e.target.value === '' ? '' : Number(e.target.value)) : e.target.value)} />;
        return (
            <div key={f.key} className="space-y-1.5">
                <label htmlFor={id} className="block text-sm font-medium text-gray-900 dark:text-slate-100">{labelOf(f)}</label>
                {input}
                {helpOf(f) && <p className="text-xs text-gray-600 dark:text-slate-400">{helpOf(f)}</p>}
                {err(f.key) && <p className="text-xs text-red-600">{err(f.key)}</p>}
            </div>
        );
    };

    const enabled = byKey.enabled;
    const save = () => form.put(route('grievances.settings.policy.update'), { preserveScroll: true });

    return (
        <form onSubmit={e => { e.preventDefault(); save(); }} className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="space-y-1">
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-slate-100">{a('tab_policy')}</h2>
                    <p className="text-sm text-gray-600 dark:text-slate-400">{a('policy_help')}</p>
                </div>
                {enabled && <label className="flex items-center gap-3 text-sm font-medium text-gray-900 dark:text-slate-100">{labelOf(enabled)}<Toggle label={labelOf(enabled)} checked={Boolean(form.data.enabled)} disabled={!editable} onChange={v => set('enabled', v)} /></label>}
            </div>
            <div className="grid gap-4 xl:grid-cols-2">
                {POLICY_GROUPS.map(group => {
                    const groupFields = group.keys.map(k => byKey[k]).filter((f): f is SettingField => Boolean(f))
                        .filter(f => f.key !== 'quorum_fixed_count' || form.data.quorum_rule === 'fixed_count');
                    if (!groupFields.length) return null;
                    return (
                        <Card key={group.id} title={a(`policy_group_${group.id}`)} actions={group.confirm ? <ConfirmBadge /> : undefined}>
                            <div className="space-y-4">{groupFields.map(control)}</div>
                        </Card>
                    );
                })}
            </div>
            {editable && (
                <div className="sticky bottom-0 z-10 -mx-4 flex items-center justify-between gap-3 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95 sm:mx-0 sm:rounded-panel sm:border">
                    <span role="status" className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                        {dirty > 0
                            ? <><span className="h-2 w-2 rounded-full bg-amber-600" aria-hidden="true" />{fmt(a('unsaved'), { n: dirty })}</>
                            : form.recentlySuccessful ? <><CheckCircle className="h-4 w-4 text-emerald-600" aria-hidden="true" />{a('saved')}</> : a('no_changes')}
                    </span>
                    <div className="flex gap-2">
                        <button type="button" className={secondaryBtn} disabled={!dirty || form.processing} onClick={() => form.setData(initial)}>{a('discard')}</button>
                        <button type="submit" className={primaryBtn} disabled={!dirty || form.processing}>{a('save_policy')}</button>
                    </div>
                </div>
            )}
        </form>
    );
}

// ── Lists ────────────────────────────────────────────────────────────────────

function CategoriesSection({ props }: { props: SettingsIndexProps }) {
    const { t } = useLocale();
    const a = (k: string) => t(`grievanceAdmin.${k}`);
    type Row = SettingsIndexProps['categories'][number];
    return <ListSection<Row> id="categories" rows={props.categories} canEdit={props.can.update} emptyTone="danger"
        emptyTitle={a('empty_categories')} emptyBody={a('empty_categories_help')}
        head={['code', 'name', 'default_confidentiality', 'requires_executive_approval', 'grievances_count', 'status']}
        cells={c => [<code className="text-xs">{c.code}</code>, <><span className="block font-medium">{c.name_en}</span>{c.name_am && <span className="block text-xs text-gray-500">{c.name_am}</span>}</>,
            c.default_confidentiality ? <GPill group="confidentiality" value={c.default_confidentiality} /> : '—', c.requires_executive_approval ? t('grievances.common.yes') : t('grievances.common.no'), c.grievances_count, <StatusPill active={c.is_active} />]}
        form={(c, close) => <AdminForm key={c?.id ?? 'new'} onSaved={close} onCancel={close} submitLabel={a('save_category')}
            url={c ? route('grievances.settings.categories.update', c.id) : route('grievances.settings.categories.store')} method={c ? 'patch' : 'post'}
            initial={{ code: c?.code ?? '', name_en: c?.name_en ?? '', name_am: c?.name_am ?? '', description_en: c?.description_en ?? '', description_am: c?.description_am ?? '', is_active: c?.is_active ?? true, default_confidentiality: c?.default_confidentiality ?? 'normal_confidential', default_priority: c?.default_priority ?? 'normal', requires_executive_approval: c?.requires_executive_approval ?? false, sort_order: c?.sort_order ?? 0 }}
            fields={[{ key: 'code', required: true, maxLength: 60, help: a('code_help') }, { key: 'sort_order', type: 'number', min: 0 }, { key: 'name_en', required: true }, { key: 'name_am' },
                { key: 'default_confidentiality', type: 'select', options: choices(props.options.confidentiality), enumGroup: 'confidentiality' }, { key: 'default_priority', type: 'select', options: choices(props.options.priorities), enumGroup: 'priority' },
                { key: 'requires_executive_approval', type: 'boolean', help: a('requires_approval_help') }, { key: 'is_active', type: 'boolean' },
                { key: 'description_en', type: 'textarea', help: a('description_help') }, { key: 'description_am', type: 'textarea' }]} />}
        emptyIcon={<TagsIcon className="h-5 w-5" />} />;
}

function ReasonCodesSection({ props }: { props: SettingsIndexProps }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const a = (k: string) => t(`grievanceAdmin.${k}`);
    type Row = SettingsIndexProps['reasonCodes'][number];
    return <ListSection<Row> id="reason_codes" rows={props.reasonCodes} canEdit={props.can.update}
        head={['type', 'code', 'name', 'status']}
        cells={c => [label('reason_code_type', c.type), <code className="text-xs">{c.code}</code>, nameOf(c, locale), <StatusPill active={c.is_active} />]}
        form={(c, close) => <AdminForm key={c?.id ?? 'new'} onSaved={close} onCancel={close}
            url={c ? route('grievances.settings.reason-codes.update', c.id) : route('grievances.settings.reason-codes.store')} method={c ? 'patch' : 'post'}
            initial={{ type: c?.type ?? '', code: c?.code ?? '', name_en: c?.name_en ?? '', name_am: c?.name_am ?? '', is_active: c?.is_active ?? true, sort_order: c?.sort_order ?? 0 }}
            fields={[{ key: 'type', type: 'select', required: true, options: choices(props.options.reason_code_types), enumGroup: 'reason_code_type' }, { key: 'code', required: true, pattern: '[A-Z0-9_]+', help: a('code_help') }, { key: 'name_en', required: true }, { key: 'name_am' }, { key: 'sort_order', type: 'number', min: 0 }, { key: 'is_active', type: 'boolean' }]} />} />;
}

function ApprovalRulesSection({ props }: { props: SettingsIndexProps }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const a = (k: string) => t(`grievanceAdmin.${k}`);
    type Row = SettingsIndexProps['approvalRules'][number];
    const orgs = props.options.organizations.map(o => ({ value: o.id!, label: nameOf(o, locale) }));
    const title = (r: Row) => r.approver_position ? ((locale === 'am' && r.approver_position.title_am) || r.approver_position.title_en) : '—';
    return <ListSection<Row> id="approval_rules" rows={props.approvalRules} canEdit={props.can.update}
        emptyTitle={a('empty_approval_rules')} emptyBody={a('empty_approval_rules_help')}
        head={['name', 'handler_type', 'category', 'decision_type', 'approver_position', 'status']}
        cells={r => [nameOf(r, locale), r.handler ? <HandlerName handler={r.handler} showType /> : label('handler_type', r.handler_type), nameOf(r.category, locale), label('decision_type', r.decision_type), r.requires_approval ? title(r) : a('no_approval_needed'), <StatusPill active={r.is_active} />]}
        form={(r, close) => <AdminForm key={r?.id ?? 'new'} onSaved={close} onCancel={close}
            url={r ? route('grievances.settings.approval-rules.update', r.id) : route('grievances.settings.approval-rules.store')} method={r ? 'patch' : 'post'}
            initial={{ name_en: r?.name_en ?? '', name_am: r?.name_am ?? '', organization_id: r?.organization_id ?? '', handler_type: r?.handler_type ?? '', handler_id: r?.handler_id ?? '', lookup_organization_id: '', category_id: r?.category_id ?? '', decision_type: r?.decision_type ?? '', requires_approval: r?.requires_approval ?? true, approver_position_id: r?.approver_position_id ?? '', approval_sla_profile_id: r?.approval_sla_profile_id ?? '', priority: r?.priority ?? 100, effective_from: r?.effective_from ?? today(), effective_to: r?.effective_to ?? '', is_active: r?.is_active ?? true }}
            fields={[{ key: 'name_en', required: true }, { key: 'name_am' }, { key: 'organization_id', type: 'select', options: orgs },
                ...handlerFields({ options: { handler_types: props.options.handler_types, organizations: props.options.organizations, committees: [], external_authorities: [] }, locale, title: a('fields.handler_type'), initial: r?.handler }).filter(f => f.type !== 'select' || f.key !== 'handler_id'),
                { key: 'category_id', type: 'select', options: props.categories.map(c => ({ value: c.id, label: nameOf(c, locale) })) }, { key: 'decision_type', type: 'select', options: choices(props.options.decision_types), enumGroup: 'decision_type' },
                { key: 'requires_approval', type: 'boolean' }, { key: 'approver_position_id', type: 'lookup', lookup: 'positions', required: true, help: a('approval_help'), initialLabel: r ? title(r) : undefined, visible: d => Boolean(d.requires_approval) },
                { key: 'approval_sla_profile_id', type: 'select', options: props.options.approval_sla_profiles.map(p => ({ value: p.id!, label: nameOf(p, locale) })) },
                { key: 'priority', type: 'number', min: 1, max: 1000, required: true }, { key: 'effective_from', type: 'date', required: true }, { key: 'effective_to', type: 'date' }, { key: 'is_active', type: 'boolean' }] satisfies AdminField[]} />} />;
}

function ExternalAuthoritiesSection({ props }: { props: SettingsIndexProps }) {
    const { t, locale } = useLocale();
    type Row = SettingsIndexProps['externalAuthorities'][number];
    const orgs = props.options.organizations.map(o => ({ value: o.id!, label: nameOf(o, locale) }));
    return <ListSection<Row> id="external_authorities" rows={props.externalAuthorities} canEdit={props.can.update}
        head={['code', 'name', 'organization', 'is_administrative_tribunal', 'status']}
        cells={x => [<code className="text-xs">{x.code}</code>, nameOf(x, locale), nameOf(x.organization, locale), x.is_administrative_tribunal ? t('grievances.common.yes') : t('grievances.common.no'), <StatusPill active={x.is_active} />]}
        form={(x, close) => <AdminForm key={x?.id ?? 'new'} onSaved={close} onCancel={close}
            url={x ? route('grievances.settings.external-authorities.update', x.id) : route('grievances.settings.external-authorities.store')} method={x ? 'patch' : 'post'}
            initial={{ code: x?.code ?? '', name_en: x?.name_en ?? '', name_am: x?.name_am ?? '', organization_id: x?.organization_id ?? '', is_administrative_tribunal: x?.is_administrative_tribunal ?? false, address: x?.address ?? '', is_active: x?.is_active ?? true }}
            fields={[{ key: 'code', required: true }, { key: 'name_en', required: true }, { key: 'name_am' }, { key: 'organization_id', type: 'select', options: orgs }, { key: 'is_administrative_tribunal', type: 'boolean' }, { key: 'is_active', type: 'boolean' }, { key: 'address', type: 'textarea' }]} />} />;
}

function DelegationsSection({ props }: { props: SettingsIndexProps }) {
    const { t, locale } = useLocale();
    type Row = SettingsIndexProps['delegations'][number];
    const orgs = props.options.organizations.map(o => ({ value: o.id!, label: nameOf(o, locale) }));
    return <ListSection<Row> id="delegations" rows={props.delegations} canEdit={props.can.delegations}
        head={['delegator', 'delegate', 'position', 'period', 'status']}
        cells={d => [d.delegator?.name ?? '—', d.delegate?.name ?? '—', d.position ? ((locale === 'am' && d.position.title_am) || d.position.title_en) : '—',
            <><DateDisplay value={d.starts_at} /> – <DateDisplay value={d.ends_at} />{d.reason && <span className="block text-xs text-gray-500">{d.reason}</span>}</>,
            <span className="flex items-center gap-2"><StatusPill active={d.status === 'active'} />{props.can.delegations && d.status === 'active' && <RevokeButton id={d.id} />}</span>]}
        form={(_, close) => <AdminForm onSaved={close} onCancel={close} url={route('grievances.settings.delegations.store')}
            initial={{ delegator_user_id: '', delegate_user_id: '', position_id: '', organization_id: '', starts_at: today(), ends_at: '', reason: '' }}
            fields={[{ key: 'delegator_user_id', type: 'lookup', lookup: 'users', required: true }, { key: 'delegate_user_id', type: 'lookup', lookup: 'users', required: true }, { key: 'position_id', type: 'lookup', lookup: 'positions' }, { key: 'organization_id', type: 'select', options: orgs }, { key: 'starts_at', type: 'date', required: true }, { key: 'ends_at', type: 'date', required: true }, { key: 'reason', type: 'textarea', required: true }]} />} />;
}

function RevokeButton({ id }: { id: string }) {
    const { t } = useLocale();
    const form = useForm({});
    return <button type="button" className="text-xs font-semibold text-red-700 hover:underline disabled:opacity-50 dark:text-red-400" disabled={form.processing} onClick={() => form.post(route('grievances.settings.delegations.revoke', id), { preserveScroll: true })}>{t('grievanceAdmin.revoke')}</button>;
}

function LetterheadsSection({ props }: { props: SettingsIndexProps }) {
    const { t, locale } = useLocale();
    const a = (k: string) => t(`grievanceAdmin.${k}`);
    type Row = SettingsIndexProps['letterheads'][number];
    const keys = ['header_line_en', 'header_line_am', 'address_en', 'address_am', 'po_box', 'phone', 'fax', 'email', 'website', 'footer_en', 'footer_am'] as const;
    const orgs = props.options.organizations.map(o => ({ value: o.id!, label: nameOf(o, locale) }));
    return <ListSection<Row> id="letterheads" rows={props.letterheads} canEdit={props.can.update}
        emptyTitle={a('empty_letterheads')} emptyBody={a('letterhead_help')}
        head={['organization', 'address', 'phone', 'email']}
        cells={l => [nameOf(l.organization, locale), <>{(locale === 'am' ? l.address_am : l.address_en) ?? '—'}{l.po_box && <span className="block text-xs text-gray-500">{a('fields.po_box')} {l.po_box}</span>}</>, l.phone ?? '—', l.email ?? '—']}
        form={(l, close) => <AdminForm key={l?.id ?? 'new'} onSaved={close} onCancel={close} url={route('grievances.settings.letterheads.save')}
            initial={{ organization_id: l?.organization_id ?? '', ...Object.fromEntries(keys.map(k => [k, l?.[k] ?? ''])) }}
            fields={[{ key: 'organization_id', type: 'select', required: true, options: orgs }, ...keys.map(k => ({ key: k, type: k.startsWith('address') || k.startsWith('footer') ? 'textarea' as const : 'text' as const }))]} />} />;
}

function SealsSection({ props }: { props: SettingsIndexProps }) {
    const { t, locale } = useLocale();
    const a = (k: string) => t(`grievanceAdmin.${k}`);
    type Row = SettingsIndexProps['seals'][number];
    const orgs = props.options.organizations.map(o => ({ value: o.id!, label: nameOf(o, locale) }));
    return <ListSection<Row> id="seals" rows={props.seals} canEdit={props.can.seals}
        emptyTitle={a('empty_seals')} emptyBody={a('seal_help')} emptyIcon={<ShieldCheck className="h-5 w-5" />}
        head={['seal', 'organization', 'name', 'status', 'approved_at']}
        cells={s => [<img alt={s.name} className="h-12 w-12 rounded border border-gray-200 bg-white object-contain dark:border-slate-700" src={route('grievances.settings.seals.image', s.id)} />, nameOf(s.organization, locale),
            <>{s.name}<span className="block font-mono text-[11px] text-gray-500" title={s.sha256}>SHA-256 {s.sha256.slice(0, 12)}…</span></>,
            <span className="flex flex-wrap items-center gap-2"><GPill group="seal_status" value={s.status} />{props.can.seals && s.status === 'pending' && <SealAction id={s.id} action="approve" label={a('approve')} />}{props.can.seals && s.status !== 'retired' && <SealAction id={s.id} action="retire" label={a('retire')} />}</span>,
            <DateDisplay value={s.approved_at} />]}
        form={(_, close) => <AdminForm onSaved={close} onCancel={close} url={route('grievances.settings.seals.store')} submitLabel={a('upload_seal')}
            initial={{ organization_id: '', name: '', file: null, effective_from: today() }}
            fields={[{ key: 'organization_id', type: 'select', required: true, options: orgs }, { key: 'name', required: true }, { key: 'file', type: 'file', required: true, accept: 'image/png', help: a('seal_file_help') }, { key: 'effective_from', type: 'date' }]}>
            <p className="rounded-md bg-gray-50 p-3 text-xs text-gray-600 dark:bg-slate-800 dark:text-slate-300">{a('seal_help')}</p>
        </AdminForm>} />;
}

function SealAction({ id, action, label }: { id: string; action: 'approve' | 'retire'; label: string }) {
    const form = useForm({ action });
    return <button type="button" className="text-xs font-semibold text-[color:var(--color-primary)] hover:underline disabled:opacity-50" disabled={form.processing} onClick={() => form.post(route('grievances.settings.seals.decide', id), { preserveScroll: true })}>{label}</button>;
}

// ── Letter templates: list + editor with token insertion and preview ────────

const SAMPLE: Record<string, string> = {
    case_number: 'GRV-2026-000001', reference_number: 'GRL-2026-000001', submission_date: '28/09/2026', letter_date: '28/09/2026', decision_date: '28/09/2026',
};

function TemplatesSection({ props }: { props: SettingsIndexProps }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    type Row = SettingsIndexProps['templates'][number];
    return <ListSection<Row> id="templates" rows={props.templates} canEdit={props.can.update}
        head={['template_type', 'language', 'name', 'organization', 'status']}
        cells={x => [label('letter_type', x.template_type), <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold dark:bg-slate-800">{x.language.toUpperCase()}</span>, x.name, x.organization ? nameOf(x.organization, locale) : t('grievanceAdmin.all_organizations'), <StatusPill active={x.is_active} />]}
        form={(x, close) => <TemplateEditor key={x?.id ?? 'new'} row={x} props={props} close={close} />} />;
}

function TemplateEditor({ row, props, close }: { row: SettingsIndexProps['templates'][number] | null; props: SettingsIndexProps; close: () => void }) {
    const { t, locale } = useLocale();
    const a = (k: string) => t(`grievanceAdmin.${k}`);
    const label = useEnumLabel();
    const body = useRef<HTMLTextAreaElement>(null);
    const form = useForm({
        organization_id: row?.organization_id ?? '', template_type: row?.template_type ?? '', language: row?.language ?? 'am', name: row?.name ?? '',
        subject_template: row?.subject_template ?? '', body_template: row?.body_template ?? '', effective_from: row?.effective_from ?? today(), effective_to: row?.effective_to ?? '', is_active: row?.is_active ?? true,
    });
    const errors = form.errors as Record<string, string>;
    const insert = (token: string) => {
        const el = body.current;
        const text = `{{${token}}}`;
        const start = el?.selectionStart ?? form.data.body_template.length;
        const end = el?.selectionEnd ?? start;
        form.setData('body_template', form.data.body_template.slice(0, start) + text + form.data.body_template.slice(end));
        requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(start + text.length, start + text.length); });
    };
    const preview = (text: string) => text.split(/(\{\{\s*[a-z_]+\s*\}\})/g).map((part, i) => {
        const m = part.match(/^\{\{\s*([a-z_]+)\s*\}\}$/);
        if (!m) return <span key={i}>{part}</span>;
        const known = props.options.tokens.includes(m[1]);
        return <mark key={i} className={known ? 'rounded bg-[color:var(--color-primary-50,#eef1fa)] px-0.5 text-[color:var(--color-primary)]' : 'rounded bg-red-100 px-0.5 text-red-800'}>{SAMPLE[m[1]] ?? `[${m[1].replace(/_/g, ' ')}]`}</mark>;
    });
    const unknown = [...new Set([...(form.data.subject_template + form.data.body_template).matchAll(/\{\{\s*([a-z_]+)\s*\}\}/g)].map(m => m[1]).filter(x => !props.options.tokens.includes(x)))];
    const url = row ? route('grievances.settings.templates.update', row.id) : route('grievances.settings.templates.store');
    const field = 'block text-sm font-medium text-gray-900 dark:text-slate-100';

    return (
        <form className="space-y-4" onSubmit={e => { e.preventDefault(); form.transform(d => ({ ...d, organization_id: d.organization_id || null, effective_to: d.effective_to || null })); form.submit(row ? 'patch' : 'post', url, { preserveScroll: true, onSuccess: close }); }}>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="space-y-1.5"><span className={field}>{a('fields.template_type')}</span><select className={inputCls} required value={form.data.template_type} onChange={e => form.setData('template_type', e.target.value)}><option value="">{t('grievances.common.select')}</option>{props.options.letter_types.map(v => <option key={v} value={v}>{label('letter_type', v)}</option>)}</select></label>
                <div className="space-y-1.5"><span className={field}>{a('fields.language')}</span>
                    <div role="radiogroup" aria-label={a('fields.language')} className="flex gap-1 rounded-lg bg-gray-100 p-1 dark:bg-slate-800">
                        {props.options.letter_languages.map(v => <button key={v} type="button" role="radio" aria-checked={form.data.language === v} onClick={() => form.setData('language', v)} className={`h-8 flex-1 rounded-md text-sm ${form.data.language === v ? 'bg-white font-semibold shadow-sm dark:bg-slate-900' : 'text-gray-600 dark:text-slate-400'}`}>{label('letter_language', v)}</button>)}
                    </div>
                </div>
                <label className="space-y-1.5 sm:col-span-2"><span className={field}>{a('fields.name')}</span><input className={inputCls} required value={form.data.name} onChange={e => form.setData('name', e.target.value)} /></label>
                <label className="space-y-1.5 sm:col-span-2"><span className={field}>{a('fields.subject_template')}</span><input className={inputCls} required value={form.data.subject_template} onChange={e => form.setData('subject_template', e.target.value)} /></label>
            </div>
            <label className="block space-y-1.5"><span className={field}>{a('fields.body_template')}</span>
                <textarea ref={body} rows={10} required className={`${inputCls} font-mono text-[13px] leading-relaxed`} value={form.data.body_template} onChange={e => form.setData('body_template', e.target.value)} />
            </label>
            <div className="space-y-2">
                <p className="text-sm font-medium text-gray-900 dark:text-slate-100">{a('insert_token')}</p>
                <div className="flex flex-wrap gap-1.5">{props.options.tokens.map(tok => <button key={tok} type="button" onClick={() => insert(tok)} className="h-7 rounded-full border border-[color:var(--color-primary-200,#bcc7eb)] bg-[color:var(--color-primary-50,#eef1fa)] px-2.5 font-mono text-[11px] text-[color:var(--color-primary)] hover:bg-white dark:border-slate-700 dark:bg-slate-800">{tok}</button>)}</div>
                <p className="text-xs text-gray-600 dark:text-slate-400">{a('template_help')}</p>
                {unknown.length > 0 && <p role="alert" className="text-xs font-medium text-red-700">{fmt(a('unknown_tokens'), { tokens: unknown.join(', ') })}</p>}
            </div>
            <div className="space-y-2">
                <p className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">{a('preview_sample')}</p>
                <div className="space-y-2 rounded border border-gray-200 bg-white p-5 text-[13px] leading-relaxed text-gray-900 shadow-sm">
                    <p className="font-semibold underline">{preview(form.data.subject_template)}</p>
                    <p className="whitespace-pre-wrap">{preview(form.data.body_template)}</p>
                </div>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="space-y-1.5"><span className={field}>{a('fields.organization')}</span><select className={inputCls} value={form.data.organization_id} onChange={e => form.setData('organization_id', e.target.value)}><option value="">{a('all_organizations')}</option>{props.options.organizations.map(o => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}</select></label>
                <label className="flex items-center justify-between gap-3 pt-6 text-sm font-medium text-gray-900 dark:text-slate-100">{a('fields.is_active')}<Toggle label={a('fields.is_active')} checked={form.data.is_active} onChange={v => form.setData('is_active', v)} /></label>
            </div>
            {Object.keys(errors).length > 0 && <div role="alert" className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{Object.values(errors).map((e, i) => <p key={i}>{e}</p>)}</div>}
            <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-slate-800">
                <button type="button" className={secondaryBtn} onClick={close}>{t('grievances.common.cancel')}</button>
                <button type="submit" className={primaryBtn} disabled={form.processing}>{a('save_template')}</button>
            </div>
        </form>
    );
}
