import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import ConfirmationDialog from '@/Components/ConfirmationDialog';
import { DownloadIcon } from '@/Components/Icons';
import { cx } from '@/Components/ui';
import { Notice, Period, StatusBadge } from '@/modules/leave/components/Common';
import { dangerBtn, formatBytes, formatDays, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, textareaCls, useLeave } from '@/modules/leave/lib/helpers';
import type { LeaveDetail } from '@/modules/leave/types';
import { router } from '@inertiajs/react';
import { useState, type JSX, type ReactNode } from 'react';

function Field({ label, children }: { label: string; children: ReactNode }): JSX.Element {
    return (
        <div>
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 text-sm text-(--app-foreground)">{children}</dd>
        </div>
    );
}

/** Approve or refuse, with a comment. A refusal must say why; the server checks it too. */
function DecisionPanel({ title, help, approveLabel, routeName, id }: { title: string; help: string; approveLabel: string; routeName: string; id: string }): JSX.Element {
    const { lt } = useLeave();
    const [comment, setComment] = useState('');
    const [busy, setBusy] = useState(false);
    const send = (decision: 'approve' | 'reject') => router.post(route(routeName, id), { decision, comment }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <section className={cx(panelCls, 'space-y-3 border-primary/40 p-5')}>
            <h2 className={sectionTitleCls}>{title}</h2>
            <p className={mutedCls}>{help}</p>
            <div>
                <label htmlFor={`lv-comment-${routeName}`} className={labelCls}>{lt('show.comment')}</label>
                <textarea id={`lv-comment-${routeName}`} rows={2} maxLength={1000} className={textareaCls} value={comment} onChange={(e) => setComment(e.target.value)} />
                <p className={cx(mutedCls, 'mt-1 text-xs')}>{lt('show.commentHelp')}</p>
            </div>
            <div className="flex flex-wrap gap-3">
                <button type="button" className={primaryBtn} disabled={busy} onClick={() => send('approve')}>{approveLabel}</button>
                <button type="button" className={dangerBtn} disabled={busy || comment.trim() === ''} onClick={() => send('reject')}>{lt('show.refuse')}</button>
            </div>
        </section>
    );
}

/**
 * One leave request: what was asked, where it stands, its evidence and
 * history, and what the reader may do. The same body serves the request's
 * own page and the popup every list opens. Each action posts and comes back
 * to wherever it was opened, so the popup shows the result at once.
 */
