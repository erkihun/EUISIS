import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import ConfirmationDialog from '@/Components/ConfirmationDialog';
import PageHeader from '@/Components/PageHeader';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import ActionErrors from '@/Components/letters/ActionErrors';
import { PriorityBadge, StatusBadge } from '@/Components/letters/LetterBadges';
import { dangerBtn, daysUntil, errorCls, formatBytes, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, textareaCls, useLetters } from '@/Components/letters/helpers';
import { Alert, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { LetterFull, NamedWithId, PersonOption, Registration, Routing, UnitOption } from '@/types/letters';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

type Lookup = { units: UnitOption[]; people: PersonOption[] };

interface Props {
    letter: LetterFull;
    routing_lookups: Record<string, Lookup>;
    dispatch_modes: string[];
    reply_organizations: NamedWithId[];
}

type Dialog =
    | { kind: 'submit' | 'approve' | 'cancel' | 'return' }
    | { kind: 'close'; registration: Registration }
    | { kind: 'complete' | 'returnRouting'; routing: Routing }
    | null;

/** One letter: its text, the registry entry of every institution handling it, routing, files and timeline, with the actions the viewer may take. */
export default function Show({ letter, routing_lookups, dispatch_modes, reply_organizations }: Props): JSX.Element {
    const { lt, name, pick } = useLetters();
    const [dialog, setDialog] = useState<Dialog>(null);
    const [dispatching, setDispatching] = useState(false);
    const [busy, setBusy] = useState(false);
    const [replyOrg, setReplyOrg] = useState(reply_organizations[0]?.id ?? '');
    const dispatchForm = useForm({ mode: letter.external_party_email ? 'email' : 'system', reference: '', email: letter.external_party_email ?? '', note: '' });
    const composed = letter.direction !== 'incoming';
    const subjectText = letter.subject ?? lt(`classification.${letter.classification}`);
    const title = letter.number ? `${letter.number} · ${subjectText}` : subjectText;

    function sendDispatch(event: FormEvent) {
        event.preventDefault();
        dispatchForm.transform((d) => ({ mode: d.mode, reference: d.reference || null, email: d.mode === 'email' ? d.email || null : null, note: d.note || null }));
        dispatchForm.post(route('letters.dispatch', letter.id), { preserveScroll: true, onSuccess: () => setDispatching(false) });
    }

    function post(url: string, data: Record<string, string | number | null | undefined> = {}) {
        setBusy(true);
        router.post(url, data, { preserveScroll: true, onFinish: () => { setBusy(false); setDialog(null); } });
    }

    function confirm(reason?: string) {
        if (!dialog) return;
        switch (dialog.kind) {
            case 'submit': return post(route('letters.submit', letter.id));
            case 'approve': return post(route('letters.approve', letter.id));
            case 'cancel': return post(route('letters.cancel', letter.id), { reason });
            case 'return': return post(route('letters.return', letter.id), { reason });
            case 'close': return post(route('letters.registrations.close', dialog.registration.id), { closing_note: reason ?? '' });
            case 'complete': return post(route('letters.routings.complete', dialog.routing.id), { outcome: reason });
            case 'returnRouting': return post(route('letters.routings.return', dialog.routing.id), { outcome: reason });
        }
    }

    const dialogText: Record<string, { title: string; description: string; confirm: string; reason: boolean; variant: 'default' | 'danger' | 'warning' | 'success' }> = {
        submit: { title: lt('show.submit'), description: lt('show.confirmSubmit'), confirm: lt('show.submit'), reason: false, variant: 'default' },
        approve: { title: lt('show.approve'), description: lt('show.confirmApprove'), confirm: lt('show.approve'), reason: false, variant: 'success' },
        cancel: { title: lt('show.cancel'), description: lt('show.confirmCancel'), confirm: lt('show.cancel'), reason: true, variant: 'danger' },
        return: { title: lt('show.return'), description: lt('show.confirmReturn'), confirm: lt('show.return'), reason: true, variant: 'warning' },
        close: { title: lt('show.close'), description: lt('show.confirmClose'), confirm: lt('show.close'), reason: false, variant: 'default' },
        complete: { title: lt('show.complete'), description: lt('show.confirmComplete'), confirm: lt('show.complete'), reason: true, variant: 'success' },
        returnRouting: { title: lt('show.returnRouting'), description: lt('show.confirmReturnRouting'), confirm: lt('show.returnRouting'), reason: true, variant: 'warning' },
    };
    const current = dialog ? dialogText[dialog.kind] : null;

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={`${lt(`direction.${letter.direction}`)} · ${name(letter.organization)}`} backHref={route('letters.index')}
            actions={(
                <div className="flex flex-wrap gap-2">
                    {letter.can.pdf && <a className={secondaryBtn} href={route('letters.pdf', letter.id)} target="_blank" rel="noopener">{lt('show.pdf')}</a>}
                    {letter.can.update && <Link className={secondaryBtn} href={route('letters.edit', letter.id)}>{lt('show.edit')}</Link>}
                    {letter.can.submit && <button type="button" className={primaryBtn} onClick={() => setDialog({ kind: 'submit' })}>{lt('show.submit')}</button>}
                    {letter.can.approve && <button type="button" className={primaryBtn} onClick={() => setDialog({ kind: 'approve' })}>{lt('show.approve')}</button>}
                    {letter.can.return && <button type="button" className={secondaryBtn} onClick={() => setDialog({ kind: 'return' })}>{lt('show.return')}</button>}
                    {letter.can.dispatch && <button type="button" className={primaryBtn} onClick={() => setDispatching((v) => !v)}>{lt('show.dispatch')}</button>}
                    {letter.can.cancel && <button type="button" className={dangerBtn} onClick={() => setDialog({ kind: 'cancel' })}>{lt('show.cancel')}</button>}
                </div>
            )} />}>
            <Head title={title} />
            <ActionErrors only={['subject', 'body', 'recipient', 'organization_id', 'reason', 'closing_note', 'outcome', 'mode', 'reference', 'acknowledged_on']} />
            {letter.contents_hidden && <Alert tone="warning" className="mb-4" title={lt(`classification.${letter.classification}`)}>{lt('show.contentsHidden')}</Alert>}
            {dispatching && letter.can.dispatch && (
                <form onSubmit={sendDispatch} className={cx(panelCls, 'mb-4 grid gap-3 p-4 sm:grid-cols-3')} aria-label={lt('show.dispatch')}>
                    <p className={cx(mutedCls, 'sm:col-span-3')}>{lt('show.confirmDispatch')}</p>
                    <div>
                        <label htmlFor="ld-mode" className={labelCls}>{lt('show.dispatchMode')}</label>
                        <select id="ld-mode" className={inputCls} value={dispatchForm.data.mode} onChange={(e) => dispatchForm.setData('mode', e.target.value)}>
                            {dispatch_modes.map((m) => <option key={m} value={m}>{lt(`dispatchMode.${m}`)}</option>)}
                        </select>
                    </div>
                    {dispatchForm.data.mode === 'email' ? (
                        <div>
                            <label htmlFor="ld-email" className={labelCls}>{lt('show.dispatchEmail')}</label>
                            <input id="ld-email" type="email" className={inputCls} value={dispatchForm.data.email} onChange={(e) => dispatchForm.setData('email', e.target.value)} maxLength={160} required />
                            <p className={cx(mutedCls, 'mt-1')}>{lt('show.dispatchEmailHelp')}</p>
                            {dispatchForm.errors.email && <p className={errorCls}>{dispatchForm.errors.email}</p>}
                        </div>
                    ) : (
                        <div>
                            <label htmlFor="ld-ref" className={labelCls}>{lt('show.dispatchReference')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                            <input id="ld-ref" className={inputCls} value={dispatchForm.data.reference} onChange={(e) => dispatchForm.setData('reference', e.target.value)} maxLength={120} placeholder={lt('show.dispatchReferencePlaceholder')} />
                            {dispatchForm.errors.reference && <p className={errorCls}>{dispatchForm.errors.reference}</p>}
                        </div>
                    )}
                    <div>
                        <label htmlFor="ld-note" className={labelCls}>{lt('show.dispatchNote')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <input id="ld-note" className={inputCls} value={dispatchForm.data.note} onChange={(e) => dispatchForm.setData('note', e.target.value)} maxLength={500} />
                    </div>
                    <div className="flex gap-2 sm:col-span-3">
                        <button type="submit" className={primaryBtn} disabled={dispatchForm.processing}>{lt('show.dispatch')}</button>
                        <button type="button" className={secondaryBtn} onClick={() => setDispatching(false)}>{lt('common.cancel')}</button>
                    </div>
                </form>
            )}
            <div className="grid gap-5 lg:grid-cols-3">
                <div className="space-y-5 lg:col-span-2">
                    <section className={cx(panelCls, 'p-4')} aria-labelledby="letter-details">
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            <h2 id="letter-details" className={sectionTitleCls}>{lt('show.details')}</h2>
                            <StatusBadge kind="status" value={letter.status} />
                            <PriorityBadge value={letter.priority} />
                            {letter.classification !== 'internal' && <StatusBadge kind="classification" value={letter.classification} />}
                        </div>
                        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <Row label={lt('common.number')} value={letter.number ?? lt('show.numberPending')} />
                            <Row label={lt('show.letterDate')} value={<LocalizedDateDisplay value={letter.letter_date} />} />
                            {letter.letterhead && <Row label={lt('show.layout')} value={letter.letterhead.name} />}
                            {letter.file_class && <Row label={lt('show.fileClass')} value={`${letter.file_class.code} · ${(letter.language === 'am' ? letter.file_class.title_am ?? letter.file_class.title_en : letter.file_class.title_en ?? letter.file_class.title_am) ?? ''}`} />}
                            {letter.file_code && <Row label={lt('common.fileCode')} value={letter.file_code} />}
                            {letter.external_party_email && <Row label={lt('show.externalEmail')} value={letter.external_party_email} />}
                            <Row label={lt('common.sender')} value={letter.direction === 'incoming' ? (letter.sender_organization ? pick(letter.sender_organization) : letter.external_party_name ?? '—') : pick(letter.organization)} />
                            <Row label={lt('common.recipient')} value={letter.direction === 'internal' ? (letter.unit ? pick(letter.unit) : lt('show.internalRecipient')) : letter.direction === 'incoming' ? pick(letter.organization) : (letter.recipient_organization ? pick(letter.recipient_organization) : letter.external_party_name ?? '—')} />
                            {letter.external_party_address && <Row label={lt('common.externalAddress')} value={letter.external_party_address} />}
                            {letter.external_reference && <Row label={lt('show.externalReference')} value={letter.external_reference} />}
                            {letter.unit && letter.direction !== 'internal' && <Row label={lt('common.unit')} value={pick(letter.unit)} />}
                            <Row label={lt('common.language')} value={lt(`language.${letter.language}`)} />
                            {letter.signatory && <Row label={lt('common.signatory')} value={letter.signatory.name} />}
                            {letter.approved_at && <Row label={lt('show.approvedBy')} value={<>{letter.approved_by} · <LocalizedDateDisplay value={letter.approved_at} withTime /></>} />}
                            {letter.dispatched_at && <Row label={lt('show.dispatchedAt')} value={<><LocalizedDateDisplay value={letter.dispatched_at} withTime />{letter.dispatch_mode && <> · {lt(`dispatchMode.${letter.dispatch_mode}`)}</>}{letter.dispatch_reference && <> · {letter.dispatch_reference}</>}</>} />}
                            {letter.dispatch_note && <Row label={lt('show.dispatchNote')} value={letter.dispatch_note} />}
                            {letter.verification_code && letter.verification_url && <Row label={lt('show.verification')} value={<><span className="font-mono">{letter.verification_code}</span> · <a className="underline" href={letter.verification_url} target="_blank" rel="noopener">{lt('show.verificationOpen')}</a></>} />}
                            {letter.copies.length > 0 && <Row label={lt('show.copies')} value={<ul className="list-disc ps-4">{letter.copies.map((c) => <li key={c.id ?? c.external_name ?? ''}>{c.organization ? name(c.organization) : c.external_name}{!c.organization && c.external_address ? ` · ${c.external_address}` : ''}</li>)}</ul>} />}
                            <Row label={lt('show.createdBy')} value={<>{letter.created_by ?? '—'} · <LocalizedDateDisplay value={letter.created_at} withTime /></>} />
                            {letter.template && <Row label={lt('common.template')} value={lt('show.templateUsed', { code: letter.template.code ?? '', version: letter.template.version_no })} />}
                            {letter.regarding && <Row label={lt('show.regarding')} value={`${letter.regarding.type} · ${letter.regarding.id ?? ''}`} />}
                            {letter.in_reply_to && <Row label={lt('show.inReplyTo')} value={<Link className="underline" href={route('letters.show', letter.in_reply_to.id)}>{letter.in_reply_to.subject}</Link>} />}
                            {letter.supersedes && <Row label={lt('show.supersedes')} value={<Link className="underline" href={route('letters.show', letter.supersedes.id)}>{letter.supersedes.subject}</Link>} />}
                        </dl>
                        {letter.summary && <p className="mt-3 whitespace-pre-line text-sm text-(--app-foreground)"><span className={labelCls}>{lt('show.summary')}</span>{letter.summary}</p>}
                    </section>

                    {composed && (
                        <section className={cx(panelCls, 'p-4')} aria-labelledby="letter-body">
                            <h2 id="letter-body" className={cx(sectionTitleCls, 'mb-2')}>{lt('show.body')}</h2>
                            {letter.status === 'draft' && <Alert tone="info" className="mb-3">{lt('show.draftNotice')}</Alert>}
                            {letter.status === 'dispatched' && <p className={cx(mutedCls, 'mb-3')}>{lt('show.immutable')}</p>}
                            <RichTextViewer html={letter.body} lang={letter.language} empty={<p className={mutedCls}>{lt('show.noBody')}</p>} className="text-sm leading-6" />
                        </section>
                    )}

                    {letter.registrations.map((registration) => (
                        <RegistrationPanel key={registration.id} registration={registration} lookup={routing_lookups[registration.organization.id]} busy={busy}
                            onClose={() => setDialog({ kind: 'close', registration })} onComplete={(r) => setDialog({ kind: 'complete', routing: r })} onReturn={(r) => setDialog({ kind: 'returnRouting', routing: r })} />
                    ))}
                    {letter.replies.length > 0 && !letter.can.reply && (
                        <section className={cx(panelCls, 'p-4 text-sm')} aria-label={lt('show.replies')}>
                            <p className={labelCls}>{lt('show.replies')}</p>
                            <ul>{letter.replies.map((r) => <li key={r.id}><Link className="underline" href={route('letters.show', r.id)}>{r.subject}</Link> · <StatusBadge kind="status" value={r.status} /></li>)}</ul>
                        </section>
                    )}

                    {letter.can.reply && reply_organizations.length > 0 && (
                        <section className={cx(panelCls, 'flex flex-wrap items-end gap-3 p-4')} aria-label={lt('show.reply')}>
                            {reply_organizations.length > 1 && (
                                <div className="min-w-60">
                                    <label htmlFor="letter-reply-org" className={labelCls}>{lt('show.replyAs')}</label>
                                    <select id="letter-reply-org" className={inputCls} value={replyOrg} onChange={(e) => setReplyOrg(e.target.value)}>
                                        {reply_organizations.map((o) => <option key={o.id} value={o.id}>{pick({ name_en: o.name_en ?? '', name_am: o.name_am, code: o.code })}</option>)}
                                    </select>
                                </div>
                            )}
                            <button type="button" className={primaryBtn} disabled={busy} onClick={() => post(route('letters.reply', letter.id), { organization_id: replyOrg })}>{lt('show.reply')}</button>
                            {letter.replies.length > 0 && (
                                <ul className="w-full text-sm">
                                    <li className={labelCls}>{lt('show.replies')}</li>
                                    {letter.replies.map((r) => <li key={r.id}><Link className="underline" href={route('letters.show', r.id)}>{r.subject}</Link> · <StatusBadge kind="status" value={r.status} /></li>)}
                                </ul>
                            )}
                        </section>
                    )}
                </div>

                <aside className="space-y-5">
                    <AttachmentsPanel letter={letter} />
                    <section className={cx(panelCls, 'p-4')} aria-labelledby="letter-timeline">
                        <h2 id="letter-timeline" className={cx(sectionTitleCls, 'mb-2')}>{lt('show.timelineTitle')}</h2>
                        <ol className="space-y-2 text-sm">
                            {letter.events.map((e) => (
                                <li key={e.id} className="border-s-2 border-(--app-border) ps-3">
                                    <p className="font-medium">{lt(`event.${e.type}`)}{typeof e.payload.number === 'string' && ` · ${e.payload.number}`}{typeof e.payload.step === 'number' && ` · ${lt('common.step')} ${e.payload.step}`}</p>
                                    {typeof e.payload.reason === 'string' && <p className={mutedCls}>{e.payload.reason}</p>}
                                    {typeof e.payload.outcome === 'string' && <p className={mutedCls}>{e.payload.outcome}</p>}
                                    <p className="text-xs text-(--app-muted-foreground)">{e.actor ?? '—'} · <LocalizedDateDisplay value={e.at} withTime /></p>
                                </li>
                            ))}
                        </ol>
                    </section>
                </aside>
            </div>

            {current && dialog && (
                <ConfirmationDialog open onClose={() => setDialog(null)} onConfirm={confirm} title={current.title} description={current.description}
                    confirmLabel={current.confirm} variant={current.variant} loading={busy} requireReason={current.reason}
                    reasonLabel={dialog.kind === 'complete' || dialog.kind === 'returnRouting' ? lt('common.outcome') : dialog.kind === 'close' ? lt('show.closingNote') : lt('common.reason')} />
            )}
        </AuthenticatedLayout>
    );
}

function Row({ label, value }: { label: string; value: JSX.Element | string }): JSX.Element {
    return (
        <div>
            <dt className={labelCls}>{label}</dt>
            <dd className="text-(--app-foreground)">{value}</dd>
        </div>
    );
}

/** One institution's registry entry: its number and status, receiving, routing steps, closing. */
function RegistrationPanel({ registration, lookup, busy, onClose, onComplete, onReturn }: { registration: Registration; lookup?: Lookup; busy: boolean; onClose: () => void; onComplete: (r: Routing) => void; onReturn: (r: Routing) => void }): JSX.Element {
    const { lt, pick } = useLetters();
    const [routing, setRouting] = useState(false);
    const [receiving, setReceiving] = useState(false);
    const [acknowledging, setAcknowledging] = useState(false);
    const today = new Date().toISOString().slice(0, 10);
    const routeForm = useForm({ to_user_id: '', to_unit_id: '', instruction: '', due_on: '' });
    const receiveForm = useForm({ received_on: today, due_on: '', physical_location: '' });
    const ackForm = useForm({ acknowledged_on: today, acknowledgement_note: '' });

    function acknowledge(event: FormEvent) {
        event.preventDefault();
        ackForm.transform((d) => ({ acknowledged_on: d.acknowledged_on || null, acknowledgement_note: d.acknowledgement_note || null }));
        ackForm.post(route('letters.registrations.acknowledge', registration.id), { preserveScroll: true, onSuccess: () => setAcknowledging(false) });
    }

    function sendRouting(event: FormEvent) {
        event.preventDefault();
        routeForm.transform((d) => ({ to_user_id: d.to_user_id ? Number(d.to_user_id) : null, to_unit_id: d.to_unit_id || null, instruction: d.instruction, due_on: d.due_on || null }));
        routeForm.post(route('letters.routings.store', registration.id), { preserveScroll: true, onSuccess: () => { routeForm.reset(); setRouting(false); } });
    }

    function receive(event: FormEvent) {
        event.preventDefault();
        receiveForm.transform((d) => ({ received_on: d.received_on || null, due_on: d.due_on || null, physical_location: d.physical_location || null }));
        receiveForm.post(route('letters.registrations.receive', registration.id), { preserveScroll: true, onSuccess: () => setReceiving(false) });
    }

    return (
        <section className={cx(panelCls, 'p-4')} aria-label={`${lt('show.registrationsTitle')} · ${pick({ name_en: registration.organization.name_en ?? '', name_am: registration.organization.name_am, code: registration.organization.code })}`}>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className={sectionTitleCls}>{pick({ name_en: registration.organization.name_en ?? '', name_am: registration.organization.name_am, code: registration.organization.code })}</h2>
                    <p className={mutedCls}>
                        <StatusBadge kind="direction" value={registration.direction} /> <StatusBadge kind="registrationStatus" value={registration.status} className="ms-1" />
                        {registration.number && <span className="ms-2 font-medium text-(--app-foreground)">{registration.number}</span>}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {registration.can.receive && <button type="button" className={primaryBtn} onClick={() => setReceiving((v) => !v)}>{lt('show.receive')}</button>}
                    {registration.can.route && <button type="button" className={secondaryBtn} onClick={() => setRouting((v) => !v)}>{lt('show.route')}</button>}
                    {registration.can.acknowledge && <button type="button" className={secondaryBtn} onClick={() => setAcknowledging((v) => !v)}>{lt('show.acknowledgeReceipt')}</button>}
                    {registration.can.close && <button type="button" className={secondaryBtn} disabled={busy} onClick={onClose}>{lt('show.close')}</button>}
                </div>
            </div>
            <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
                {registration.received_on && <Row label={lt('show.receivedOn')} value={<LocalizedDateDisplay value={registration.received_on} />} />}
                {registration.physical_location && <Row label={lt('common.physicalLocation')} value={registration.physical_location} />}
                {registration.acknowledged_at && <Row label={lt('show.acknowledgedAt')} value={<><LocalizedDateDisplay value={registration.acknowledged_at} />{registration.acknowledgement_note && <span className={mutedCls}> · {registration.acknowledgement_note}</span>}</>} />}
                {registration.escalated_at && <Row label={lt('show.escalatedAt')} value={<span className="font-semibold text-red-700 dark:text-red-400"><LocalizedDateDisplay value={registration.escalated_at} /></span>} />}
                {registration.due_on && <Row label={lt('common.dueOn')} value={<span className={cx(registration.status !== 'closed' && daysUntil(registration.due_on) < 0 && 'font-semibold text-red-700 dark:text-red-400')}><LocalizedDateDisplay value={registration.due_on} /></span>} />}
                {registration.closed_at && <Row label={lt('registrationStatus.closed')} value={<><LocalizedDateDisplay value={registration.closed_at} withTime />{registration.closing_note && <span className={mutedCls}> · {registration.closing_note}</span>}</>} />}
            </dl>

            {receiving && (
                <form onSubmit={receive} className="mt-3 grid gap-3 border-t border-(--app-border) pt-3 sm:grid-cols-3" aria-label={lt('show.receive')}>
                    <div>
                        <label htmlFor={`rcv-on-${registration.id}`} className={labelCls}>{lt('show.receivedOn')}</label>
                        <LocalizedDatePicker id={`rcv-on-${registration.id}`} className={inputCls} value={receiveForm.data.received_on} onChange={(iso) => receiveForm.setData('received_on', iso)} />
                    </div>
                    <div>
                        <label htmlFor={`rcv-due-${registration.id}`} className={labelCls}>{lt('common.dueOn')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <LocalizedDatePicker id={`rcv-due-${registration.id}`} className={inputCls} value={receiveForm.data.due_on} onChange={(iso) => receiveForm.setData('due_on', iso)} />
                    </div>
                    <div>
                        <label htmlFor={`rcv-loc-${registration.id}`} className={labelCls}>{lt('common.physicalLocation')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <input id={`rcv-loc-${registration.id}`} className={inputCls} value={receiveForm.data.physical_location} onChange={(e) => receiveForm.setData('physical_location', e.target.value)} maxLength={120} placeholder={lt('register.physicalLocationPlaceholder')} />
                    </div>
                    <div className="flex items-end gap-2 sm:col-span-3">
                        <button type="submit" className={primaryBtn} disabled={receiveForm.processing}>{lt('show.confirmReceive')}</button>
                        <button type="button" className={secondaryBtn} onClick={() => setReceiving(false)}>{lt('common.cancel')}</button>
                    </div>
                </form>
            )}

            {acknowledging && (
                <form onSubmit={acknowledge} className="mt-3 grid gap-3 border-t border-(--app-border) pt-3 sm:grid-cols-3" aria-label={lt('show.acknowledgeReceipt')}>
                    <p className={cx(mutedCls, 'sm:col-span-3')}>{lt('show.acknowledgeHelp')}</p>
                    <div>
                        <label htmlFor={`ack-on-${registration.id}`} className={labelCls}>{lt('show.acknowledgedAt')}</label>
                        <LocalizedDatePicker id={`ack-on-${registration.id}`} className={inputCls} value={ackForm.data.acknowledged_on} onChange={(iso) => ackForm.setData('acknowledged_on', iso)} />
                    </div>
                    <div>
                        <label htmlFor={`ack-note-${registration.id}`} className={labelCls}>{lt('show.acknowledgementNote')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <input id={`ack-note-${registration.id}`} className={inputCls} value={ackForm.data.acknowledgement_note} onChange={(e) => ackForm.setData('acknowledgement_note', e.target.value)} maxLength={500} placeholder={lt('show.acknowledgementPlaceholder')} />
                    </div>
                    <div className="flex items-end gap-2">
                        <button type="submit" className={primaryBtn} disabled={ackForm.processing}>{lt('show.acknowledgeReceipt')}</button>
                        <button type="button" className={secondaryBtn} onClick={() => setAcknowledging(false)}>{lt('common.cancel')}</button>
                    </div>
                </form>
            )}

            {routing && lookup && (
                <form onSubmit={sendRouting} className="mt-3 grid gap-3 border-t border-(--app-border) pt-3 sm:grid-cols-2" aria-label={lt('show.route')}>
                    <p className={cx(mutedCls, 'sm:col-span-2')}>{lt('show.routeHelp')}</p>
                    <div>
                        <label htmlFor={`rt-unit-${registration.id}`} className={labelCls}>{lt('show.routeToUnit')}</label>
                        <select id={`rt-unit-${registration.id}`} className={inputCls} value={routeForm.data.to_unit_id} onChange={(e) => routeForm.setData('to_unit_id', e.target.value)}>
                            <option value="">—</option>
                            {lookup.units.map((u) => <option key={u.id} value={u.id}>{pick(u)}</option>)}
                        </select>
                        {routeForm.errors.to_unit_id && <p className={errorCls}>{routeForm.errors.to_unit_id}</p>}
                    </div>
                    <div>
                        <label htmlFor={`rt-user-${registration.id}`} className={labelCls}>{lt('show.routeToPerson')}</label>
                        <select id={`rt-user-${registration.id}`} className={inputCls} value={routeForm.data.to_user_id} onChange={(e) => routeForm.setData('to_user_id', e.target.value)}>
                            <option value="">—</option>
                            {lookup.people.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </select>
                        {routeForm.errors.to_user_id && <p className={errorCls}>{routeForm.errors.to_user_id}</p>}
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor={`rt-instr-${registration.id}`} className={labelCls}>{lt('show.instruction')}</label>
                        <textarea id={`rt-instr-${registration.id}`} rows={2} className={textareaCls} value={routeForm.data.instruction} onChange={(e) => routeForm.setData('instruction', e.target.value)} required maxLength={2000} />
                        {routeForm.errors.instruction && <p className={errorCls}>{routeForm.errors.instruction}</p>}
                    </div>
                    <div>
                        <label htmlFor={`rt-due-${registration.id}`} className={labelCls}>{lt('common.dueOn')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <LocalizedDatePicker id={`rt-due-${registration.id}`} className={inputCls} value={routeForm.data.due_on} onChange={(iso) => routeForm.setData('due_on', iso)} />
                    </div>
                    <div className="flex items-end gap-2">
                        <button type="submit" className={primaryBtn} disabled={routeForm.processing}>{lt('show.route')}</button>
                        <button type="button" className={secondaryBtn} onClick={() => setRouting(false)}>{lt('common.cancel')}</button>
                    </div>
                </form>
            )}

            <div className="mt-3 border-t border-(--app-border) pt-3">
                <h3 className="mb-1 text-sm font-semibold">{lt('show.routingTitle')}</h3>
                {registration.routings.length === 0 ? <p className={mutedCls}>{lt('show.noRouting')}</p> : (
                    <ol className="space-y-2">
                        {registration.routings.map((r) => {
                            const overdue = r.due_on !== null && (r.status === 'pending' || r.status === 'acknowledged') && daysUntil(r.due_on) < 0;

                            return (
                                <li key={r.id} className="rounded-control border border-(--app-border) p-3 text-sm">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="font-medium">{lt('common.step')} {r.step} → {r.to_user ? r.to_user.name : r.to_unit ? pick({ name_en: r.to_unit.name_en ?? '', name_am: r.to_unit.name_am, code: r.to_unit.code }) : '—'}</p>
                                        <StatusBadge kind="routingStatus" value={r.status} />
                                    </div>
                                    <p className="mt-1 whitespace-pre-line">{r.instruction}</p>
                                    <p className="mt-1 text-xs text-(--app-muted-foreground)">
                                        {r.from ?? '—'}{r.due_on && <> · {lt('common.dueOn')}: <span className={cx(overdue && 'font-semibold text-red-700 dark:text-red-400')}><LocalizedDateDisplay value={r.due_on} /></span></>}
                                        {r.acknowledged_at && <> · {lt('routingStatus.acknowledged')}: <LocalizedDateDisplay value={r.acknowledged_at} withTime /></>}
                                        {r.completed_at && <> · <LocalizedDateDisplay value={r.completed_at} withTime /></>}
                                    </p>
                                    {r.outcome && <p className="mt-1 whitespace-pre-line text-(--app-foreground)"><span className={labelCls}>{lt('common.outcome')}</span>{r.outcome}</p>}
                                    {r.can_act && (
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {r.status === 'pending' && <button type="button" className={secondaryBtn} disabled={busy} onClick={() => router.post(route('letters.routings.acknowledge', r.id), {}, { preserveScroll: true })}>{lt('show.acknowledge')}</button>}
                                            <button type="button" className={primaryBtn} disabled={busy} onClick={() => onComplete(r)}>{lt('show.complete')}</button>
                                            <button type="button" className={secondaryBtn} disabled={busy} onClick={() => onReturn(r)}>{lt('show.returnRouting')}</button>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                )}
            </div>
        </section>
    );
}

function AttachmentsPanel({ letter }: { letter: LetterFull }): JSX.Element {
    const { lt } = useLetters();
    const form = useForm({ file: null as File | null, kind: 'annex' });

    function upload(event: FormEvent) {
        event.preventDefault();
        form.post(route('letters.attachments.store', letter.id), { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
    }

    return (
        <section className={cx(panelCls, 'p-4')} aria-labelledby="letter-files">
            <h2 id="letter-files" className={cx(sectionTitleCls, 'mb-2')}>{lt('show.attachmentsTitle')}</h2>
            {letter.attachments.length === 0 ? <p className={mutedCls}>{lt('show.noAttachments')}</p> : (
                <ul className="space-y-1 text-sm">
                    {letter.attachments.map((a) => (
                        <li key={a.id} className="flex flex-wrap items-center justify-between gap-2">
                            <a className="min-w-0 truncate underline" href={route('letters.attachments.download', a.id)}>{a.name}</a>
                            <span className="text-xs text-(--app-muted-foreground)">{lt(`show.kind.${a.kind}`)} · {formatBytes(a.size)}</span>
                        </li>
                    ))}
                </ul>
            )}
            {letter.can.add_attachment && (
                <form onSubmit={upload} className="mt-3 space-y-2 border-t border-(--app-border) pt-3" aria-label={lt('show.addFile')}>
                    <div>
                        <label htmlFor="letter-file" className={labelCls}>{lt('show.addFile')}</label>
                        <input id="letter-file" type="file" accept="application/pdf,image/jpeg,image/png" className="block w-full text-sm" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                        {form.errors.file && <p className={errorCls}>{form.errors.file}</p>}
                    </div>
                    <div className="flex items-end gap-2">
                        <div className="grow">
                            <label htmlFor="letter-file-kind" className={labelCls}>{lt('show.fileKind')}</label>
                            <select id="letter-file-kind" className={inputCls} value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                                <option value="annex">{lt('show.kind.annex')}</option>
                                <option value="scan">{lt('show.kind.scan')}</option>
                            </select>
                        </div>
                        <button type="submit" className={secondaryBtn} disabled={form.processing || !form.data.file}>{lt('common.add')}</button>
                    </div>
                </form>
            )}
        </section>
    );
}
