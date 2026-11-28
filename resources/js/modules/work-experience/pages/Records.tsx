import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import ConfirmationDialog from '@/Components/ConfirmationDialog';
import EmptyState from '@/Components/EmptyState';
import { DownloadIcon, EyeIcon, PencilIcon, Plus, TrashIcon } from '@/Components/Icons';
import PageHeader from '@/Components/PageHeader';
import { AppActionMenu, FilterField, FilterPanel, ListPagination, Table, TableCard, TableTools, TBody, Td, Th, THead, Tr, badgeClassName, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    ActionErrors, ExperiencePopup, LinesTable, Notice, errorCls, inputCls, labelCls, mutedCls, primaryBtn, secondaryBtn, useExperience, usePopupRecord,
    type Bilingual, type Duration, type EmployeeRef, type ExperienceLine, type Paginated,
} from '@/modules/work-experience/lib';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

type EmployeeRow = EmployeeRef & { position: Bilingual | null; unit: Bilingual | null; service_start_date: string | null; assignments_count: number; records_count: number };

interface Selected {
    employee: EmployeeRef;
    lines: ExperienceLine[];
    total: Duration;
    can_record: boolean;
}

interface Props {
    experienceEmployees: Paginated<EmployeeRow>;
    filters: { search: string; service: string };
    experienceSelected: Selected | null;
    canRecordAny: boolean;
    today: string;
}

/** What the form is doing: a new line, the details of an assignment, or a correction of a record. */
type Editing = { mode: 'new' } | { mode: 'details'; line: ExperienceLine } | { mode: 'edit'; line: ExperienceLine };

const FIELDS = ['kind', 'employer_name', 'position_title', 'unit_name', 'started_on', 'ended_on', 'monthly_salary', 'note', 'evidence', 'employee_assignment_id'];

