import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { dangerBtn, errorCls, inputCls, labelCls, panelCls, primaryBtn, scrollCls, secondaryBtn, tableCls, tdCls, thCls, useSm } from '@/Components/serviceMonitoring/helpers';
import type { AccountabilityRow, FinalAction, FormDescription, ReasonCategory, ReportingLevel, RoleCategory, SubjectType, Suggestion, Summary } from '@/types/serviceMonitoring';
import { Deferred, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useId, useState, type FormEvent, type JSX } from 'react';

const ROLES: RoleCategory[] = ['appointee_leader', 'merit_leader', 'professional', 'customer'];
const REASONS: ReasonCategory[] = ['service_delay', 'theft_corruption', 'malpractice'];
const ACTIONS: FinalAction[] = ['warning', 'salary_fine', 'demotion', 'removal_from_responsibility', 'dismissal', 'legal_action', 'other'];

type EntryForm = {
    subject_type: SubjectType; employee_id: string; full_name: string; office_name: string; role_category: RoleCategory; reason_category: ReasonCategory;
    reason_detail: string; final_action: FinalAction; action_detail: string; action_date: string; remark: string; repeat_confirmed: boolean;
};

const blank = (date: string): EntryForm => ({
    subject_type: 'employee', employee_id: '', full_name: '', office_name: '', role_category: 'professional', reason_category: 'service_delay',
    reason_detail: '', final_action: 'warning', action_detail: '', action_date: date, remark: '', repeat_confirmed: false,
});

interface Props {
    submissionId: string;
    level: ReportingLevel;
    quarter: { starts_on: string; ends_on: string };
    definition: FormDescription;
    entries: AccountabilityRow[];
    named: boolean;
    summary: Summary;
    editable: boolean;
    suggestions?: Suggestion[];
}

