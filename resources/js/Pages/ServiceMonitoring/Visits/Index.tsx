import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { ListPagination } from '@/Components/ui';
import { errorCls, inputCls, labelCls, panelCls, primaryBtn, secondaryBtn, useSm } from '@/Components/serviceMonitoring/helpers';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { FiscalYearRow, Named, NamedWithId, Paginated, VisitStatus } from '@/types/serviceMonitoring';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

type Visit = { id: string; visited_on: string; purpose: string; status: VisitStatus; findings: number; action_items: number; organization: Named };

interface Props {
    visits: Paginated<Visit>;
    participants: NamedWithId[];
    years: FiscalYearRow[];
    team_candidates: { id: number; name: string }[];
}

/** Monitoring visits in scope, and planning a new one (D22). */
export default function VisitsIndex({ visits, participants, years, team_candidates }: Props): JSX.Element {
    const { sm, name } = useSm();
    const [planning, setPlanning] = useState(false);
    const form = useForm({ participant_id: participants[0]?.id ?? '', period_id: '', visited_on: new Date().toISOString().slice(0, 10), purpose: '', member_ids: [] as number[] });

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, period_id: d.period_id || null }));
        form.post(route('service-monitoring.visits.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('visits.title')} description={sm('visits.description')}
            actions={participants.length > 0 ? <button type="button" className={primaryBtn} onClick={() => setPlanning(true)}>{sm('visits.plan')}</button> : undefined} />}>
            <Head title={sm('visits.title')} />
            <div className="space-y-4">
                {planning && (
                    <form onSubmit={save} className={`${panelCls} grid gap-3 p-4 sm:grid-cols-2`} aria-label={sm('visits.plan')}>
                        <div>
                            <label htmlFor="sm-v-p" className={labelCls}>{sm('common.institution')}</label>
                            <select id="sm-v-p" className={inputCls} value={form.data.participant_id} onChange={(e) => form.setData('participant_id', e.target.value)}>
                                {participants.map((p) => <option key={p.id} value={p.id}>{name(p)}</option>)}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="sm-v-period" className={labelCls}>{sm('common.period')}</label>
                            <select id="sm-v-period" className={inputCls} value={form.data.period_id} onChange={(e) => form.setData('period_id', e.target.value)}>
                                <option value="">—</option>
                                {years.flatMap((y) => y.periods).map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="sm-v-date" className={labelCls}>{sm('visits.visitedOn')}</label>
                            <input id="sm-v-date" type="date" className={inputCls} value={form.data.visited_on} onChange={(e) => form.setData('visited_on', e.target.value)} required />
                        </div>
                        <div className="sm:col-span-2">
                            <label htmlFor="sm-v-purpose" className={labelCls}>{sm('visits.purpose')}</label>
                            <textarea id="sm-v-purpose" rows={3} className={inputCls} value={form.data.purpose} onChange={(e) => form.setData('purpose', e.target.value)} required />
                            {form.errors.purpose && <p className={errorCls}>{form.errors.purpose}</p>}
                        </div>
                        <fieldset className="grid gap-1 sm:col-span-2 sm:grid-cols-3">
                            <legend className={labelCls}>{sm('visits.team')}</legend>
                            {team_candidates.map((u) => (
                                <label key={u.id} className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={form.data.member_ids.includes(u.id)}
                                        onChange={(e) => form.setData('member_ids', e.target.checked ? [...form.data.member_ids, u.id] : form.data.member_ids.filter((x) => x !== u.id))} />
                                    {u.name}
                                </label>
                            ))}
                        </fieldset>
                        <div className="flex gap-2 sm:col-span-2">
                            <button type="submit" className={primaryBtn} disabled={form.processing}>{sm('common.save')}</button>
                            <button type="button" className={secondaryBtn} onClick={() => setPlanning(false)}>{sm('common.cancel')}</button>
                        </div>
                    </form>
                )}
                {visits.data.length === 0 ? <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('visits.empty')}</p> : (
                    <ul className={`${panelCls} divide-y divide-(--app-border)`}>
                        {visits.data.map((v) => (
                            <li key={v.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                <div className="min-w-0">
                                    <Link className="text-sm font-medium underline" href={route('service-monitoring.visits.show', v.id)}>{name(v.organization)} · <LocalizedDateDisplay value={v.visited_on} /></Link>
                                    <p className="truncate text-xs text-(--app-muted-foreground)">{v.purpose}</p>
                                </div>
                                <span className="text-xs">{sm(`enums.visit_status.${v.status}`)} · {sm('visits.counts', { findings: v.findings, items: v.action_items })}</span>
                            </li>
                        ))}
                    </ul>
                )}
                <ListPagination meta={visits} />
            </div>
        </AuthenticatedLayout>
    );
}
