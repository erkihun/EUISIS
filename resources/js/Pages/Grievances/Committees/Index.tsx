import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Modal from '@/Components/Modal';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AdminForm, choices, today } from '@/Components/grievances/AdminForm';
import MemberAvatar from '@/Components/grievances/MemberAvatar';
import { Pager, GPill, nameOf, employeeName, fmt, pageCls, inputCls, primaryBtn, useEnumLabel } from '@/Components/grievances/ui';
import { AppActionMenu, AppMetricCard, FilterField, FilterPanel } from '@/Components/ui';
import { AlertTriangle, CheckCircle, ChevronRight, PencilIcon, Plus, UserPlusIcon, XCircle } from '@/Components/Icons';
import { UsersTwoIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import type { CommitteeListItem, CommitteeListMember, CommitteesIndexProps } from '@/types/grievances';

/**
 * Grievance committees board. One card per committee: who sits on it, whether
 * its composition is complete, and every action (register, edit or end a
 * member, edit the committee) in a popup without leaving the list.
 * Status is always text plus an icon, never colour alone.
 */

type Dialog =
    | { kind: 'register'; committee: CommitteeListItem }
    | { kind: 'editCommittee'; committee: CommitteeListItem }
    | { kind: 'editMember'; committee: CommitteeListItem; member: CommitteeListMember }
    | { kind: 'endMember'; committee: CommitteeListItem; member: CommitteeListMember };

const SOURCES = ['appointed', 'elected', 'hr_representative'];
const ROLE_ORDER: Record<string, string> = {
    chairperson: 'bg-primary text-white',
    writer: 'bg-amber-500 text-white dark:bg-amber-600',
    member: 'bg-(--app-surface-muted) text-(--app-foreground) ring-1 ring-(--app-border-strong)',
};

function initials(name: string): string {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => Array.from(part)[0] ?? '').join('');
}

