import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ConfirmationDialog from '@/Components/ConfirmationDialog';
import EmptyState from '@/Components/EmptyState';
import { CheckCircle, ClockIcon, EyeIcon, PrinterIcon, RefreshIcon, SendIcon, SettingsIcon, UserPlusIcon, XCircle } from '@/Components/Icons';
import PageHeader from '@/Components/PageHeader';
import { AppActionMenu, AppMetricCard, FilterField, FilterPanel, ListPagination, Table, TableCard, TableTools, TBody, Td, Th, THead, Tr, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    ActionErrors, ExperiencePopup, LinesTable, Notice, StatusBadge, errorCls, inputCls, labelCls, mutedCls, primaryBtn, secondaryBtn, textareaCls, useExperience, usePopupRecord,
    type Duration, type EmployeeRef, type ExperienceLine, type Paginated, type RequestRow,
} from '@/modules/work-experience/lib';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

interface Detail extends RequestRow {
    verification_code: string | null;
    decided_by: string | null;
    sent_by: string | null;
    certificate: { lines: ExperienceLine[]; total: Duration; include_salary: boolean; signatory: { name_am: string | null; name_en: string | null; title_am: string | null; title_en: string | null } | null; issued_on: string | null } | null;
    problem: string | null;
    can: { decide: boolean; print: boolean; rewrite: boolean; send: boolean; record: boolean };
}

interface Props {
    experienceRequests: Paginated<RequestRow>;
    filters: { status: string; search: string };
    experienceSummary: { waiting: number; to_send: number; sent: number };
    experienceOpenRequest: Detail | null;
    experienceFormer: EmployeeRef[] | null;
    canManage: boolean;
    canSettings: boolean;
}

const STATUSES = ['submitted', 'accepted', 'returned', 'sent', 'cancelled'];
const REFRESH = ['experienceRequests', 'experienceSummary'];

