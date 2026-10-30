import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { errorCls, inputCls, labelCls, panelCls, primaryBtn, scrollCls, secondaryBtn, tableCls, tdCls, textareaCls, thCls, useSm } from '@/Components/serviceMonitoring/helpers';
import ReasonDialog from '@/Components/serviceMonitoring/ReasonDialog';
import SmStatusBadge from '@/Components/serviceMonitoring/SmStatusBadge';
import { useConfirm } from '@/hooks/useConfirm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Compliance, ComplianceStatus, ComplianceTally, Named, Period, ReportingLevel } from '@/types/serviceMonitoring';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

type Row = {
    participant_id: string;
    level: ReportingLevel;
    organization: Named & { id: string };
    parent: Named | null;
    compliance: Compliance;
    certified_at: string | null;
    can_extend: boolean;
};
type Extension = { id: string; participant_id: string; due_at: string; reason: string; granted_by: string | null; revoked_at: string | null; active: boolean };

interface Props {
    period: Period & { ec_year: number };
    rows: Row[];
    extensions: Extension[];
    tally: ComplianceTally;
    can: { manage: boolean; lock: boolean; reopen: boolean };
}

const ORDER: ComplianceStatus[] = ['on_time', 'late_extension', 'in_review', 'returned', 'pending', 'missing', 'not_submitted'];