export default function Index({ committees, filters, options, summary, policy, can }: CommitteesIndexProps) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const [query, setQuery] = useState({ search: filters.search ?? '', organization_id: filters.organization_id ?? '', committee_type: filters.committee_type ?? '', status: filters.status ?? '' });
    const [dialog, setDialog] = useState<Dialog | null>(null);
    // "Expand all" / "Collapse all": a counter so pressing the same one twice still applies.
    const [bulk, setBulk] = useState<{ open: boolean; n: number } | null>(null);
    const close = () => setDialog(null);
    const organizations = options.organizations.map(org => ({ value: org.id!, label: nameOf(org, locale) }));
    const filtered = Object.values(filters).some(Boolean);
    const b = (key: string) => t(`grievanceAdmin.committeeBoard.${key}`);

    return <AuthenticatedLayout header={<PageHeader title={t('grievanceAdmin.committees')} description={t('grievanceAdmin.committeesHelp')}
        actions={can.create && <Link className={`${primaryBtn} gap-2`} href={route('grievances.committees.create')}><Plus className="h-4 w-4" aria-hidden />{t('grievanceAdmin.newCommittee')}</Link>} />}>
        <Head title={t('grievanceAdmin.committees')} />
        <div className={pageCls}>
            <section aria-label={t('grievanceAdmin.committees')} className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <AppMetricCard label={b('totalCommittees')} value={summary.total} detail={b('totalHelp')} variant="primary" icon={<UsersTwoIcon className="h-5 w-5" />} />
                <AppMetricCard label={b('activeCommittees')} value={summary.active} detail={b('activeHelp')} variant="success" icon={<CheckCircle className="h-5 w-5" />} />
                <AppMetricCard label={b('pendingCommittees')} value={summary.pending} detail={b('pendingHelp')} variant="warning" icon={<AlertTriangle className="h-5 w-5" />} />
                <AppMetricCard label={b('servingMembers')} value={summary.members} detail={b('membersHelp')} variant="neutral" icon={<UserPlusIcon className="h-5 w-5" />} />
            </section>

            <FilterPanel onSubmit={() => router.get(route('grievances.committees.index'), query, { preserveState: true })} onReset={() => router.get(route('grievances.committees.index'))} hasActiveFilters={filtered}>
                <FilterField label={t('grievances.common.search')} wide><input type="search" className={inputCls} value={query.search} onChange={event => setQuery({ ...query, search: event.target.value })} /></FilterField>
                {(['organization_id', 'committee_type', 'status'] as const).map(key => <FilterField key={key} label={t(`grievanceAdmin.fields.${key}`)}><select className={inputCls} value={query[key]} onChange={event => setQuery({ ...query, [key]: event.target.value })}><option value="">{t('grievances.common.all')}</option>{(key === 'organization_id' ? organizations : choices(key === 'status' ? options.statuses : options.types)).map(option => <option key={option.value} value={option.value}>{key === 'organization_id' ? option.label : label(key === 'status' ? 'committee_status' : key, option.value)}</option>)}</select></FilterField>)}
            </FilterPanel>

            {committees.data.length === 0
                ? <EmptyBoard filtered={filtered} canCreate={can.create} />
                : <div className="space-y-5">
                    <div className="flex justify-end gap-2">
                        <button type="button" className="text-sm font-medium text-primary hover:underline" onClick={() => setBulk(prev => ({ open: true, n: (prev?.n ?? 0) + 1 }))}>{b('expandAll')}</button>
                        <span className="text-(--app-muted-foreground)" aria-hidden>·</span>
                        <button type="button" className="text-sm font-medium text-primary hover:underline" onClick={() => setBulk(prev => ({ open: false, n: (prev?.n ?? 0) + 1 }))}>{b('collapseAll')}</button>
                    </div>
                    {committees.data.map(committee => <CommitteeCard key={committee.id} committee={committee} policy={policy} onOpen={setDialog} bulk={bulk} />)}
                </div>}
            {committees.data.length > 0 && <Pager page={committees} />}
        </div>

        <Modal show={dialog !== null} onClose={close} maxWidth="2xl">
            {dialog?.kind === 'register' && <DialogShell title={`${t('grievanceAdmin.registerMember')}: ${nameOf(dialog.committee, locale)}`} help={t('grievanceAdmin.addMemberHelp')}>
                <AdminForm key={`register-${dialog.committee.id}`} initial={{ employee_id: '', role: 'member', selection_source: '', term_number: '1', effective_from: today(), effective_to: '', appointment_reference: '', signature: null, organization_id: dialog.committee.organization?.id ?? '' }}
                    fields={[{ key: 'employee_id', type: 'lookup', lookup: 'employees', organizationKey: 'organization_id', lookupParams: { exclude_committee_id: dialog.committee.id }, required: true }, { key: 'role', type: 'select', options: choices(options.roles), enumGroup: 'committee_role', required: true }, { key: 'selection_source', type: 'select', options: choices(SOURCES), enumGroup: 'committee_source' }, { key: 'term_number', type: 'select', options: choices(['1', '2']) }, { key: 'effective_from', type: 'date', required: true }, { key: 'effective_to', type: 'date' }, { key: 'appointment_reference' }, { key: 'signature', type: 'file', accept: 'image/png,image/jpeg,image/webp', required: true, help: t('grievanceAdmin.signatureHelp') }]}
                    url={route('grievances.committees.members.store', dialog.committee.id)} onSaved={close} onCancel={close} />
            </DialogShell>}
            {dialog?.kind === 'editMember' && <DialogShell title={`${t('grievanceAdmin.editMember')}: ${employeeName(dialog.member.employee, locale)}`} help={t('grievanceAdmin.editMemberHelp')}>
                <AdminForm key={`edit-${dialog.member.id}`} initial={{ role: dialog.member.role, selection_source: dialog.member.selection_source ?? '', term_number: String(dialog.member.term_number ?? 1), effective_from: dialog.member.effective_from ?? today(), effective_to: dialog.member.effective_to ?? '', appointment_reference: dialog.member.appointment_reference ?? '', signature: null }}
                    fields={[{ key: 'role', type: 'select', options: choices(options.roles), enumGroup: 'committee_role', required: true }, { key: 'selection_source', type: 'select', options: choices(SOURCES), enumGroup: 'committee_source' }, { key: 'term_number', type: 'select', options: choices(['1', '2']) }, { key: 'effective_from', type: 'date', required: true }, { key: 'effective_to', type: 'date' }, { key: 'appointment_reference' }, { key: 'signature', type: 'file', accept: 'image/png,image/jpeg,image/webp', help: t('grievanceAdmin.signatureReplaceHelp') }]}
                    url={route('grievances.committees.members.update', [dialog.committee.id, dialog.member.id])} onSaved={close} onCancel={close} />
            </DialogShell>}
            {dialog?.kind === 'endMember' && <DialogShell title={`${t('grievanceAdmin.endMembership')}: ${employeeName(dialog.member.employee, locale)}`} help={b('endMemberHelp')}>
                <AdminForm key={`end-${dialog.member.id}`} initial={{ reason: '', effective_to: today() }} fields={[{ key: 'reason', type: 'textarea', required: true, maxLength: 1000 }, { key: 'effective_to', type: 'date' }]}
                    url={route('grievances.committees.members.end', [dialog.committee.id, dialog.member.id])} onSaved={close} onCancel={close} />
            </DialogShell>}
            {dialog?.kind === 'editCommittee' && <DialogShell title={`${b('editCommittee')}: ${nameOf(dialog.committee, locale)}`}>
                <AdminForm key={`committee-${dialog.committee.id}`} method="patch" initial={{ name_en: dialog.committee.name_en, name_am: dialog.committee.name_am ?? '', organization_id: dialog.committee.organization?.id ?? '', organization_unit_id: dialog.committee.organization_unit?.id ?? '', effective_to: dialog.committee.effective_to ?? '', description_en: dialog.committee.description_en ?? '', description_am: dialog.committee.description_am ?? '' }}
                    fields={[{ key: 'name_en', required: true }, { key: 'name_am' }, { key: 'organization_unit_id', label: t('grievanceAdmin.committeeUnit'), type: 'lookup', lookup: 'units', organizationKey: 'organization_id', initialLabel: dialog.committee.organization_unit ? nameOf(dialog.committee.organization_unit, locale) : undefined, emptyLabel: b('allStaff'), help: t('grievanceAdmin.committeeUnitHelp') }, { key: 'effective_to', type: 'date' }, { key: 'description_en', type: 'textarea' }, { key: 'description_am', type: 'textarea' }]}
                    url={route('grievances.committees.update', dialog.committee.id)} onSaved={close} onCancel={close} />
            </DialogShell>}
        </Modal>
    </AuthenticatedLayout>;
}