function RecordForm({ employee, editing, today, onDone }: { employee: EmployeeRef; editing: Editing; today: string; onDone: () => void }): JSX.Element {
    const { wt, pick } = useExperience();
    const line = editing.mode === 'new' ? null : editing.line;
    // The details of an assignment keep its institution; only a line the office writes itself chooses one.
    const linked = line !== null && line.source === 'assignment';
    const form = useForm<{ employee_assignment_id: string; kind: string; employer_name: string; position_title: string; unit_name: string; started_on: string; ended_on: string; monthly_salary: string; note: string; evidence: File | null }>({
        employee_assignment_id: editing.mode === 'details' ? (line?.assignment_id ?? '') : '',
        kind: line?.kind ?? 'internal',
        employer_name: line && !linked ? pick(line, 'employer') : '',
        position_title: line ? pick(line, 'position') : '',
        unit_name: line ? pick(line, 'unit') : '',
        started_on: line?.started_on ?? '',
        ended_on: line?.ended_on ?? '',
        monthly_salary: line?.monthly_salary === null || line?.monthly_salary === undefined ? '' : String(line.monthly_salary),
        note: line?.note ?? '',
        evidence: null,
    });
    const fieldError = (field: keyof typeof form.data) => (form.errors[field] ? <p className={errorCls}>{form.errors[field]}</p> : null);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const target = editing.mode === 'edit' && line?.record_id
            ? route('work-experience.records.update', line.record_id)
            : route('work-experience.records.store', employee.id);
        form.post(target, { forceFormData: true, preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-4 rounded-panel border border-(--app-border) bg-(--app-surface) p-4">
            <h3 className="text-sm font-semibold text-(--app-foreground)">{wt(`records.form.${editing.mode}`)}</h3>
            {linked && <Notice tone="info">{wt('records.form.linkedHelp', { employer: line ? pick(line, 'employer') : '' })}</Notice>}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {!linked && (
                    <div>
                        <label htmlFor="wx-kind" className={labelCls}>{wt('records.form.kind')}<span className="text-red-600"> *</span></label>
                        <select id="wx-kind" className={inputCls} value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                            <option value="internal">{wt('records.form.internal')}</option>
                            <option value="external">{wt('records.form.external')}</option>
                        </select>
                        {fieldError('kind')}
                    </div>
                )}
                {!linked && (
                    <div>
                        <label htmlFor="wx-employer" className={labelCls}>{wt('lines.employer')}{form.data.kind === 'external' && <span className="text-red-600"> *</span>}</label>
                        <input id="wx-employer" type="text" maxLength={255} className={inputCls} value={form.data.employer_name} onChange={(e) => form.setData('employer_name', e.target.value)} />
                        {fieldError('employer_name')}
                    </div>
                )}
                <div>
                    <label htmlFor="wx-position" className={labelCls}>{wt('lines.position')}<span className="text-red-600"> *</span></label>
                    <input id="wx-position" type="text" maxLength={255} className={inputCls} value={form.data.position_title} onChange={(e) => form.setData('position_title', e.target.value)} />
                    {fieldError('position_title')}
                </div>
                <div>
                    <label htmlFor="wx-unit" className={labelCls}>{wt('lines.unit')}</label>
                    <input id="wx-unit" type="text" maxLength={255} className={inputCls} value={form.data.unit_name} onChange={(e) => form.setData('unit_name', e.target.value)} />
                    {fieldError('unit_name')}
                </div>
                <div>
                    <span className={labelCls}>{wt('lines.from')}<span className="text-red-600"> *</span></span>
                    <LocalizedDatePicker value={form.data.started_on} max={today} onChange={(v) => form.setData('started_on', v)} />
                    {fieldError('started_on')}
                </div>
                <div>
                    <span className={labelCls}>{wt('lines.until')}</span>
                    <LocalizedDatePicker value={form.data.ended_on} min={form.data.started_on || undefined} max={today} onChange={(v) => form.setData('ended_on', v)} />
                    <p className={cx(mutedCls, 'mt-1 text-xs')}>{wt('records.form.untilHelp')}</p>
                    {fieldError('ended_on')}
                </div>
                <div>
                    <label htmlFor="wx-salary" className={labelCls}>{wt('lines.salary')}</label>
                    <input id="wx-salary" type="number" min={0} step="0.01" inputMode="decimal" className={inputCls} value={form.data.monthly_salary} onChange={(e) => form.setData('monthly_salary', e.target.value)} />
                    {fieldError('monthly_salary')}
                </div>
                <div className="sm:col-span-2">
                    <label htmlFor="wx-note" className={labelCls}>{wt('records.form.note')}</label>
                    <input id="wx-note" type="text" maxLength={1000} className={inputCls} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                    {fieldError('note')}
                </div>
                <div className="sm:col-span-2 lg:col-span-3">
                    <label htmlFor="wx-evidence" className={labelCls}>{wt('records.form.evidence')}</label>
                    <input id="wx-evidence" type="file" accept=".pdf,.jpg,.jpeg,.png" className="block w-full text-sm" onChange={(e) => form.setData('evidence', e.target.files?.[0] ?? null)} />
                    <p className={cx(mutedCls, 'mt-1 text-xs')}>{line?.evidence_name ? wt('records.form.evidenceReplace', { name: line.evidence_name }) : wt('records.form.evidenceHelp')}</p>
                    {fieldError('evidence')}
                </div>
            </div>
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" className={secondaryBtn} onClick={onDone}>{wt('common.cancel')}</button>
                <button type="submit" className={primaryBtn} disabled={form.processing || form.data.position_title.trim() === '' || form.data.started_on === ''}>{wt('common.save')}</button>
            </div>
        </form>
    );
}

/**
 * Employee management > Work experience: the office that manages employees
 * keeps each employee's experience here. The assignments the system holds
 * come by themselves; the office adds the salary and what the system does
 * not know (service before the system, service in another institution).
 */
export default function Records({ experienceEmployees, filters, experienceSelected, canRecordAny, today }: Props): JSX.Element {
    const { wt, name } = useExperience();
    const [draft, setDraft] = useState({ search: filters.search, service: filters.service });
    const popup = usePopupRecord('selected', 'experienceSelected', ['experienceEmployees'], experienceSelected?.employee.id ?? null);
    const [editing, setEditing] = useState<Editing | null>(null);
    const [removing, setRemoving] = useState<ExperienceLine | null>(null);
    const selected = popup.openId !== null && experienceSelected?.employee.id === popup.openId ? experienceSelected : null;
    const apply = () => router.get(route('work-experience.records.index'),
        { ...(draft.search.trim() === '' ? {} : { search: draft.search.trim() }), ...(draft.service === '' ? {} : { service: draft.service }) }, { preserveState: true });
    const closePopup = () => {
        setEditing(null);
        popup.close();
    };
    const formKey = editing === null ? 'none' : `${editing.mode}:${editing.mode === 'new' ? '' : editing.line.key}`;

    return (
        <AuthenticatedLayout header={<PageHeader title={wt('records.title')} description={wt('records.description')} />}>
            <Head title={wt('records.title')} />
            <div className="space-y-4 pb-4">
                <FilterPanel onSubmit={apply} onReset={() => router.get(route('work-experience.records.index'))} hasActiveFilters={filters.search !== '' || filters.service !== ''}>
                    <FilterField label={wt('records.search')} wide><input type="search" className={inputCls} value={draft.search} onChange={(e) => setDraft({ ...draft, search: e.target.value })} /></FilterField>
                    <FilterField label={wt('records.service')}>
                        <select className={inputCls} value={draft.service} onChange={(e) => setDraft({ ...draft, service: e.target.value })}>
                            <option value="">{wt('common.all')}</option>
                            <option value="in">{wt('records.inService')}</option>
                            <option value="left">{wt('records.left')}</option>
                        </select>
                    </FilterField>
                </FilterPanel>

                <TableCard>
                    <div className="border-b border-(--app-border) px-4 py-3">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{wt('records.tableTitle')}</h2>
                        <p className={cx(mutedCls, 'mt-0.5 text-xs')}>{wt('records.tableDescription')}</p>
                    </div>
                    {experienceEmployees.data.length === 0 ? <EmptyState title={wt('records.emptyTitle')} description={wt('records.emptyDescription')} /> : (
                        <TableTools id="work-experience-records"><Table>
                            <THead>
                                <Tr>
                                    <Th>{wt('records.employee')}</Th><Th>{wt('lines.position')}</Th><Th>{wt('records.serviceStart')}</Th>
                                    <Th>{wt('records.fromSystem')}</Th><Th>{wt('records.recorded')}</Th><Th>{wt('records.service')}</Th><Th className="text-end">{wt('common.actions')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {experienceEmployees.data.map((employee) => (
                                    <Tr key={employee.id}>
                                        <Td>
                                            <button type="button" className="text-start font-medium text-primary hover:underline" onClick={() => popup.open(employee.id)}>{name(employee)}</button>
                                            <div className={cx(mutedCls, 'text-xs')}>{employee.employee_number}</div>
                                        </Td>
                                        <Td>
                                            <div>{employee.position ? name(employee.position) : '—'}</div>
                                            {employee.unit && <div className={cx(mutedCls, 'text-xs')}>{name(employee.unit)}</div>}
                                        </Td>
                                        <Td className="whitespace-nowrap">{employee.service_start_date ? <LocalizedDateDisplay value={employee.service_start_date} /> : '—'}</Td>
                                        <Td>{employee.assignments_count}</Td>
                                        <Td>{employee.records_count}</Td>
                                        <Td><span className={badgeClassName(employee.has_left ? 'neutral' : 'success')}>{wt(employee.has_left ? 'records.left' : 'records.inService')}</span></Td>
                                        <Td className="whitespace-nowrap text-end">
                                            <div className="flex justify-end">
                                                <AppActionMenu items={[
                                                    { label: wt(canRecordAny ? 'records.openRecord' : 'records.viewRecord'), onClick: () => popup.open(employee.id), icon: canRecordAny ? <PencilIcon className="h-4 w-4" /> : <EyeIcon className="h-4 w-4" /> },
                                                ]} />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table></TableTools>
                    )}
                </TableCard>
                <ListPagination meta={experienceEmployees} />
            </div>

            <ExperiencePopup show={popup.openId !== null} onClose={closePopup} title={wt('records.popupTitle')} subtitle={selected ? `${name(selected.employee)} · ${selected.employee.employee_number ?? ''}` : null}>
                {selected === null ? <p className={mutedCls} role="status">{wt('common.loading')}</p> : (
                    <>
                        <ActionErrors except={FIELDS} />
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className={cx(mutedCls, 'max-w-3xl')}>{wt(selected.can_record ? 'records.popupHelp' : 'records.popupReadOnly')}</p>
                            {selected.can_record && editing === null && (
                                <button type="button" className={cx(primaryBtn, 'gap-2')} onClick={() => setEditing({ mode: 'new' })}>
                                    <Plus className="h-4 w-4" aria-hidden />{wt('records.add')}
                                </button>
                            )}
                        </div>
                        {editing !== null && <RecordForm key={formKey} employee={selected.employee} editing={editing} today={today} onDone={() => setEditing(null)} />}
                        <TableCard>
                            <LinesTable lines={selected.lines} total={selected.total} toolsId="work-experience-record-lines" actions={(line) => (
                                <AppActionMenu items={[
                                    { label: wt('records.addDetails'), onClick: () => setEditing({ mode: 'details', line }), show: selected.can_record && line.source === 'assignment' && !line.record_id, icon: <Plus className="h-4 w-4" /> },
                                    { label: wt('records.edit'), onClick: () => setEditing({ mode: 'edit', line }), show: selected.can_record && Boolean(line.record_id), icon: <PencilIcon className="h-4 w-4" /> },
                                    { label: wt('records.evidence'), onClick: () => line.record_id && window.open(route('work-experience.records.evidence', line.record_id), '_blank', 'noopener'), show: Boolean(line.record_id && line.evidence_name), icon: <DownloadIcon className="h-4 w-4" /> },
                                    { label: wt(line.source === 'assignment' ? 'records.removeDetails' : 'records.remove'), onClick: () => setRemoving(line), show: selected.can_record && Boolean(line.record_id), variant: 'danger', icon: <TrashIcon className="h-4 w-4" /> },
                                ]} />
                            )} />
                        </TableCard>
                    </>
                )}
            </ExperiencePopup>

            <ConfirmationDialog open={removing !== null} onClose={() => setRemoving(null)} variant="danger"
                title={wt('records.removeTitle')} description={wt(removing?.source === 'assignment' ? 'records.removeDetailsDescription' : 'records.removeDescription')}
                confirmLabel={wt('records.remove')} cancelLabel={wt('common.keep')}
                onConfirm={() => removing?.record_id && router.delete(route('work-experience.records.destroy', removing.record_id), { preserveScroll: true, onFinish: () => { setRemoving(null); setEditing(null); } })} />
        </AuthenticatedLayout>
    );
}