export function RequestDetail({ leave, staff, balance, today }: {
    leave: LeaveDetail;
    /** Read as supervisor or officer (shows the balance), not as the employee. */
    staff: boolean;
    balance?: { available: number; pending: number; free: number } | null;
    today: string;
}): JSX.Element {
    const { lt, name, dayCount } = useLeave();
    const [cancelling, setCancelling] = useState(false);
    const [lastDay, setLastDay] = useState('');
    const [endReason, setEndReason] = useState('');
    const [deliveredOn, setDeliveredOn] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const unit = leave.leave_type?.day_unit;
    const post = (routeName: string, data: Record<string, unknown>, options: { forceFormData?: boolean; onSuccess?: () => void } = {}) =>
        router.post(route(routeName, leave.id), data as never, { preserveScroll: true, ...options });

    return (
        <>
            <div className="space-y-5">
                <section className={cx(panelCls, 'p-5')}>
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <StatusBadge status={leave.status} />
                            <span className={cx(mutedCls, 'text-xs')}>{lt(`show.next.${leave.status}`)}</span>
                        </div>
                        {leave.can.cancel && <button type="button" className={dangerBtn} onClick={() => setCancelling(true)}>{lt('show.cancel')}</button>}
                    </div>
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Field label={lt('table.employee')}>{name(leave.employee)}<span className={cx(mutedCls, 'block text-xs')}>{leave.employee?.employee_number}</span></Field>
                        <Field label={lt('show.workUnit')}>{name(leave.unit)}<span className={cx(mutedCls, 'block text-xs')}>{name(leave.organization)}</span></Field>
                        <Field label={lt('table.type')}>{name(leave.leave_type)}</Field>
                        <Field label={lt('table.period')}><Period row={leave} /></Field>
                        <Field label={lt('table.days')}>{dayCount(leave.days_counted, unit)}</Field>
                        <Field label={lt('show.budgetYear')}>{leave.budget_year}</Field>
                        {leave.pay_breakdown && <Field label={lt('show.pay')}>{lt('show.payBreakdown', { full: formatDays(leave.pay_breakdown.full), half: formatDays(leave.pay_breakdown.half) })}</Field>}
                        {leave.expected_delivery_on && <Field label={lt('form.expectedDelivery')}><LocalizedDateDisplay value={leave.expected_delivery_on} /></Field>}
                        {leave.actual_delivery_on && <Field label={lt('show.deliveredOn')}><LocalizedDateDisplay value={leave.actual_delivery_on} /></Field>}
                        {leave.ended_early_on && <Field label={lt('show.endedEarly')}><LocalizedDateDisplay value={leave.ended_early_on} /></Field>}
                        {leave.supervisor && <Field label={lt('show.supervisor')}>{leave.supervisor.name}</Field>}
                        {leave.contact_during_leave && <Field label={lt('form.contact')}>{leave.contact_during_leave}</Field>}
                        {leave.registered_by_hr && <Field label={lt('show.source')}>{lt('show.registeredByHr')}</Field>}
                    </dl>
                    <div className="mt-4 border-t border-(--app-border) pt-4">
                        <p className="text-xs font-medium text-(--app-muted-foreground)">{lt('form.reason')}</p>
                        <p className="mt-0.5 whitespace-pre-line text-sm text-(--app-foreground)">{leave.reason_hidden ? lt('show.reasonHidden') : (leave.reason || '—')}</p>
                    </div>
                    {balance && staff && (
                        <p className="mt-4 rounded-control border border-(--app-border) px-3 py-2 text-sm">
                            {lt('show.balance', { available: formatDays(balance.available), pending: formatDays(balance.pending), free: formatDays(balance.free) })}
                        </p>
                    )}
                </section>

                {leave.can.decide_as_supervisor && <DecisionPanel title={lt('show.supervisorTitle')} help={lt('show.supervisorHelp')} approveLabel={lt('show.approve')} routeName="leave.requests.supervisor-decision" id={leave.id} />}
                {leave.can.decide_as_hr && (
                    <>
                        {leave.needs_document && leave.documents.length === 0 && <Notice tone="warning">{lt('show.documentNeeded')}</Notice>}
                        <DecisionPanel title={lt('show.hrTitle')} help={lt('show.hrHelp')} approveLabel={lt('show.confirm')} routeName="leave.requests.hr-decision" id={leave.id} />
                    </>
                )}

                {leave.can.record_delivery && (
                    <section className={cx(panelCls, 'space-y-3 p-5')}>
                        <h2 className={sectionTitleCls}>{lt('show.deliveryTitle')}</h2>
                        <p className={mutedCls}>{lt('show.deliveryHelp')}</p>
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="min-w-56"><span className={labelCls}>{lt('show.deliveredOn')}</span><LocalizedDatePicker value={deliveredOn} min={leave.starts_on} max={today} onChange={setDeliveredOn} /></div>
                            <button type="button" className={primaryBtn} disabled={!deliveredOn} onClick={() => post('leave.requests.delivery', { delivered_on: deliveredOn })}>{lt('show.recordDelivery')}</button>
                        </div>
                    </section>
                )}

                {leave.can.end_early && (
                    <section className={cx(panelCls, 'space-y-3 p-5')}>
                        <h2 className={sectionTitleCls}>{lt('show.endEarlyTitle')}</h2>
                        <p className={mutedCls}>{lt('show.endEarlyHelp')}</p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div><span className={labelCls}>{lt('show.lastDay')}</span><LocalizedDatePicker value={lastDay} min={leave.starts_on} max={leave.ends_on} onChange={setLastDay} /></div>
                            <div>
                                <label htmlFor="lv-end-reason" className={labelCls}>{lt('form.reason')}</label>
                                <input id="lv-end-reason" type="text" maxLength={1000} className={inputCls} value={endReason} onChange={(e) => setEndReason(e.target.value)} />
                            </div>
                        </div>
                        <button type="button" className={dangerBtn} disabled={!lastDay || endReason.trim() === ''} onClick={() => post('leave.requests.end-early', { last_day_on: lastDay, reason: endReason })}>{lt('show.endEarly')}</button>
                    </section>
                )}

                <section className={cx(panelCls, 'space-y-3 p-5')}>
                    <h2 className={sectionTitleCls}>{lt('show.documents')}</h2>
                    {leave.documents_hidden && <p className={mutedCls}>{lt('show.documentsHidden')}</p>}
                    {!leave.documents_hidden && leave.documents.length === 0 && <p className={mutedCls}>{lt('show.noDocuments')}</p>}
                    <ul className="divide-y divide-(--app-border)">
                        {leave.documents.map((document) => (
                            <li key={document.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                <span className="min-w-0 truncate">{document.name} <span className={cx(mutedCls, 'text-xs')}>{formatBytes(document.size_bytes)}</span></span>
                                <a href={route('leave.documents.download', document.id)} className={cx(secondaryBtn, 'gap-2')}><DownloadIcon className="h-4 w-4" aria-hidden />{lt('show.download')}</a>
                            </li>
                        ))}
                    </ul>
                    {leave.can.add_document && (
                        <div className="flex flex-wrap items-end gap-3 border-t border-(--app-border) pt-3">
                            <div>
                                <label htmlFor="lv-add-docs" className={labelCls}>{lt('show.addDocument')}</label>
                                <input id="lv-add-docs" type="file" multiple accept=".pdf,.jpg,.jpeg,.png" className="block text-sm" onChange={(e) => setFiles(Array.from(e.target.files ?? []).slice(0, 5))} />
                            </div>
                            <button type="button" className={secondaryBtn} disabled={files.length === 0} onClick={() => post('leave.requests.documents.store', { documents: files }, { forceFormData: true, onSuccess: () => setFiles([]) })}>{lt('show.attach')}</button>
                        </div>
                    )}
                </section>

                <section className={cx(panelCls, 'space-y-3 p-5')}>
                    <h2 className={sectionTitleCls}>{lt('show.history')}</h2>
                    <ol className="space-y-3">
                        {leave.events.map((event) => (
                            <li key={event.id} className="border-s-2 border-(--app-border) ps-3">
                                <p className="text-sm font-medium text-(--app-foreground)">{lt(`events.${event.action}`)}</p>
                                <p className={cx(mutedCls, 'text-xs')}>{event.actor ?? '—'} · <LocalizedDateDisplay value={event.at} withTime /></p>
                                {event.comment && <p className="mt-1 whitespace-pre-line text-sm text-(--app-foreground)">{event.comment}</p>}
                            </li>
                        ))}
                    </ol>
                </section>
            </div>

            <ConfirmationDialog
                open={cancelling} onClose={() => setCancelling(false)}
                title={lt('show.cancelTitle')} description={lt(leave.status === 'approved' ? 'show.cancelApproved' : 'show.cancelPending')}
                confirmLabel={lt('show.cancel')} cancelLabel={lt('common.keep')} variant="danger" requireReason={!leave.is_owner} reasonLabel={lt('form.reason')}
                onConfirm={(reason) => post('leave.requests.cancel', { reason: reason ?? '' }, { onSuccess: () => setCancelling(false) })}
            />
        </>
    );
}