function DialogShell({ title, help, children }: { title: string; help?: string; children: ReactNode }) {
    return <div className="p-6">
        <h2 className="text-lg font-semibold text-(--app-foreground)">{title}</h2>
        {help && <p className="mt-1 text-sm text-(--app-muted-foreground)">{help}</p>}
        <div className="mt-5">{children}</div>
    </div>;
}

function EmptyBoard({ filtered, canCreate }: { filtered: boolean; canCreate: boolean }) {
    const { t } = useLocale();
    return <div className="flex flex-col items-center rounded-xl border border-dashed border-(--app-border-strong) bg-(--app-surface) px-6 py-14 text-center">
        <span className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"><UsersTwoIcon className="h-6 w-6" /></span>
        <h2 className="mt-4 text-base font-semibold text-(--app-foreground)">{filtered ? t('grievanceAdmin.committeeBoard.noMatches') : t('grievanceAdmin.committeeBoard.noCommittees')}</h2>
        {!filtered && <p className="mt-1 max-w-md text-sm text-(--app-muted-foreground)">{t('grievanceAdmin.committeeBoard.noCommitteesHelp')}</p>}
        {!filtered && canCreate && <Link className={`${primaryBtn} mt-5 gap-2`} href={route('grievances.committees.create')}><Plus className="h-4 w-4" aria-hidden />{t('grievanceAdmin.newCommittee')}</Link>}
    </div>;
}