/** The office enters a request for an employee who has left: look the person up, then fill the same short form. */
function RegisterForm({ matches, onDone }: { matches: EmployeeRef[] | null; onDone: () => void }): JSX.Element {
    const { wt, name } = useExperience();
    const [term, setTerm] = useState('');
    const [picked, setPicked] = useState<EmployeeRef | null>(null);
    const form = useForm({ employee_id: '', addressee: '', purpose: '', language: 'am' });
    const fieldError = (field: 'employee_id' | 'addressee' | 'purpose' | 'language') => (form.errors[field] ? <p className={errorCls}>{form.errors[field]}</p> : null);
    const search = () => router.reload({ data: { former: term.trim() }, only: ['experienceFormer'] });
    const pick = (employee: EmployeeRef) => {
        setPicked(employee);
        form.setData('employee_id', employee.id);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('work-experience.requests.store'), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <Notice tone="info">{wt('register.help')}</Notice>
            {picked === null ? (
                <div className="space-y-3">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="min-w-64 flex-1">
                            <label htmlFor="wx-former" className={labelCls}>{wt('register.search')}</label>
                            <input id="wx-former" type="search" className={inputCls} value={term} onChange={(e) => setTerm(e.target.value)}
                                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); if (term.trim() !== '') search(); } }} />
                        </div>
                        <button type="button" className={secondaryBtn} disabled={term.trim() === ''} onClick={search}>{wt('register.find')}</button>
                    </div>
                    {fieldError('employee_id')}
                    {matches !== null && matches.length === 0 && <p className={mutedCls}>{wt('register.none')}</p>}
                    {matches !== null && matches.length > 0 && (
                        <ul className="divide-y divide-(--app-border) rounded-panel border border-(--app-border)">
                            {matches.map((employee) => (
                                <li key={employee.id} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2 text-sm">
                                    <span><span className="font-medium text-(--app-foreground)">{name(employee)}</span> <span className={mutedCls}>· {employee.employee_number}</span></span>
                                    <button type="button" className={secondaryBtn} onClick={() => pick(employee)}>{wt('register.choose')}</button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            ) : (
                <>
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-(--app-border) px-3 py-2 text-sm">
                        <span><span className="font-medium text-(--app-foreground)">{name(picked)}</span> <span className={mutedCls}>· {picked.employee_number}</span></span>
                        <button type="button" className={secondaryBtn} onClick={() => { setPicked(null); form.setData('employee_id', ''); }}>{wt('register.change')}</button>
                    </div>
                    {fieldError('employee_id')}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="wx-r-addressee" className={labelCls}>{wt('form.addressee')}</label>
                            <input id="wx-r-addressee" type="text" maxLength={255} className={inputCls} placeholder={wt('form.toWhom')} value={form.data.addressee} onChange={(e) => form.setData('addressee', e.target.value)} />
                            {fieldError('addressee')}
                        </div>
                        <div>
                            <label htmlFor="wx-r-language" className={labelCls}>{wt('form.language')}<span className="text-red-600"> *</span></label>
                            <select id="wx-r-language" className={inputCls} value={form.data.language} onChange={(e) => form.setData('language', e.target.value)}>
                                <option value="am">{wt('form.amharic')}</option>
                                <option value="en">{wt('form.english')}</option>
                            </select>
                            {fieldError('language')}
                        </div>
                    </div>
                    <div>
                        <label htmlFor="wx-r-purpose" className={labelCls}>{wt('form.purpose')}<span className="text-red-600"> *</span></label>
                        <textarea id="wx-r-purpose" rows={3} maxLength={500} className={textareaCls} value={form.data.purpose} onChange={(e) => form.setData('purpose', e.target.value)} />
                        {fieldError('purpose')}
                    </div>
                </>
            )}
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" className={secondaryBtn} onClick={onDone}>{wt('common.close')}</button>
                <button type="submit" className={primaryBtn} disabled={form.processing || picked === null || form.data.purpose.trim() === ''}>{wt('register.save')}</button>
            </div>
        </form>
    );
}

/**
 * Employee management > Work experience requests. The office looks at each
 * request beside the recorded experience, accepts it or sends it back with
 * the reason, prints the certificate for signature and seal, and records that
 * it was sent. The employee is told at each step.
 */
export default function Requests({ experienceRequests, filters, experienceSummary, experienceOpenRequest, experienceFormer, canManage, canSettings }: Props): JSX.Element {
    const { wt, name, am } = useExperience();
    const [draft, setDraft] = useState({ status: filters.status, search: filters.search });
    const popup = usePopupRecord('request', 'experienceOpenRequest', REFRESH, experienceOpenRequest?.id ?? null);
    const [registering, setRegistering] = useState(false);
    const [returning, setReturning] = useState(false);
    const [sending, setSending] = useState(false);
    const [note, setNote] = useState('');
    const open = popup.openId !== null && experienceOpenRequest?.id === popup.openId ? experienceOpenRequest : null;
    const apply = () => router.get(route('work-experience.requests.index'),
        { ...(draft.status === '' ? {} : { status: draft.status }), ...(draft.search.trim() === '' ? {} : { search: draft.search.trim() }) }, { preserveState: true });
    const act = (routeName: string, data: Record<string, string> = {}, done?: () => void) =>
        open && router.post(route(routeName, open.id), data, { preserveScroll: true, onSuccess: done });
    const print = () => {
        if (!open) return;
        window.open(route('work-experience.requests.print', open.id), '_blank', 'noopener');
        // Opening the page counts as a printing; bring the count back so "sent" can be recorded.
        window.setTimeout(() => router.reload({ only: ['experienceOpenRequest'] }), 1500);
    };
    const signatory = open?.certificate?.signatory ?? null;
    const actions = (
        <>
            {canSettings && <Link href={route('work-experience.settings')} className={cx(secondaryBtn, 'gap-2')}><SettingsIcon className="h-4 w-4" aria-hidden />{wt('nav.settings')}</Link>}
            {canManage && <button type="button" className={cx(primaryBtn, 'gap-2')} onClick={() => setRegistering(true)}><UserPlusIcon className="h-4 w-4" aria-hidden />{wt('register.button')}</button>}
        </>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={wt('requests.title')} description={wt('requests.description')} actions={actions} />}>
            <Head title={wt('requests.title')} />
            <div className="space-y-4 pb-4">
                <section aria-label={wt('requests.title')} className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-3">
                    <AppMetricCard label={wt('requests.waiting')} value={experienceSummary.waiting} variant={experienceSummary.waiting > 0 ? 'warning' : 'success'} icon={<ClockIcon className="h-5 w-5" />} />
                    <AppMetricCard label={wt('requests.toSend')} value={experienceSummary.to_send} variant={experienceSummary.to_send > 0 ? 'primary' : 'success'} icon={<PrinterIcon className="h-5 w-5" />} />
                    <AppMetricCard label={wt('requests.sentCount')} value={experienceSummary.sent} variant="success" icon={<SendIcon className="h-5 w-5" />} />
                </section>

                <FilterPanel onSubmit={apply} onReset={() => router.get(route('work-experience.requests.index'))} hasActiveFilters={filters.status !== '' || filters.search !== ''}>
                    <FilterField label={wt('requests.search')} wide><input type="search" className={inputCls} value={draft.search} onChange={(e) => setDraft({ ...draft, search: e.target.value })} /></FilterField>
                    <FilterField label={wt('requests.status')}>
                        <select className={inputCls} value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                            <option value="">{wt('common.all')}</option>
                            {STATUSES.map((status) => <option key={status} value={status}>{wt(`status.${status}`)}</option>)}
                        </select>
                    </FilterField>
                </FilterPanel>

                <TableCard>
                    <div className="border-b border-(--app-border) px-4 py-3">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{wt('requests.tableTitle')}</h2>
                        <p className={cx(mutedCls, 'mt-0.5 text-xs')}>{wt('requests.tableDescription')}</p>
                    </div>
                    {experienceRequests.data.length === 0 ? <EmptyState title={wt(filters.status !== '' || filters.search !== '' ? 'requests.noMatches' : 'requests.emptyTitle')} description={filters.status !== '' || filters.search !== '' ? undefined : wt('requests.emptyDescription')} /> : (
                        <TableTools id="work-experience-requests"><Table>
                            <THead>
                                <Tr>
                                    <Th>{wt('requests.reference')}</Th><Th>{wt('records.employee')}</Th><Th>{wt('requests.askedOn')}</Th><Th>{wt('form.purpose')}</Th>
                                    <Th>{wt('requests.certificateNumber')}</Th><Th>{wt('requests.status')}</Th><Th className="text-end">{wt('common.actions')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {experienceRequests.data.map((row) => (
                                    <Tr key={row.id}>
                                        <Td><button type="button" className="font-medium text-primary hover:underline" onClick={() => popup.open(row.id)}>{row.reference_number}</button></Td>
                                        <Td>
                                            <div className="font-medium text-(--app-foreground)">{name(row.employee)}</div>
                                            <div className={cx(mutedCls, 'text-xs')}>{row.employee?.employee_number}{row.registered_by_office ? ` · ${wt('requests.byOffice')}` : ''}</div>
                                        </Td>
                                        <Td className="whitespace-nowrap">{row.submitted_at ? <LocalizedDateDisplay value={row.submitted_at} /> : '—'}</Td>
                                        <Td className="max-w-xs">{row.purpose}</Td>
                                        <Td className="whitespace-nowrap">{row.certificate_number ?? '—'}</Td>
                                        <Td><StatusBadge status={row.status} /></Td>
                                        <Td className="whitespace-nowrap text-end">
                                            <div className="flex justify-end">
                                                <AppActionMenu items={[{ label: wt('common.open'), onClick: () => popup.open(row.id), icon: <EyeIcon className="h-4 w-4" /> }]} />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table></TableTools>
                    )}
                </TableCard>
                <ListPagination meta={experienceRequests} />
            </div>

            <ExperiencePopup show={popup.openId !== null} onClose={() => { setSending(false); popup.close(); }} title={wt('requests.detailTitle', { reference: open?.reference_number ?? '' })}
                subtitle={open ? `${name(open.employee)} · ${open.employee?.employee_number ?? ''}` : null}>
                {open === null ? <p className={mutedCls} role="status">{wt('common.loading')}</p> : (
                    <>
                        <ActionErrors />
                        <div className="flex flex-wrap items-center gap-3">
                            <StatusBadge status={open.status} />
                            <span className={mutedCls}>{wt(`requests.step.${open.status}`)}</span>
                        </div>
                        {open.problem && <Notice tone="warning" role="alert">{open.problem}</Notice>}

                        <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div><dt className={labelCls}>{wt('form.addressee')}</dt><dd>{open.addressee ?? wt('form.toWhom')}</dd></div>
                            <div><dt className={labelCls}>{wt('form.language')}</dt><dd>{wt(open.language === 'en' ? 'form.english' : 'form.amharic')}</dd></div>
                            <div><dt className={labelCls}>{wt('requests.askedOn')}</dt><dd>{open.submitted_at ? <LocalizedDateDisplay value={open.submitted_at} /> : '—'}{open.registered_by_office ? ` · ${wt('requests.byOffice')}` : ''}</dd></div>
                            <div><dt className={labelCls}>{wt('requests.institution')}</dt><dd>{name(open.organization)}</dd></div>
                            <div className="sm:col-span-2 lg:col-span-4"><dt className={labelCls}>{wt('form.purpose')}</dt><dd>{open.purpose}</dd></div>
                            {open.decided_at && <div><dt className={labelCls}>{wt('requests.decidedOn')}</dt><dd><LocalizedDateDisplay value={open.decided_at} />{open.decided_by ? ` · ${open.decided_by}` : ''}</dd></div>}
                            {open.decision_comment && <div className="sm:col-span-2"><dt className={labelCls}>{wt('requests.reason')}</dt><dd>{open.decision_comment}</dd></div>}
                            {open.certificate_number && <div><dt className={labelCls}>{wt('requests.certificateNumber')}</dt><dd>{open.certificate_number}</dd></div>}
                            {open.verification_code && <div><dt className={labelCls}>{wt('requests.verificationCode')}</dt><dd className="font-mono tracking-wider">{open.verification_code}</dd></div>}
                            {open.certificate_number && <div><dt className={labelCls}>{wt('requests.printed')}</dt><dd>{open.print_count > 0 ? wt('requests.printedTimes', { n: open.print_count }) : wt('requests.notPrinted')}</dd></div>}
                            {open.sent_at && <div><dt className={labelCls}>{wt('requests.sentOn')}</dt><dd><LocalizedDateDisplay value={open.sent_at} />{open.sent_by ? ` · ${open.sent_by}` : ''}</dd></div>}
                            {open.delivery_note && <div className="sm:col-span-2"><dt className={labelCls}>{wt('requests.deliveryNote')}</dt><dd>{open.delivery_note}</dd></div>}
                        </dl>

                        {open.certificate && (
                            <TableCard>
                                <div className="border-b border-(--app-border) px-4 py-3">
                                    <h3 className="text-sm font-semibold text-(--app-foreground)">{wt(open.status === 'submitted' ? 'requests.previewTitle' : 'requests.certificateTitle')}</h3>
                                    <p className={cx(mutedCls, 'mt-0.5 text-xs')}>
                                        {wt(open.status === 'submitted' ? 'requests.previewHelp' : 'requests.certificateHelp')}
                                        {signatory && <> · {wt('requests.signedBy', { name: (am ? signatory.name_am || signatory.name_en : signatory.name_en || signatory.name_am) ?? '', title: (am ? signatory.title_am || signatory.title_en : signatory.title_en || signatory.title_am) ?? '' })}</>}
                                    </p>
                                </div>
                                <LinesTable lines={open.certificate.lines} total={open.certificate.total} toolsId="work-experience-certificate-lines" showSalary={open.certificate.include_salary} />
                            </TableCard>
                        )}

                        {sending && (
                            <div className="space-y-3 rounded-panel border border-(--app-border) p-4">
                                <label htmlFor="wx-delivery" className={labelCls}>{wt('requests.deliveryNote')}</label>
                                <input id="wx-delivery" type="text" maxLength={500} className={inputCls} placeholder={wt('requests.deliveryPlaceholder')} value={note} onChange={(e) => setNote(e.target.value)} />
                                <p className={cx(mutedCls, 'text-xs')}>{wt('requests.deliveryHelp')}</p>
                                <div className="flex flex-wrap justify-end gap-3">
                                    <button type="button" className={secondaryBtn} onClick={() => setSending(false)}>{wt('common.cancel')}</button>
                                    <button type="button" className={primaryBtn} onClick={() => act('work-experience.requests.sent', { delivery_note: note }, () => { setSending(false); setNote(''); })}>{wt('requests.confirmSent')}</button>
                                </div>
                            </div>
                        )}

                        <div className="flex flex-wrap justify-end gap-3">
                            {open.can.record && open.employee && <Link href={route('work-experience.records.index', { selected: open.employee.id })} className={secondaryBtn}>{wt('requests.openRecord')}</Link>}
                            {open.can.decide && <button type="button" className={cx(secondaryBtn, 'gap-2')} onClick={() => setReturning(true)}><XCircle className="h-4 w-4" aria-hidden />{wt('requests.sendBack')}</button>}
                            {open.can.decide && <button type="button" className={cx(primaryBtn, 'gap-2')} disabled={open.problem !== null} onClick={() => act('work-experience.requests.accept')}><CheckCircle className="h-4 w-4" aria-hidden />{wt('requests.accept')}</button>}
                            {open.can.rewrite && <button type="button" className={cx(secondaryBtn, 'gap-2')} onClick={() => act('work-experience.requests.rewrite')}><RefreshIcon className="h-4 w-4" aria-hidden />{wt('requests.rewrite')}</button>}
                            {open.can.print && <button type="button" className={cx(open.print_count > 0 ? secondaryBtn : primaryBtn, 'gap-2')} onClick={print}><PrinterIcon className="h-4 w-4" aria-hidden />{wt(open.print_count > 0 ? 'requests.printAgain' : 'requests.print')}</button>}
                            {open.can.send && !sending && <button type="button" className={cx(primaryBtn, 'gap-2')} onClick={() => setSending(true)}><SendIcon className="h-4 w-4" aria-hidden />{wt('requests.markSent')}</button>}
                        </div>
                    </>
                )}
            </ExperiencePopup>

            <ExperiencePopup show={registering} onClose={() => setRegistering(false)} title={wt('register.title')}>
                <ActionErrors except={['employee_id', 'addressee', 'purpose', 'language']} />
                {registering && <RegisterForm matches={experienceFormer} onDone={() => { setRegistering(false); router.reload({ data: { former: '' }, only: ['experienceFormer', ...REFRESH] }); }} />}
            </ExperiencePopup>

            <ConfirmationDialog open={returning} onClose={() => setReturning(false)} variant="danger" requireReason reasonLabel={wt('requests.reason')}
                title={wt('requests.sendBackTitle')} description={wt('requests.sendBackDescription')} confirmLabel={wt('requests.sendBack')} cancelLabel={wt('common.cancel')}
                onConfirm={(reason) => { act('work-experience.requests.return', { comment: reason ?? '' }); setReturning(false); }} />
        </AuthenticatedLayout>
    );
}
