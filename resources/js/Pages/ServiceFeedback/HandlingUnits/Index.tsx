import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { AppDialog, Alert, Button, FilterField, FilterPanel, FormField, Select, Textarea, actionLinkClassName, cx } from '@euisis/ui';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { unitsIndexUrl, type CaseType, type HandlingMember } from './MemberForm';

type HandlingUnit = {
    id: string;
    case_type: CaseType;
    status: 'active' | 'inactive';
    description: string | null;
    organization_id: string;
    organization: string | null;
    unit: string | null;
    problems: string[];
    members: HandlingMember[];
    can_manage: boolean;
};

type Props = {
    units: HandlingUnit[];
    organizations: { id: string; name: string }[];
    filters: { organization_id: string };
    /** This page's type: the Tip-off Unit (suggestion) or the Complaint Unit. */
    caseType: CaseType;
};

const PANEL = 'rounded-panel border border-(--app-border) bg-(--app-surface)';

/**
 * The Tip-off Unit and the Complaint Unit (ADR 0034): each type is received
 * by its own work unit. An institution's own unit is picked, its staff are
 * given a responsibility, and each member's signature is registered. Adding
 * and editing happen on their own pages; ending a term is a confirmation.
 */
export default function HandlingUnitsIndex({ units, organizations, filters, caseType }: Props) {
    const { t } = useLocale();
    const h = (key: string) => t(`serviceFeedback.handling.${key}`);
    const [ending, setEnding] = useState<{ unit: HandlingUnit; member: HandlingMember } | null>(null);
    const title = h(caseType === 'complaint' ? 'titleComplaint' : 'titleTipOff');

    function filter(next: Partial<Props['filters']>) {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== ''));
        router.get(unitsIndexUrl(caseType), query, { preserveState: true, replace: true });
    }

    function toggle(unit: HandlingUnit) {
        router.patch(route('service-feedback.handling-units.update', unit.id), { status: unit.status === 'active' ? 'inactive' : 'active' }, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={h(caseType === 'complaint' ? 'introComplaint' : 'introTipOff')} actions={organizations.length > 0 ? <Button as={Link} href={route('service-feedback.handling-units.create', { case_type: caseType })} variant="primary">{h(caseType === 'complaint' ? 'designateComplaint' : 'designateTipOff')}</Button> : undefined} />}>
            <Head title={title} />
            <div className="space-y-5 p-4 sm:p-6">
                <FilterPanel onSubmit={(e) => e.preventDefault()}>
                    <FilterField label={t('serviceFeedback.filterOrganization')} htmlFor="hu-org">
                        <Select id="hu-org" value={filters.organization_id} onChange={(e) => filter({ organization_id: e.target.value })}>
                            <option value="">{t('serviceFeedback.allOrganizations')}</option>
                            {organizations.map((org) => <option key={org.id} value={org.id}>{org.name}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                {units.length === 0 ? <EmptyState title={h(caseType === 'complaint' ? 'noneComplaint' : 'noneTipOff')} description={h(caseType === 'complaint' ? 'noneHintComplaint' : 'noneHintTipOff')} /> : units.map((unit) => (
                    <section key={unit.id} aria-labelledby={`hu-${unit.id}`} className={cx(PANEL, 'space-y-4 p-5')}>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 id={`hu-${unit.id}`} className="text-[15px] font-semibold text-(--app-foreground)">{unit.unit ?? '—'}</h2>
                                <p className="text-sm text-(--app-muted-foreground)">{unit.organization ?? '—'}</p>
                                {unit.description && <p className="mt-1 text-sm">{unit.description}</p>}
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge status={unit.status} label={h(unit.status === 'active' ? 'active' : 'inactive')} />
                                {unit.can_manage && unit.status === 'active' && <Button as={Link} href={route('service-feedback.handling-units.members.create', unit.id)} size="sm" variant="primary">{h('addMember')}</Button>}
                                {unit.can_manage && <Button type="button" size="sm" variant="outline" onClick={() => toggle(unit)}>{h(unit.status === 'active' ? 'deactivate' : 'activate')}</Button>}
                            </div>
                        </div>

                        {unit.problems.length > 0 && <Alert tone="warning"><ul className="list-disc space-y-0.5 pl-5">{unit.problems.map((p) => <li key={p}>{p}</li>)}</ul></Alert>}

                        {unit.members.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{h('noMembers')}</p> : (
                            <ul className="divide-y divide-(--app-border)">
                                {unit.members.map((member) => (
                                    <li key={member.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                        <div className="min-w-0">
                                            <div className="font-medium">{member.name ?? member.employee_number ?? '—'}</div>
                                            <div className="text-xs text-(--app-muted-foreground)">
                                                {[member.employee_number, h(`responsibility.${member.responsibility}`)].filter(Boolean).join(' · ')} · <LocalizedDateDisplay value={member.effective_from} />{member.effective_to && <> → <LocalizedDateDisplay value={member.effective_to} /></>}
                                                {member.appointment_reference && <> · {member.appointment_reference}</>}
                                            </div>
                                            <div className="mt-1 flex flex-wrap gap-2 text-xs">
                                                {!member.placed && <span className="font-medium text-amber-700 dark:text-amber-400">{h('notPlaced')}</span>}
                                                {!member.has_account && <span className="font-medium text-amber-700 dark:text-amber-400">{h('noAccount')}</span>}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-4">
                                            <figure className="text-center">
                                                <img src={member.signature_url} alt={h('signatureOf').replace('{name}', member.name ?? '')} className="h-12 w-28 rounded-control border border-(--app-border) bg-white object-contain p-1" loading="lazy" />
                                                <figcaption className="mt-0.5 text-[11px] text-(--app-muted-foreground)">{h('signature')}</figcaption>
                                            </figure>
                                            {unit.can_manage && <div className="flex flex-col items-end gap-1">
                                                <Link href={route('service-feedback.handling-units.members.edit', { unit: unit.id, member: member.id })} className={actionLinkClassName()}>{t('common.edit')}</Link>
                                                <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => setEnding({ unit, member })}>{h('endTerm')}</button>
                                            </div>}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                ))}
            </div>

            {ending && <EndMemberDialog unit={ending.unit} member={ending.member} onClose={() => setEnding(null)} />}
        </AuthenticatedLayout>
    );
}

function EndMemberDialog({ unit, member, onClose }: { unit: HandlingUnit; member: HandlingMember; onClose: () => void }) {
    const { t } = useLocale();
    const h = (key: string) => t(`serviceFeedback.handling.${key}`);
    const form = useForm({ reason: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('service-feedback.handling-units.members.end', { unit: unit.id, member: member.id }), { preserveScroll: true, onSuccess: onClose });
    }

    return <AppDialog open onClose={onClose} title={h('endTerm')} description={member.name ?? undefined} maxWidth="lg"
        footer={<><Button type="button" variant="outline" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="hu-end-member" variant="destructive" loading={form.processing}>{h('endTerm')}</Button></>}>
        <form id="hu-end-member" onSubmit={submit} className="grid gap-4">
            <FormField id="hu-end-reason" label={t('serviceFeedback.transitionReason')} error={form.errors.reason} required>
                {({ id }) => <Textarea id={id} rows={3} maxLength={1000} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />}
            </FormField>
        </form>
    </AppDialog>;
}