function Check({ ok, children }: { ok: boolean; children: ReactNode }) {
    const Icon = ok ? CheckCircle : XCircle;
    return <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ${ok
        ? 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-300 dark:ring-emerald-900'
        : 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-300 dark:ring-amber-900'}`}>
        <Icon className="h-3.5 w-3.5 shrink-0" aria-hidden />{children}
    </span>;
}

function CommitteeCard({ committee, policy, onOpen, bulk }: { committee: CommitteeListItem; policy: CommitteesIndexProps['policy']; onOpen: (dialog: Dialog) => void; bulk: { open: boolean; n: number } | null }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const b = (key: string) => t(`grievanceAdmin.committeeBoard.${key}`);
    const members = committee.members;
    const count = members.length;
    const hasChair = members.some(member => member.role === 'chairperson');
    const hasWriter = members.some(member => member.role === 'writer');
    const signed = members.filter(member => member.signature).length;
    const ready = committee.is_grievance_type && committee.problems.length === 0;
    const state = (ok: boolean) => ok ? b('present') : b('missing');
    const [membersOpen, setMembersOpen] = useState(true);
    // The list shows one line per committee ("Institution · Type"); expanding shows the full card.
    const [open, setOpen] = useState(false);
    useEffect(() => { if (bulk) { setOpen(bulk.open); setMembersOpen(true); } }, [bulk]);
    // The work unit tells apart two committees of one institution (e.g. a school's teachers and its other staff).
    const unit = committee.organization_unit ? ` · ${nameOf(committee.organization_unit, locale)}` : '';
    const title = `${nameOf(committee.organization, locale)} · ${label('committee_type', committee.committee_type)}${unit}`;
    const bodyId = `committee-${committee.id}-body`;

    if (!open) {
        return <article className="overflow-hidden rounded-xl border border-(--app-border) bg-(--app-surface) shadow-xs">
            <h2 className="m-0">
                <button type="button" aria-expanded={false} aria-controls={bodyId} onClick={() => setOpen(true)}
                    className="flex w-full items-center gap-3 px-5 py-4 text-left hover:bg-(--app-surface-muted) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden><UsersTwoIcon className="h-5 w-5" /></span>
                    <span className="min-w-0 flex-1 truncate text-base font-medium text-(--app-foreground)">{title}</span>
                    <span className="hidden text-xs font-medium text-primary sm:inline">{b('expand')}</span>
                    <ChevronRight className="h-5 w-5 shrink-0 text-(--app-muted-foreground)" aria-hidden />
                </button>
            </h2>
        </article>;
    }

    return <article id={bodyId} className="relative overflow-hidden rounded-xl border border-(--app-border) bg-(--app-surface) shadow-xs" aria-labelledby={`committee-${committee.id}`}>
        <header className="flex flex-col gap-4 p-5 lg:flex-row lg:items-start lg:justify-between">
            <div className="flex min-w-0 gap-4 pr-10 lg:pr-0">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden><UsersTwoIcon className="h-6 w-6" /></span>
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 id={`committee-${committee.id}`} className="truncate text-base font-semibold text-(--app-foreground)">
                            <Link href={route('grievances.committees.show', committee.id)} className="hover:text-primary hover:underline focus-visible:underline focus-visible:outline-none">{nameOf(committee, locale)}</Link>
                        </h2>
                        <GPill group="committee_status" value={committee.status} />
                        {committee.is_grievance_type && <span className={`inline-flex items-center gap-1 text-xs font-medium ${ready ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'}`}>
                            {ready ? <CheckCircle className="h-3.5 w-3.5" aria-hidden /> : <AlertTriangle className="h-3.5 w-3.5" aria-hidden />}{ready ? b('ready') : b('needsAttention')}
                        </span>}
                    </div>
                    <p className="mt-1 text-sm text-(--app-muted-foreground)">{nameOf(committee.organization, locale)} · {label('committee_type', committee.committee_type)} · {committee.organization_unit ? nameOf(committee.organization_unit, locale) : b('allStaff')}</p>
                    <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-(--app-muted-foreground)">
                        <span>{b('effectivePeriod')}:</span><LocalizedDateDisplay value={committee.effective_from} /><span>–</span>
                        {committee.effective_to ? <LocalizedDateDisplay value={committee.effective_to} /> : <span>{b('noEnd')}</span>}
                        <span aria-hidden>·</span><span>{b('openCases')}: <strong className="font-semibold text-(--app-foreground)">{committee.open_cases_count}</strong></span>
                    </p>
                </div>
            </div>
            {/* The same ⋮ row menu as every other admin list. */}
            <div className="absolute top-4 right-4 flex items-center gap-1 lg:static lg:shrink-0">
                <button type="button" aria-expanded={true} aria-controls={bodyId} onClick={() => setOpen(false)} aria-label={`${b('collapse')}: ${title}`} title={b('collapse')}
                    className="rounded-md p-1.5 text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                    <ChevronRight className="h-5 w-5 -rotate-90" aria-hidden />
                </button>
                <AppActionMenu label={`${t('common.actions')}: ${nameOf(committee, locale)}`} items={[
                    { label: t('grievanceAdmin.registerMember'), icon: <UserPlusIcon className="h-4 w-4" />, onClick: () => onOpen({ kind: 'register', committee }), show: committee.can_manage_members && committee.status !== 'inactive' },
                    { label: b('editCommittee'), icon: <PencilIcon className="h-4 w-4" />, onClick: () => onOpen({ kind: 'editCommittee', committee }), show: committee.can_update },
                    { label: b('viewCommittee'), icon: <ChevronRight className="h-4 w-4" />, href: route('grievances.committees.show', committee.id), separatorBefore: committee.can_manage_members || committee.can_update },
                ]} />
            </div>
        </header>

        {committee.is_grievance_type && <div className="flex flex-wrap items-center gap-2 border-t border-(--app-border) px-5 py-3" aria-label={b('composition')}>
            <span className="mr-1 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{b('composition')}</span>
            <Check ok={count >= policy.min && count <= policy.max}>{fmt(b('seats'), { count, min: policy.min, max: policy.max })}</Check>
            <Check ok={hasChair}>{b('chair')}: {state(hasChair)}</Check>
            {policy.require_writer && <Check ok={hasWriter}>{b('writer')}: {state(hasWriter)}</Check>}
            <Check ok={count > 0 && signed === count}>{fmt(b('signatures'), { count: signed, total: count })}</Check>
        </div>}

        {/* The full problem list, folded: the composition checks above already show the headline. */}
        {committee.problems.length > 0 && <details className="group border-t border-amber-200 bg-amber-50/60 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
            <summary className="flex cursor-pointer list-none items-center gap-2 px-5 py-2.5 font-medium [&::-webkit-details-marker]:hidden">
                <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden />{b('needsAttention')} ({committee.problems.length})
                <ChevronRight className="ml-auto h-4 w-4 transition-transform group-open:rotate-90" aria-hidden />
            </summary>
            <ul className="space-y-1 px-5 pb-3 pl-11">{committee.problems.map(problem => <li key={problem} className="list-disc">{problem}</li>)}</ul>
        </details>}

        {/* Members: collapsible. Folded, the heading still shows the count and the members' initials. */}
        <div className="border-t border-(--app-border) bg-(--app-surface-muted)/40">
            <h3 className="m-0">
                <button type="button" aria-expanded={membersOpen} aria-controls={`committee-${committee.id}-members`} onClick={() => setMembersOpen(open => !open)}
                    className="flex w-full items-center gap-3 px-5 py-3 text-left hover:bg-(--app-surface-muted) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">
                    <ChevronRight className={`h-4 w-4 shrink-0 text-(--app-muted-foreground) transition-transform ${membersOpen ? 'rotate-90' : ''}`} aria-hidden />
                    <span className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{b('membersHeading')} · {count}</span>
                    {!membersOpen && count > 0 && <span className="flex -space-x-2" aria-hidden>{members.slice(0, 5).map(member => <span key={member.id}
                        className={`flex h-7 w-7 items-center justify-center rounded-full text-[11px] font-semibold ring-2 ring-(--app-surface) ${ROLE_ORDER[member.role] ?? ROLE_ORDER.member}`}>{initials(employeeName(member.employee, locale))}</span>)}
                        {count > 5 && <span className="flex h-7 w-7 items-center justify-center rounded-full bg-(--app-surface-muted) text-[11px] font-semibold text-(--app-foreground) ring-2 ring-(--app-surface)">+{count - 5}</span>}</span>}
                    <span className="ml-auto text-xs font-medium text-primary">{membersOpen ? b('hideMembers') : b('showMembers')}</span>
                </button>
            </h3>
            <div id={`committee-${committee.id}-members`} hidden={!membersOpen} className="px-5 pb-4">
                {count === 0
                    ? <p className="text-sm text-(--app-muted-foreground)">{t('grievanceAdmin.noMembersYet')}</p>
                    : <ul className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">{members.map(member => <MemberTile key={member.id} member={member} canManage={committee.can_manage_members}
                        onEdit={() => onOpen({ kind: 'editMember', committee, member })} onEnd={() => onOpen({ kind: 'endMember', committee, member })} />)}</ul>}
            </div>
        </div>
    </article>;
}