/** One period: schedule (coordinator), compliance per institution, extensions, lock and reopen. */
export default function PeriodShow({ period, rows, extensions, tally, can }: Props): JSX.Element {
    const { sm, name } = useSm();
    const { confirm } = useConfirm();
    const [reopening, setReopening] = useState(false);
    const [revoking, setRevoking] = useState<string | null>(null);
    const [extending, setExtending] = useState<Row | null>(null);
    const schedule = useForm({
        opens_on: period.opens_on, submission_due_on: period.submission_due_on,
        subcity_review_due_on: period.subcity_review_due_on, city_review_due_on: period.city_review_due_on,
    });
    const extension = useForm({ participant_id: '', due_at: '', reason: '' });

    function saveSchedule(event: FormEvent) {
        event.preventDefault();
        schedule.put(route('service-monitoring.periods.schedule', period.id), { preserveScroll: true });
    }

    function grant(event: FormEvent) {
        event.preventDefault();
        extension.post(route('service-monitoring.periods.extensions.store', period.id), { preserveScroll: true, onSuccess: () => { extension.reset(); setExtending(null); } });
    }

    async function lock() {
        const { confirmed } = await confirm({ title: sm('periods.lock'), description: sm('periods.lockConfirm'), confirmLabel: sm('periods.lock'), cancelLabel: sm('common.cancel'), variant: 'warning' });
        if (confirmed) router.post(route('service-monitoring.periods.lock', period.id), {}, { preserveScroll: true });
    }

    const nameOf = (id: string) => name(rows.find((r) => r.participant_id === id)?.organization);

    return (
        <AuthenticatedLayout header={<PageHeader title={period.label} description={sm('periods.description')} backHref={route('service-monitoring.periods.index')}
            actions={<div className="flex gap-2">
                {can.lock && <button type="button" className={primaryBtn} onClick={lock}>{sm('periods.lock')}</button>}
                {can.reopen && <button type="button" className={secondaryBtn} onClick={() => setReopening(true)}>{sm('periods.reopen')}</button>}
            </div>} />}>
            <Head title={period.label} />
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <SmStatusBadge kind="period" status={period.status} />
                    {period.locked_at && <span className="text-(--app-muted-foreground)">{sm('periods.locked', { date: '' })}<LocalizedDateDisplay value={period.locked_at} /></span>}
                    {period.reopened_reason && <span className="text-(--app-muted-foreground)">{sm('periods.reopen')}: {period.reopened_reason}</span>}
                </div>

                <form onSubmit={saveSchedule} className={`${panelCls} grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5`} aria-label={sm('periods.editSchedule')}>
                    {(['opens_on', 'submission_due_on', 'subcity_review_due_on', 'city_review_due_on'] as const).map((key) => (
                        <div key={key}>
                            <label htmlFor={`sm-${key}`} className={labelCls}>{sm(({ opens_on: 'periods.opens', submission_due_on: 'periods.submissionDue', subcity_review_due_on: 'periods.subcityReviewDue', city_review_due_on: 'periods.cityReviewDue' })[key])}</label>
                            <LocalizedDatePicker id={`sm-${key}`} className={inputCls} value={schedule.data[key]} disabled={!can.manage || period.status === 'locked'} onChange={(iso) => schedule.setData(key, iso)} />
                            {schedule.errors[key] && <p className={errorCls}>{schedule.errors[key]}</p>}
                        </div>
                    ))}
                    {can.manage && period.status !== 'locked' && (
                        <div className="flex items-end"><button type="submit" className={primaryBtn} disabled={schedule.processing || !schedule.isDirty}>{sm('common.save')}</button></div>
                    )}
                </form>

                <section className={`${panelCls} p-4`} aria-labelledby="sm-tally">
                    <h2 id="sm-tally" className="mb-2 text-sm font-semibold text-(--app-foreground)">{sm('periods.complianceTitle')}</h2>
                    <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4 lg:grid-cols-7">
                        {ORDER.map((s) => (
                            <div key={s}><dt className="text-xs text-(--app-muted-foreground)">{sm(`compliance.${s}`)}</dt><dd className="text-lg font-semibold tabular-nums">{tally[s]}</dd></div>
                        ))}
                    </dl>
                </section>

                <div className={scrollCls}>
                    <table className={tableCls}>
                        <thead>
                            <tr>{['common.institution', 'common.level', 'participants.parent', 'common.status', 'periods.certified', 'common.actions'].map((k) => <th key={k} scope="col" className={thCls}>{sm(k, { date: '' })}</th>)}</tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.participant_id}>
                                    <td className={tdCls}>
                                        {row.compliance.submission_id ? <Link className="underline" href={route('service-monitoring.submissions.show', row.compliance.submission_id)}>{name(row.organization)}</Link> : name(row.organization)}
                                    </td>
                                    <td className={tdCls}>{sm(`level.${row.level}`)}</td>
                                    <td className={tdCls}>{row.parent ? name(row.parent) : '—'}</td>
                                    <td className={tdCls}><SmStatusBadge kind="compliance" status={row.compliance.status} /></td>
                                    <td className={tdCls}>{row.certified_at ? <LocalizedDateDisplay value={row.certified_at} /> : '—'}</td>
                                    <td className={tdCls}>
                                        {row.can_extend && period.status !== 'locked' && (
                                            <button type="button" className={secondaryBtn} onClick={() => { extension.setData('participant_id', row.participant_id); setExtending(row); }}>{sm('periods.grantExtension')}</button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {extending && (
                    <form onSubmit={grant} className={`${panelCls} grid gap-3 p-4 sm:grid-cols-3`} aria-label={sm('periods.grantExtension')}>
                        <p className="text-sm font-semibold sm:col-span-3">{sm('periods.grantExtension')}: {name(extending.organization)}</p>
                        <div>
                            <label htmlFor="sm-ext-due" className={labelCls}>{sm('periods.extensionDue')}</label>
                            <input id="sm-ext-due" type="datetime-local" className={inputCls} value={extension.data.due_at} onChange={(e) => extension.setData('due_at', e.target.value)} required />
                            {extension.errors.due_at && <p className={errorCls}>{extension.errors.due_at}</p>}
                        </div>
                        <div className="sm:col-span-2">
                            <label htmlFor="sm-ext-reason" className={labelCls}>{sm('periods.extensionReason')}</label>
                            <textarea id="sm-ext-reason" rows={2} minLength={20} className={textareaCls} value={extension.data.reason} onChange={(e) => extension.setData('reason', e.target.value)} required />
                            {extension.errors.reason && <p className={errorCls}>{extension.errors.reason}</p>}
                            {extension.errors.participant_id && <p className={errorCls}>{extension.errors.participant_id}</p>}
                        </div>
                        <div className="flex gap-2 sm:col-span-3">
                            <button type="submit" className={primaryBtn} disabled={extension.processing}>{sm('common.save')}</button>
                            <button type="button" className={secondaryBtn} onClick={() => setExtending(null)}>{sm('common.cancel')}</button>
                        </div>
                    </form>
                )}

                <section className={`${panelCls} p-4`} aria-labelledby="sm-ext">
                    <h2 id="sm-ext" className="mb-2 text-sm font-semibold text-(--app-foreground)">{sm('periods.extensions')}</h2>
                    {extensions.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{sm('common.empty')}</p> : (
                        <ul className="divide-y divide-(--app-border) text-sm">
                            {extensions.map((e) => (
                                <li key={e.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span>{nameOf(e.participant_id)} · <LocalizedDateDisplay value={e.due_at} withTime /> · {e.reason}</span>
                                    <span className="flex items-center gap-2">
                                        <span className="text-xs text-(--app-muted-foreground)">{e.revoked_at ? sm('periods.revoked') : e.active ? sm('periods.active') : sm('periods.expired')}</span>
                                        {e.active && can.manage && <button type="button" className={secondaryBtn} onClick={() => setRevoking(e.id)}>{sm('periods.revoke')}</button>}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
            <ReasonDialog show={reopening} title={sm('periods.reopen')} label={sm('periods.reopenReason')} url={route('service-monitoring.periods.reopen', period.id)} onClose={() => setReopening(false)} />
            <ReasonDialog show={revoking !== null} title={sm('periods.revoke')} label={sm('periods.revokeReason')} url={revoking ? route('service-monitoring.extensions.revoke', revoking) : '#'} onClose={() => setRevoking(null)} />
        </AuthenticatedLayout>
    );
}