/** Section I.1 rows (D12–D14), the discipline suggestions (D16) and this report's I.2 summary (D15). */
export default function Section1Panel({ submissionId, level, quarter, definition, entries, named, summary, editable, suggestions }: Props): JSX.Element {
    const { sm } = useSm();
    const id = useId();
    const [editing, setEditing] = useState<AccountabilityRow | 'new' | null>(null);
    const [accepting, setAccepting] = useState<Suggestion | null>(null);
    const [search, setSearch] = useState('');
    const [employees, setEmployees] = useState<{ id: string; full_name: string | null; employee_number: string }[]>([]);
    const form = useForm<EntryForm>(blank(quarter.starts_on));
    const accept = useForm({ decision_id: '', role_category: 'professional' as RoleCategory, reason_category: 'service_delay' as ReasonCategory, reason_detail: '', office_name: '', remark: '', repeat_confirmed: false });

    useEffect(() => {
        if (editing === null || form.data.subject_type !== 'employee' || search.trim().length < 2) return;
        const timer = window.setTimeout(() => {
            axios.get(route('service-monitoring.submissions.employees', submissionId), { params: { q: search } }).then((r) => setEmployees(r.data));
        }, 300);
        return () => window.clearTimeout(timer);
    }, [search, editing, form.data.subject_type, submissionId]);

    function open(row: AccountabilityRow | null) {
        form.clearErrors();
        form.setData(row ? {
            subject_type: row.subject_type, employee_id: row.employee_id ?? '', full_name: row.full_name ?? '', office_name: row.office_name ?? '',
            role_category: row.role_category, reason_category: row.reason_category, reason_detail: row.reason_detail ?? '', final_action: row.final_action,
            action_detail: row.action_detail ?? '', action_date: row.action_date, remark: row.remark ?? '', repeat_confirmed: row.repeat_confirmed,
        } : blank(quarter.starts_on));
        setEditing(row ?? 'new');
    }

    function save(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing === 'new') form.post(route('service-monitoring.submissions.section1.store', submissionId), options);
        else if (editing) form.put(route('service-monitoring.submissions.section1.update', [submissionId, editing.id]), options);
    }

    function remove(row: AccountabilityRow) {
        router.delete(route('service-monitoring.submissions.section1.destroy', [submissionId, row.id]), { preserveScroll: true });
    }

    function startAccept(s: Suggestion) {
        accept.clearErrors();
        accept.setData({ decision_id: s.decision_id, role_category: s.role_category ?? 'professional', reason_category: 'service_delay', reason_detail: '', office_name: '', remark: '', repeat_confirmed: false });
        setAccepting(s);
    }

    function submitAccept(event: FormEvent) {
        event.preventDefault();
        accept.post(route('service-monitoring.submissions.section1.suggestion', submissionId), { preserveScroll: true, onSuccess: () => setAccepting(null) });
    }

    const roleLabel = (r: RoleCategory) => definition.section_1.role_columns.find((c) => c.value === r)?.label ?? sm(`enums.role_category.${r}`);
    const field = (key: keyof EntryForm, label: string, input: JSX.Element) => (
        <div>
            <label htmlFor={`${id}-${key}`} className={labelCls}>{label}</label>
            {input}
            {form.errors[key] && <p className={errorCls} id={`${id}-${key}-error`}>{form.errors[key]}</p>}
        </div>
    );
    const groups = definition.section_1.summary.groups;

    return (
        <div className="space-y-4">
            <p className="text-sm font-semibold text-(--app-foreground)">{definition.section_1.heading}</p>
            {!named && <p className="rounded-panel border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">{sm('edit.section1.namesHidden')}</p>}

            <div className={scrollCls}>
                <table className={tableCls}>
                    <thead>
                        <tr>
                            <th scope="col" className={thCls}>{definition.section_1.columns[0].label}</th>
                            <th scope="col" className={thCls}>{definition.section_1.columns[1].label}</th>
                            <th scope="col" className={thCls}>{sm('edit.section1.role')}</th>
                            <th scope="col" className={thCls}>{definition.section_1.columns[2].label}</th>
                            <th scope="col" className={thCls}>{definition.section_1.columns[3].label}</th>
                            <th scope="col" className={thCls}>{sm('edit.section1.actionDate')}</th>
                            <th scope="col" className={thCls}>{definition.section_1.tables[definition.section_1.tables.length - 1].remark_column}</th>
                            {editable && <th scope="col" className={thCls}>{sm('common.actions')}</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {entries.length === 0 && <tr><td className={tdCls} colSpan={editable ? 8 : 7}>{sm('common.empty')}</td></tr>}
                        {entries.map((row) => (
                            <tr key={row.id} className={row.repeat_warning && !row.repeat_confirmed ? 'bg-amber-50 dark:bg-amber-950' : undefined}>
                                <td className={tdCls}>{row.sequence}</td>
                                <td className={tdCls}>{named ? row.full_name ?? '—' : sm(`enums.subject_type.${row.subject_type}`)}{row.source === 'discipline' && <span className="ms-1 text-xs text-(--app-muted-foreground)">({sm('enums.source.discipline')})</span>}</td>
                                <td className={tdCls}>{roleLabel(row.role_category)}</td>
                                <td className={tdCls}>{named ? row.reason_detail : sm(`enums.reason_category.${row.reason_category}`)}</td>
                                <td className={tdCls}>{named ? row.action_detail : sm(`enums.final_action.${row.final_action}`)}<p className="text-xs text-(--app-muted-foreground)">{sm(`enums.final_action.${row.final_action}`)}</p></td>
                                <td className={tdCls}><LocalizedDateDisplay value={row.action_date} /></td>
                                <td className={tdCls}>{[row.office_name, row.remark].filter(Boolean).join(' — ') || '—'}{row.repeat_warning && <p className="text-xs text-amber-800 dark:text-amber-200">{sm('edit.section1.repeat')}</p>}</td>
                                {editable && (
                                    <td className={tdCls}>
                                        <div className="flex gap-1">
                                            {named && <button type="button" className={secondaryBtn} onClick={() => open(row)}>{sm('common.edit')}</button>}
                                            <button type="button" className={dangerBtn} onClick={() => remove(row)}>{sm('common.delete')}</button>
                                        </div>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {editable && editing === null && <button type="button" className={primaryBtn} onClick={() => open(null)}>{sm('edit.section1.add')}</button>}

            {editing !== null && (
                <form onSubmit={save} className={`${panelCls} grid gap-3 p-4 sm:grid-cols-2`} aria-label={sm('edit.section1.add')}>
                    {field('subject_type', sm('edit.section1.subject'), (
                        <select id={`${id}-subject_type`} className={inputCls} value={form.data.subject_type}
                            onChange={(e) => { const v = e.target.value as SubjectType; form.setData((d) => ({ ...d, subject_type: v, role_category: v === 'customer' ? 'customer' : 'professional' })); }}>
                            <option value="employee">{sm('enums.subject_type.employee')}</option>
                            <option value="customer">{sm('enums.subject_type.customer')}</option>
                        </select>
                    ))}
                    {form.data.subject_type === 'employee' ? field('employee_id', sm('edit.section1.employee'), (
                        <div className="space-y-1">
                            <input type="search" className={inputCls} aria-label={sm('edit.section1.searchEmployee')} placeholder={sm('edit.section1.searchEmployee')} value={search} onChange={(e) => setSearch(e.target.value)} />
                            <select id={`${id}-employee_id`} className={inputCls} value={form.data.employee_id} onChange={(e) => form.setData('employee_id', e.target.value)}>
                                <option value="">—</option>
                                {employees.map((e) => <option key={e.id} value={e.id}>{e.full_name} ({e.employee_number})</option>)}
                            </select>
                        </div>
                    )) : field('full_name', sm('edit.section1.fullName'), <input id={`${id}-full_name`} className={inputCls} value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} />)}
                    {field('role_category', sm('edit.section1.role'), (
                        <select id={`${id}-role_category`} className={inputCls} value={form.data.role_category} onChange={(e) => form.setData('role_category', e.target.value as RoleCategory)}>
                            {ROLES.filter((r) => (form.data.subject_type === 'customer') === (r === 'customer')).map((r) => <option key={r} value={r}>{roleLabel(r)}</option>)}
                        </select>
                    ))}
                    {field('reason_category', sm('edit.section1.reasonCategory'), (
                        <select id={`${id}-reason_category`} className={inputCls} value={form.data.reason_category} onChange={(e) => form.setData('reason_category', e.target.value as ReasonCategory)}>
                            {REASONS.map((r) => <option key={r} value={r}>{sm(`enums.reason_category.${r}`)}</option>)}
                        </select>
                    ))}
                    {field('reason_detail', definition.section_1.columns[2].label, <textarea id={`${id}-reason_detail`} rows={3} className={inputCls} value={form.data.reason_detail} onChange={(e) => form.setData('reason_detail', e.target.value)} required />)}
                    {field('final_action', sm('edit.section1.finalAction'), (
                        <select id={`${id}-final_action`} className={inputCls} value={form.data.final_action} onChange={(e) => form.setData('final_action', e.target.value as FinalAction)}>
                            {ACTIONS.map((a) => <option key={a} value={a}>{sm(`enums.final_action.${a}`)}</option>)}
                        </select>
                    ))}
                    {field('action_detail', definition.section_1.columns[3].label, <textarea id={`${id}-action_detail`} rows={3} className={inputCls} value={form.data.action_detail} onChange={(e) => form.setData('action_detail', e.target.value)} required />)}
                    {field('action_date', sm('edit.section1.actionDate'), (
                        <div>
                            <input id={`${id}-action_date`} type="date" min={quarter.starts_on} max={quarter.ends_on} className={inputCls} value={form.data.action_date} onChange={(e) => form.setData('action_date', e.target.value)} required />
                            <p className="mt-1 text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={form.data.action_date} /></p>
                        </div>
                    ))}
                    {field('office_name', sm('edit.section1.office'), <input id={`${id}-office_name`} className={inputCls} value={form.data.office_name} required={level === 'woreda'} onChange={(e) => form.setData('office_name', e.target.value)} />)}
                    {field('remark', sm('edit.section1.remark'), <input id={`${id}-remark`} className={inputCls} value={form.data.remark} onChange={(e) => form.setData('remark', e.target.value)} />)}
                    {(form.errors.repeat_confirmed || (editing !== 'new' && editing.repeat_warning)) && (
                        <label className="flex items-start gap-2 text-sm sm:col-span-2">
                            <input type="checkbox" checked={form.data.repeat_confirmed} onChange={(e) => form.setData('repeat_confirmed', e.target.checked)} />
                            <span>{sm('edit.section1.repeat')} <strong>{sm('edit.section1.repeatConfirm')}</strong></span>
                        </label>
                    )}
                    <div className="flex gap-2 sm:col-span-2">
                        <button type="submit" className={primaryBtn} disabled={form.processing}>{sm('common.save')}</button>
                        <button type="button" className={secondaryBtn} onClick={() => setEditing(null)}>{sm('common.cancel')}</button>
                    </div>
                </form>
            )}

            {editable && (
                <aside className={`${panelCls} space-y-2 p-4`} aria-labelledby={`${id}-sugg`}>
                    <h3 id={`${id}-sugg`} className="text-sm font-semibold">{sm('edit.section1.suggestions')}</h3>
                    <p className="text-xs text-(--app-muted-foreground)">{sm('edit.section1.suggestionsHint')}</p>
                    <Deferred data="suggestions" fallback={() => <p className="text-sm text-(--app-muted-foreground)">{sm('common.loading')}</p>}>
                        {(suggestions ?? []).length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{sm('edit.section1.noSuggestions')}</p> : (
                            <ul className="divide-y divide-(--app-border) text-sm">
                                {(suggestions ?? []).map((s) => (
                                    <li key={s.decision_id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                        <span>{s.employee_name ?? sm('common.masked')} · {sm(`enums.final_action.${s.final_action}`)}{s.penalty_amount ? ` (${s.penalty_amount})` : ''} · <LocalizedDateDisplay value={s.action_date} />{s.case_number ? ` · ${s.case_number}` : ''}</span>
                                        {s.used ? <span className="text-xs text-(--app-muted-foreground)">{sm('edit.section1.used')}</span> : <button type="button" className={secondaryBtn} onClick={() => startAccept(s)}>{sm('edit.section1.accept')}</button>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Deferred>
                    {accepting && (
                        <form onSubmit={submitAccept} className="grid gap-3 border-t border-(--app-border) pt-3 sm:grid-cols-2">
                            <div>
                                <label htmlFor={`${id}-acc-role`} className={labelCls}>{sm('edit.section1.role')}</label>
                                <select id={`${id}-acc-role`} className={inputCls} value={accept.data.role_category} onChange={(e) => accept.setData('role_category', e.target.value as RoleCategory)}>
                                    {ROLES.filter((r) => r !== 'customer').map((r) => <option key={r} value={r}>{roleLabel(r)}</option>)}
                                </select>
                            </div>
                            <div>
                                <label htmlFor={`${id}-acc-reason`} className={labelCls}>{sm('edit.section1.reasonCategory')}</label>
                                <select id={`${id}-acc-reason`} className={inputCls} value={accept.data.reason_category} onChange={(e) => accept.setData('reason_category', e.target.value as ReasonCategory)}>
                                    {REASONS.map((r) => <option key={r} value={r}>{sm(`enums.reason_category.${r}`)}</option>)}
                                </select>
                            </div>
                            <div className="sm:col-span-2">
                                <label htmlFor={`${id}-acc-detail`} className={labelCls}>{definition.section_1.columns[2].label}</label>
                                <textarea id={`${id}-acc-detail`} rows={2} className={inputCls} value={accept.data.reason_detail} onChange={(e) => accept.setData('reason_detail', e.target.value)} required />
                                {accept.errors.reason_detail && <p className={errorCls}>{accept.errors.reason_detail}</p>}
                            </div>
                            <div>
                                <label htmlFor={`${id}-acc-office`} className={labelCls}>{sm('edit.section1.office')}</label>
                                <input id={`${id}-acc-office`} className={inputCls} value={accept.data.office_name} required={level === 'woreda'} onChange={(e) => accept.setData('office_name', e.target.value)} />
                                {accept.errors.office_name && <p className={errorCls}>{accept.errors.office_name}</p>}
                            </div>
                            {accept.errors.repeat_confirmed && (
                                <label className="flex items-start gap-2 text-sm sm:col-span-2">
                                    <input type="checkbox" checked={accept.data.repeat_confirmed} onChange={(e) => accept.setData('repeat_confirmed', e.target.checked)} />
                                    <span>{accept.errors.repeat_confirmed} <strong>{sm('edit.section1.repeatConfirm')}</strong></span>
                                </label>
                            )}
                            {accept.errors.decision_id && <p className={`${errorCls} sm:col-span-2`}>{accept.errors.decision_id}</p>}
                            <div className="flex gap-2 sm:col-span-2">
                                <button type="submit" className={primaryBtn} disabled={accept.processing}>{sm('edit.section1.accept')}</button>
                                <button type="button" className={secondaryBtn} onClick={() => setAccepting(null)}>{sm('common.cancel')}</button>
                            </div>
                        </form>
                    )}
                </aside>
            )}

            <section aria-labelledby={`${id}-summary`} className="space-y-2">
                <h3 id={`${id}-summary`} className="text-sm font-semibold">{sm('edit.section1.summary')}: {definition.section_1.summary.title}</h3>
                <div className={scrollCls}>
                    <table className={tableCls}>
                        <thead>
                            <tr>
                                <th scope="col" rowSpan={2} className={thCls}>{definition.section_1.summary.action_column}</th>
                                {groups.map((g) => <th key={g.level} scope="colgroup" colSpan={definition.section_1.summary.role_columns.length + 1} className={`${thCls} text-center`}>{g.label}</th>)}
                            </tr>
                            <tr>
                                {groups.flatMap((g) => [...definition.section_1.summary.role_columns.map((r) => <th key={`${g.level}-${r.value}`} scope="col" className={thCls}>{r.label}</th>), <th key={`${g.level}-t`} scope="col" className={thCls}>{definition.section_1.summary.total}</th>])}
                            </tr>
                        </thead>
                        <tbody>
                            {definition.section_1.summary.rows.map((row) => (
                                <tr key={row.value}>
                                    <th scope="row" className={thCls}>{row.label}</th>
                                    {groups.flatMap((g) => [
                                        ...definition.section_1.summary.role_columns.map((r) => <td key={`${g.level}-${r.value}`} className={`${tdCls} text-end tabular-nums`}>{summary.matrix[g.level]?.[row.value]?.[r.value] ?? 0}</td>),
                                        <td key={`${g.level}-t`} className={`${tdCls} text-end font-semibold tabular-nums`}>{summary.row_totals[g.level]?.[row.value] ?? 0}</td>,
                                    ])}
                                </tr>
                            ))}
                            <tr className="font-semibold">
                                <th scope="row" className={thCls}>{definition.section_1.summary.total}</th>
                                {groups.flatMap((g) => [
                                    ...definition.section_1.summary.role_columns.map((r) => <td key={`${g.level}-${r.value}`} className={`${tdCls} text-end tabular-nums`}>{summary.column_totals[g.level]?.[r.value] ?? 0}</td>),
                                    <td key={`${g.level}-t`} className={`${tdCls} text-end tabular-nums`}>{summary.level_totals[g.level] ?? 0}</td>,
                                ])}
                            </tr>
                        </tbody>
                    </table>
                </div>
                {definition.section_1.summary.notes.map((n, i) => <p key={i} className="text-xs text-(--app-muted-foreground)">{n}</p>)}
            </section>
        </div>
    );
}
