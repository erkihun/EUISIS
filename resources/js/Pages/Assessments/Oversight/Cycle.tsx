import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { CycleForm, type CycleData, type Option } from '@/Components/assessmentOversight/CycleForm';
import { named, oversightHref, type Cycle as ShellCycle } from '@/Components/assessmentOversight/shell';
import { Section, Table, dangerLinkBtn, filterInputCls, pageCls, primaryBtn, secondaryBtn, smallBtn, smallPrimaryBtn, tdCls, thCls } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Participant = { id: string; organization_id: string; status: string; submission_status: string; exclusion_reason: string | null; organization: Option | null };

type Props = {
    cycle: CycleData;
    participants: Participant[];
    organizations: Option[];
    eligibility: { eligible: number; excluded: number; by_reason: Array<{ code: string; name_en: string; name_am: string | null; n: number }> };
    pending: number;
    policies: Option[];
    types: Option[];
    employeeStatuses: string[];
    search: string | null;
};

/** One cycle: settings, explicit participating institutions and the eligibility snapshot. */
export default function OversightCycle({ cycle, participants, organizations, eligibility, pending, policies, types, employeeStatuses, search }: Props) {
    const { t, locale } = useLocale();
    const [picked, setPicked] = useState<string[]>([]);
    const finalized = cycle.eligibility_status === 'finalized';
    const post = (name: string, data: Record<string, string> = {}) => router.post(route(name, cycle.id), data, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={named(cycle, locale)} description={`${cycle.code} · ${cycle.period_start} – ${cycle.period_end} · ${t(`assessmentOversight.cycleStatuses.${cycle.status}`)}`}
            actions={<div className="flex gap-2">
                <Link href={oversightHref('assessment-oversight.dashboard', { id: cycle.id } as ShellCycle)} className={secondaryBtn}>{t('assessmentOversight.nav.dashboard')}</Link>
                <Link href={route('assessment-oversight.setup')} className={secondaryBtn}>{t('assessmentOversight.nav.setup')}</Link>
                {cycle.status === 'draft' && <button type="button" className={primaryBtn} onClick={() => post('assessment-oversight.cycles.status', { status: 'active' })}>{t('assessmentOversight.cycleActions.activate')}</button>}
                {cycle.status === 'active' && <button type="button" className={secondaryBtn} onClick={() => post('assessment-oversight.cycles.status', { status: 'closed' })}>{t('assessmentOversight.cycleActions.close')}</button>}
            </div>} />}>
            <Head title={named(cycle, locale)} />
            <div className={pageCls}>
                <Section title={t('assessmentOversight.cycleSections.settings')}>
                    <CycleForm cycle={cycle} types={types} policies={policies} employeeStatuses={employeeStatuses} />
                </Section>

                <Section title={t('assessmentOversight.cycleSections.eligibility')} description={t('assessmentOversight.eligibilityHelp')}>
                    <div className="flex flex-wrap items-center gap-6 text-sm">
                        <div><span className="text-gray-500">{t('assessmentOversight.eligible')}</span> <b className="tabular-nums">{eligibility.eligible}</b></div>
                        <div><span className="text-gray-500">{t('assessmentOversight.excluded')}</span> <b className="tabular-nums">{eligibility.excluded}</b></div>
                        <div><span className="text-gray-500">{t('assessmentOversight.snapshotAt')}</span> {cycle.eligibility_snapshot_at ? <LocalizedDateDisplay value={cycle.eligibility_snapshot_at} withTime /> : '—'}</div>
                        <div><span className="text-gray-500">{t('assessmentOversight.eligibility')}</span> {t(`assessmentOversight.eligibilityStatuses.${cycle.eligibility_status}`)}</div>
                        {pending > 0 && <div className="text-amber-700">{t('assessmentOversight.pendingExclusions')}: {pending}</div>}
                    </div>
                    {eligibility.by_reason.length > 0 && (
                        <ul className="mt-3 text-sm">{eligibility.by_reason.map((r) => <li key={r.code} className="flex max-w-md justify-between py-0.5"><span>{named(r, locale)}</span><span className="tabular-nums">{r.n}</span></li>)}</ul>
                    )}
                    {!finalized && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            <button type="button" className={smallPrimaryBtn} onClick={() => post('assessment-oversight.cycles.eligibility.snapshot')}>{cycle.eligibility_snapshot_at ? t('assessmentOversight.cycleActions.resnapshot') : t('assessmentOversight.cycleActions.snapshot')}</button>
                            {cycle.eligibility_snapshot_at && (
                                <button type="button" className={smallBtn} onClick={() => { if (window.confirm(t('assessmentOversight.cycleActions.finalizeConfirm'))) post('assessment-oversight.cycles.eligibility.finalize'); }}>{t('assessmentOversight.cycleActions.finalize')}</button>
                            )}
                        </div>
                    )}
                </Section>

                <Section title={t('assessmentOversight.cycleSections.participants')} description={t('assessmentOversight.participantsHelp')} flush>
                    <Table head={<>
                        <th className={thCls}>{t('assessmentOversight.institution')}</th>
                        <th className={thCls}>{t('assessmentOversight.participation')}</th>
                        <th className={thCls}>{t('assessmentOversight.submissionStatus')}</th>
                        <th className={thCls}><span className="sr-only">{t('assessmentOversight.actionsLabel')}</span></th>
                    </>}>
                        {participants.map((p) => (
                            <tr key={p.id}>
                                <td className={tdCls}>{named(p.organization, locale)} <span className="text-xs text-gray-500">{p.organization?.code}</span></td>
                                <td className={tdCls}>{t(`assessmentOversight.participationStatuses.${p.status}`)}{p.exclusion_reason && <div className="text-xs text-gray-500">{p.exclusion_reason}</div>}</td>
                                <td className={tdCls}>{t(`assessmentOversight.submissionStatuses.${p.submission_status}`)}</td>
                                <td className={tdCls}>
                                    {!finalized && p.status === 'included' && (
                                        <button type="button" className={dangerLinkBtn} onClick={() => {
                                            const reason = window.prompt(t('assessmentOversight.removeReason'));
                                            if (reason) router.delete(route('assessment-oversight.cycles.organizations.destroy', { cycle: cycle.id, organization: p.organization_id }), { data: { reason }, preserveScroll: true });
                                        }}>{t('assessmentOversight.actions.remove')}</button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Section>

                {!finalized && (
                    <Section title={t('assessmentOversight.addInstitutions')}>
                        <form className="mb-3 flex gap-2" onSubmit={(e) => { e.preventDefault(); router.get(route('assessment-oversight.cycles.show', cycle.id), { search: new FormData(e.currentTarget).get('search') as string }, { preserveState: true }); }}>
                            <input name="search" aria-label={t('assessmentOversight.searchInstitution')} placeholder={t('assessmentOversight.searchInstitution')} className={filterInputCls} defaultValue={search ?? ''} />
                            <button className={smallBtn}>{t('common.filter')}</button>
                        </form>
                        <div className="grid max-h-80 gap-1 overflow-y-auto sm:grid-cols-2">
                            {organizations.map((o) => (
                                <label key={o.id} className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={picked.includes(o.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, o.id] : picked.filter((x) => x !== o.id))} />
                                    {named(o, locale)} <span className="text-xs text-gray-500">{o.code}</span>
                                </label>
                            ))}
                        </div>
                        <div className="mt-3 flex gap-2">
                            <button type="button" className={smallPrimaryBtn} disabled={picked.length === 0} onClick={() => router.post(route('assessment-oversight.cycles.organizations.store', cycle.id), { organization_ids: picked }, { preserveScroll: true, onSuccess: () => setPicked([]) })}>
                                {t('assessmentOversight.addSelected').replace(':count', String(picked.length))}
                            </button>
                            <button type="button" className={smallBtn} onClick={() => setPicked(organizations.map((o) => o.id))}>{t('assessmentOversight.selectAllShown')}</button>
                        </div>
                    </Section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
