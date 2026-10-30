import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import ActionItemCard from '@/Components/serviceMonitoring/ActionItemCard';
import { errorCls, inputCls, labelCls, panelCls, primaryBtn, secondaryBtn, useSm } from '@/Components/serviceMonitoring/helpers';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { ActionItemRow, FindingSeverity, Named, VisitStatus } from '@/types/serviceMonitoring';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';

interface Props {
    visit: {
        id: string; visited_on: string; purpose: string; summary: string | null; status: VisitStatus; period_id: string | null; period_label: string | null;
        organization: Named & { id: string | null }; members: { id: number; name: string }[];
        findings: { id: string; section: number | null; body: string; severity: FindingSeverity }[];
        action_items: ActionItemRow[];
    };
    severities: FindingSeverity[];
    team_candidates: { id: number; name: string }[];
    can: { update: boolean };
}

/** A visit: its summary and team, findings and action items (D22). */
export default function VisitShow({ visit, severities, team_candidates, can }: Props): JSX.Element {
    const { sm, name } = useSm();
    const details = useForm({ visited_on: visit.visited_on, purpose: visit.purpose, summary: visit.summary ?? '', status: visit.status, member_ids: visit.members.map((m) => m.id) });
    const finding = useForm({ section: '', body: '', severity: 'medium' as FindingSeverity });
    const item = useForm({ title: '', finding_id: '', owner_organization_id: visit.organization.id ?? '', owner_user_id: '', due_on: '' });

    function saveDetails(event: FormEvent) {
        event.preventDefault();
        details.put(route('service-monitoring.visits.update', visit.id), { preserveScroll: true });
    }

    function addFinding(event: FormEvent) {
        event.preventDefault();
        finding.transform((d) => ({ ...d, section: d.section === '' ? null : Number(d.section) }));
        finding.post(route('service-monitoring.visits.findings.store', visit.id), { preserveScroll: true, onSuccess: () => finding.reset() });
    }

    function addItem(event: FormEvent) {
        event.preventDefault();
        item.transform((d) => ({ ...d, finding_id: d.finding_id || null, owner_user_id: d.owner_user_id === '' ? null : Number(d.owner_user_id) }));
        item.post(route('service-monitoring.visits.action-items.store', visit.id), { preserveScroll: true, onSuccess: () => item.reset('title', 'due_on', 'finding_id') });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={`${name(visit.organization)} · ${sm('visits.title')}`} backHref={route('service-monitoring.visits.index')} />}>
            <Head title={sm('visits.title')} />
            <div className="space-y-4">
                <form onSubmit={saveDetails} className={`${panelCls} grid gap-3 p-4 sm:grid-cols-2`}>
                    <div>
                        <label htmlFor="sm-vd-date" className={labelCls}>{sm('visits.visitedOn')}</label>
                        <input id="sm-vd-date" type="date" className={inputCls} disabled={!can.update} value={details.data.visited_on} onChange={(e) => details.setData('visited_on', e.target.value)} />
                        <p className="mt-1 text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={details.data.visited_on} />{visit.period_label ? ` · ${visit.period_label}` : ''}</p>
                    </div>
                    <div>
                        <label htmlFor="sm-vd-status" className={labelCls}>{sm('common.status')}</label>
                        <select id="sm-vd-status" className={inputCls} disabled={!can.update} value={details.data.status} onChange={(e) => details.setData('status', e.target.value as VisitStatus)}>
                            {(['planned', 'completed', 'cancelled'] as const).map((s) => <option key={s} value={s}>{sm(`enums.visit_status.${s}`)}</option>)}
                        </select>
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor="sm-vd-purpose" className={labelCls}>{sm('visits.purpose')}</label>
                        <textarea id="sm-vd-purpose" rows={2} className={inputCls} disabled={!can.update} value={details.data.purpose} onChange={(e) => details.setData('purpose', e.target.value)} />
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor="sm-vd-summary" className={labelCls}>{sm('visits.summary')}</label>
                        <textarea id="sm-vd-summary" rows={4} className={inputCls} disabled={!can.update} value={details.data.summary} onChange={(e) => details.setData('summary', e.target.value)} />
                    </div>
                    <fieldset className="grid gap-1 sm:col-span-2 sm:grid-cols-3">
                        <legend className={labelCls}>{sm('visits.team')}</legend>
                        {(can.update ? team_candidates : visit.members).map((u) => (
                            <label key={u.id} className="flex items-center gap-2 text-sm">
                                <input type="checkbox" disabled={!can.update} checked={details.data.member_ids.includes(u.id)}
                                    onChange={(e) => details.setData('member_ids', e.target.checked ? [...details.data.member_ids, u.id] : details.data.member_ids.filter((x) => x !== u.id))} />
                                {u.name}
                            </label>
                        ))}
                    </fieldset>
                    {can.update && <div className="sm:col-span-2"><button type="submit" className={primaryBtn} disabled={details.processing || !details.isDirty}>{sm('common.save')}</button></div>}
                </form>

                <section className={`${panelCls} space-y-2 p-4`} aria-labelledby="sm-findings">
                    <h2 id="sm-findings" className="text-sm font-semibold">{sm('visits.findings')}</h2>
                    <ul className="space-y-1 text-sm">
                        {visit.findings.map((f) => <li key={f.id}>[{sm(`enums.finding_severity.${f.severity}`)}]{f.section ? ` ${sm('edit.section', { n: f.section })}:` : ''} {f.body}</li>)}
                    </ul>
                    {can.update && (
                        <form onSubmit={addFinding} className="grid gap-2 sm:grid-cols-4">
                            <div>
                                <label htmlFor="sm-f-sec" className={labelCls}>{sm('clarifications.section')}</label>
                                <select id="sm-f-sec" className={inputCls} value={finding.data.section} onChange={(e) => finding.setData('section', e.target.value)}>
                                    <option value="">—</option>
                                    {[1, 2, 3, 4].map((n) => <option key={n} value={n}>{sm('edit.section', { n })}</option>)}
                                </select>
                            </div>
                            <div>
                                <label htmlFor="sm-f-sev" className={labelCls}>{sm('visits.severity')}</label>
                                <select id="sm-f-sev" className={inputCls} value={finding.data.severity} onChange={(e) => finding.setData('severity', e.target.value as FindingSeverity)}>
                                    {severities.map((s) => <option key={s} value={s}>{sm(`enums.finding_severity.${s}`)}</option>)}
                                </select>
                            </div>
                            <div className="sm:col-span-2">
                                <label htmlFor="sm-f-body" className={labelCls}>{sm('visits.addFinding')}</label>
                                <input id="sm-f-body" className={inputCls} value={finding.data.body} onChange={(e) => finding.setData('body', e.target.value)} required />
                                {finding.errors.body && <p className={errorCls}>{finding.errors.body}</p>}
                            </div>
                            <button type="submit" className={secondaryBtn} disabled={finding.processing}>{sm('common.add')}</button>
                        </form>
                    )}
                </section>

                <section className={`${panelCls} space-y-2`} aria-labelledby="sm-items">
                    <h2 id="sm-items" className="px-4 pt-4 text-sm font-semibold">{sm('visits.actionItems')}</h2>
                    <ul className="divide-y divide-(--app-border)">{visit.action_items.map((i) => <ActionItemCard key={i.id} item={i} />)}</ul>
                    {can.update && (
                        <form onSubmit={addItem} className="grid gap-2 p-4 sm:grid-cols-4">
                            <div className="sm:col-span-2">
                                <label htmlFor="sm-i-title" className={labelCls}>{sm('visits.addActionItem')}</label>
                                <input id="sm-i-title" className={inputCls} value={item.data.title} onChange={(e) => item.setData('title', e.target.value)} required />
                                {item.errors.title && <p className={errorCls}>{item.errors.title}</p>}
                            </div>
                            <div>
                                <label htmlFor="sm-i-due" className={labelCls}>{sm('common.due')}</label>
                                <input id="sm-i-due" type="date" className={inputCls} value={item.data.due_on} onChange={(e) => item.setData('due_on', e.target.value)} required />
                            </div>
                            <div>
                                <label htmlFor="sm-i-finding" className={labelCls}>{sm('visits.findings')}</label>
                                <select id="sm-i-finding" className={inputCls} value={item.data.finding_id} onChange={(e) => item.setData('finding_id', e.target.value)}>
                                    <option value="">—</option>
                                    {visit.findings.map((f) => <option key={f.id} value={f.id}>{f.body.slice(0, 60)}</option>)}
                                </select>
                            </div>
                            <div className="sm:col-span-2">
                                <label htmlFor="sm-i-user" className={labelCls}>{sm('visits.ownerUser')}</label>
                                <select id="sm-i-user" className={inputCls} value={item.data.owner_user_id} onChange={(e) => item.setData('owner_user_id', e.target.value)}>
                                    <option value="">—</option>
                                    {team_candidates.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </select>
                            </div>
                            <p className="text-xs text-(--app-muted-foreground) sm:col-span-2">{sm('visits.owner')}: {name(visit.organization)}</p>
                            <button type="submit" className={primaryBtn} disabled={item.processing}>{sm('common.add')}</button>
                        </form>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