function MemberTile({ member, canManage, onEdit, onEnd }: { member: CommitteeListMember; canManage: boolean; onEdit: () => void; onEnd: () => void }) {
    const { t, locale } = useLocale();
    const name = employeeName(member.employee, locale);
    return <li className="flex min-w-0 items-start gap-3 rounded-lg border border-(--app-border) bg-(--app-surface) p-3">
        <MemberAvatar name={name} photoUrl={member.employee?.photo_url} tier={member.role === 'chairperson' ? 'lead' : member.role === 'writer' ? 'second' : 'member'} />
        <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
                <span className="truncate font-medium text-(--app-foreground)">{name}</span>
                <GPill group="committee_role" value={member.role} />
            </div>
            {member.employee?.position && <p className="mt-0.5 truncate text-sm text-(--app-foreground)">{nameOf(member.employee.position, locale)}</p>}
            {member.employee?.unit && <p className="mt-0.5 truncate text-xs text-(--app-muted-foreground)">{nameOf(member.employee.unit, locale)}</p>}
            {/* The signature image itself is not shown on the list; only a missing one is flagged. */}
            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-(--app-muted-foreground)">
                {!member.signature && <span className="inline-flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400"><AlertTriangle className="h-3.5 w-3.5" aria-hidden />{t('grievanceAdmin.signatureMissing')}</span>}
                <span className="inline-flex items-center gap-1"><LocalizedDateDisplay value={member.effective_from} /></span>
            </div>
        </div>
        <div className="shrink-0">
            <AppActionMenu label={`${t('common.actions')}: ${name}`} items={[
                { label: t('grievanceAdmin.editMember'), icon: <PencilIcon className="h-4 w-4" />, onClick: onEdit, show: canManage },
                { label: t('grievanceAdmin.endMembership'), icon: <XCircle className="h-4 w-4" />, onClick: onEnd, variant: 'danger', show: canManage, separatorBefore: true },
            ]} />
        </div>
    </li>;
}
