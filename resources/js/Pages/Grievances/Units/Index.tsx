import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Modal from '@/Components/Modal';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AdminForm, choices, today } from '@/Components/grievances/AdminForm';
import MemberAvatar from '@/Components/grievances/MemberAvatar';
import { Pager, GPill, nameOf, employeeName, pageCls, inputCls, primaryBtn, useEnumLabel } from '@/Components/grievances/ui';
import { AppActionMenu, AppMetricCard, FilterField, FilterPanel } from '@/Components/ui';
import { AlertTriangle, CheckCircle, ChevronRight, PencilIcon, Plus, UserPlusIcon, XCircle } from '@/Components/Icons';
import { Building2 } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import type { HandlerUnitItem, HandlerUnitMember, HandlerUnitsIndexProps } from '@/types/grievances';

/**
 * Grievance Teams & Directorates (ADR 0029). Existing units are designated —
 * nothing is created — and their own staff are named with a Regulation
 * 201/2018 responsibility and a signature. Same board pattern as Committees:
 * one line per unit that expands, ⋮ menus, every action in a popup.
 */

type Dialog =
    | { kind: 'designate' }
    | { kind: 'edit'; unit: HandlerUnitItem }
    | { kind: 'register'; unit: HandlerUnitItem }
    | { kind: 'editMember'; unit: HandlerUnitItem; member: HandlerUnitMember }
    | { kind: 'endMember'; unit: HandlerUnitItem; member: HandlerUnitMember };

const AVATAR: Record<string, string> = {
    head: 'bg-primary text-white',
    expert: 'bg-(--app-surface-muted) text-(--app-foreground) ring-1 ring-(--app-border-strong)',
};

export default function Index({ kind, directorate_exists, units, filters, options, summary, can }: HandlerUnitsIndexProps) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const u = (key: string) => t(`grievanceAdmin.unitBoard.${key}`);
    // Two pages: the Grievance Teams, and the one Grievance Directorate (ADR 0030).
    const isTeams = kind === 'team';
    const listRoute = isTeams ? 'grievances.teams.index' : 'grievances.directorate.index';
    const title = u(isTeams ? 'teamsTitle' : 'directorateTitle');
    const designateLabel = u(isTeams ? 'designateTeam' : 'designateDirectorate');
    const canDesignate = can.designate && (isTeams || !directorate_exists);
    const [query, setQuery] = useState({ search: filters.search ?? '', organization_id: filters.organization_id ?? '', status: filters.status ?? '' });
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const [bulk, setBulk] = useState<{ open: boolean; n: number } | null>(null);
    const close = () => setDialog(null);
    const organizations = options.organizations.map(org => ({ value: org.id!, label: nameOf(org, locale) }));
    const filtered = Object.values(filters).some(Boolean);
    // The head is the Team leader on the Teams page and the Director on the Directorate page.
    const roleGroup = isTeams ? 'unit_responsibility_team' : 'unit_responsibility_directorate';
    const responsibilityField = { key: 'responsibility', type: 'select' as const, options: choices(options.responsibilities), enumGroup: roleGroup, required: true };

    return <AuthenticatedLayout header={<PageHeader title={title} description={u(isTeams ? 'teamsHelp2' : 'directorateHelp2')}
        actions={canDesignate && <button type="button" className={`${primaryBtn} gap-2`} onClick={() => setDialog({ kind: 'designate' })}><Plus className="h-4 w-4" aria-hidden />{designateLabel}</button>} />}>
        <Head title={title} />
        <div className={pageCls}>
            <section aria-label={title} className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-3">
                {isTeams
                    ? <AppMetricCard label={u('teams')} value={summary.teams} detail={u('teamsHelp')} variant="primary" icon={<Building2 className="h-5 w-5" />} />
                    : <AppMetricCard label={u('directorates')} value={summary.directorates} detail={u('directoratesHelp')} variant="accent" icon={<Building2 className="h-5 w-5" />} />}
                <AppMetricCard label={u('members')} value={summary.members} detail={u('membersHelp')} variant="success" icon={<UserPlusIcon className="h-5 w-5" />} />
                <AppMetricCard label={u('unsigned')} value={summary.unsigned} detail={u('unsignedHelp')} variant={summary.unsigned > 0 ? 'warning' : 'neutral'} icon={<AlertTriangle className="h-5 w-5" />} />
            </section>

            <ResponsibilityGuide roleGroup={roleGroup} />

            {isTeams && <FilterPanel onSubmit={() => router.get(route(listRoute), query, { preserveState: true })} onReset={() => router.get(route(listRoute))} hasActiveFilters={filtered}>
                <FilterField label={t('grievances.common.search')} wide><input type="search" className={inputCls} value={query.search} onChange={event => setQuery({ ...query, search: event.target.value })} /></FilterField>
                <FilterField label={t('grievanceAdmin.fields.organization_id')}><select className={inputCls} value={query.organization_id} onChange={event => setQuery({ ...query, organization_id: event.target.value })}><option value="">{t('grievances.common.all')}</option>{organizations.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}</select></FilterField>
                <FilterField label={t('grievanceAdmin.fields.status')}><select className={inputCls} value={query.status} onChange={event => setQuery({ ...query, status: event.target.value })}><option value="">{t('grievances.common.all')}</option>{["active", "inactive"].map(s => <option key={s} value={s}>{label("committee_status", s)}</option>)}</select></FilterField>
            </FilterPanel>}

            {units.data.length === 0
                ? <div className="flex flex-col items-center rounded-card border border-dashed border-(--app-border-strong) bg-(--app-surface) px-6 py-14 text-center">
                    <span className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"><Building2 className="h-6 w-6" /></span>
                    <h2 className="mt-4 text-base font-semibold text-(--app-foreground)">{filtered ? u('noMatches') : u(isTeams ? 'noTeams' : 'noDirectorate')}</h2>
                    {!filtered && <p className="mt-1 max-w-md text-sm text-(--app-muted-foreground)">{u(isTeams ? 'noTeamsHelp' : 'noDirectorateHelp')}</p>}
                    {!filtered && canDesignate && <button type="button" className={`${primaryBtn} mt-5 gap-2`} onClick={() => setDialog({ kind: 'designate' })}><Plus className="h-4 w-4" aria-hidden />{designateLabel}</button>}
                </div>
                : <div className="space-y-4">
                    <div className="flex justify-end gap-2">
                        <button type="button" className="text-sm font-medium text-primary hover:underline" onClick={() => setBulk(prev => ({ open: true, n: (prev?.n ?? 0) + 1 }))}>{t('grievanceAdmin.committeeBoard.expandAll')}</button>
                        <span className="text-(--app-muted-foreground)" aria-hidden>·</span>
                        <button type="button" className="text-sm font-medium text-primary hover:underline" onClick={() => setBulk(prev => ({ open: false, n: (prev?.n ?? 0) + 1 }))}>{t('grievanceAdmin.committeeBoard.collapseAll')}</button>
                    </div>
                    {units.data.map(unit => <UnitCard key={unit.id} unit={unit} onOpen={setDialog} bulk={bulk} />)}
                </div>}
            {units.data.length > 0 && <Pager page={units} />}
        </div>

        <Modal show={dialog !== null} onClose={close} maxWidth="2xl">
            {dialog?.kind === 'designate' && <DialogShell title={designateLabel} help={u(isTeams ? 'designateTeamHelp' : 'designateDirectorateHelp')}>
                {/* The kind is fixed by the page: a team here, or the one directorate. */}
                <AdminForm key={`designate-${kind}`} initial={{ organization_id: organizations.length === 1 ? organizations[0].value : '', organization_unit_id: '', kind, description_en: '', description_am: '' }}
                    fields={[{ key: 'organization_id', type: 'select', required: true, options: organizations, clearOnChange: ['organization_unit_id'] }, { key: 'organization_unit_id', label: u('unitField'), type: 'lookup', lookup: 'units', organizationKey: 'organization_id', required: true }, { key: 'description_en', type: 'textarea' }, { key: 'description_am', type: 'textarea' }]}
                    url={route('grievances.units.store')} onSaved={close} onCancel={close} />
            </DialogShell>}
            {dialog?.kind === 'edit' && <DialogShell title={`${u('editDesignation')}: ${nameOf(dialog.unit.unit, locale)}`}>
                <AdminForm key={`edit-${dialog.unit.id}`} method="patch" initial={{ description_en: dialog.unit.description_en ?? '', description_am: dialog.unit.description_am ?? '' }}
                    fields={[{ key: 'description_en', type: 'textarea' }, { key: 'description_am', type: 'textarea' }]}
                    url={route('grievances.units.update', dialog.unit.id)} onSaved={close} onCancel={close} />
            </DialogShell>}
            {dialog?.kind === 'register' && <DialogShell title={`${u('registerMember')}: ${nameOf(dialog.unit.unit, locale)}`} help={u('registerMemberHelp')}>
                <AdminForm key={`register-${dialog.unit.id}`} initial={{ employee_id: '', responsibility: 'expert', effective_from: today(), effective_to: '', appointment_reference: '', signature: null, organization_id: dialog.unit.organization?.id ?? '', unit_id: dialog.unit.unit?.id ?? '' }}
                    fields={[{ key: 'employee_id', type: 'lookup', lookup: 'employees', organizationKey: 'organization_id', unitKey: 'unit_id', lookupParams: { exclude_handler_unit_id: dialog.unit.id }, required: true }, responsibilityField, { key: 'effective_from', type: 'date', required: true }, { key: 'effective_to', type: 'date' }, { key: 'appointment_reference' }, { key: 'signature', type: 'file', accept: 'image/png,image/jpeg,image/webp', required: true, help: t('grievanceAdmin.signatureHelp') }]}
                    url={route('grievances.units.members.store', dialog.unit.id)} onSaved={close} onCancel={close} />
            </DialogShell>}
            {dialog?.kind === 'editMember' && <DialogShell title={`${t('grievanceAdmin.editMember')}: ${employeeName(dialog.member.employee, locale)}`} help={t('grievanceAdmin.editMemberHelp')}>
                <AdminForm key={`editm-${dialog.member.id}`} initial={{ responsibility: dialog.member.responsibility, effective_from: dialog.member.effective_from ?? today(), effective_to: dialog.member.effective_to ?? '', appointment_reference: dialog.member.appointment_reference ?? '', signature: null }}
                    fields={[responsibilityField, { key: 'effective_from', type: 'date', required: true }, { key: 'effective_to', type: 'date' }, { key: 'appointment_reference' }, { key: 'signature', type: 'file', accept: 'image/png,image/jpeg,image/webp', help: t('grievanceAdmin.signatureReplaceHelp') }]}
                    url={route('grievances.units.members.update', [dialog.unit.id, dialog.member.id])} onSaved={close} onCancel={close} />
            </DialogShell>}
            {dialog?.kind === 'endMember' && <DialogShell title={`${t('grievanceAdmin.endMembership')}: ${employeeName(dialog.member.employee, locale)}`} help={t('grievanceAdmin.committeeBoard.endMemberHelp')}>
                <AdminForm key={`end-${dialog.member.id}`} initial={{ end_reason_code: '', reason: '', effective_to: today() }}
                    fields={[{ key: 'end_reason_code', label: u('endReasonField'), type: 'select', required: true, options: choices(options.end_reasons), enumGroup: 'unit_end_reason' },
                        { key: 'reason', label: u('endNoteField'), type: 'textarea', maxLength: 1000, required: false, visible: data => data.end_reason_code === 'other' }, { key: 'effective_to', type: 'date' }]}
                    url={route('grievances.units.members.end', [dialog.unit.id, dialog.member.id])} onSaved={close} onCancel={close} />
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

/** What each responsibility means, folded by default. */
function ResponsibilityGuide({ roleGroup }: { roleGroup: string }) {
    const { t } = useLocale();
    const label = useEnumLabel();
    const u = (key: string) => t(`grievanceAdmin.unitBoard.${key}`);
    return <details className="group rounded-card border border-(--app-border) bg-(--app-surface) text-sm">
        <summary className="flex cursor-pointer list-none items-center gap-2 px-5 py-3 font-medium text-(--app-foreground) [&::-webkit-details-marker]:hidden">
            <ChevronRight className="h-4 w-4 text-(--app-muted-foreground) transition-transform group-open:rotate-90" aria-hidden />{u('responsibilities')}
        </summary>
        <dl className="grid gap-4 border-t border-(--app-border) px-5 py-4 md:grid-cols-2">
            {(['head', 'expert'] as const).map(r => <div key={r}>
                <dt className="flex items-center gap-2 font-semibold text-(--app-foreground)"><span className={`h-2.5 w-2.5 rounded-full ${AVATAR[r]}`} aria-hidden />{label(roleGroup, r)}</dt>
                <dd className="mt-1 text-(--app-muted-foreground)">{u(`${r}Duty`)}</dd>
            </div>)}
        </dl>
    </details>;
}

function UnitCard({ unit, onOpen, bulk }: { unit: HandlerUnitItem; onOpen: (dialog: Dialog) => void; bulk: { open: boolean; n: number } | null }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const u = (key: string) => t(`grievanceAdmin.unitBoard.${key}`);
    const [open, setOpen] = useState(false);
    useEffect(() => { if (bulk) { setOpen(bulk.open); } }, [bulk]);
    const status = useForm({ status: unit.status === 'active' ? 'inactive' : 'active' });
    const head = unit.members.find(member => member.responsibility === 'head');
    const ready = unit.status === 'active' && unit.problems.length === 0;
    const title = `${nameOf(unit.unit, locale)} · ${label('unit_kind', unit.kind)}`;
    const bodyId = `unit-${unit.id}-body`;

    const marker = <span className={`inline-flex items-center gap-1 text-xs font-medium ${ready ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'}`}>
        {ready ? <CheckCircle className="h-3.5 w-3.5" aria-hidden /> : <AlertTriangle className="h-3.5 w-3.5" aria-hidden />}{ready ? u('ready') : u('needsAttention')}
    </span>;

    if (!open) {
        return <article className="overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface) shadow-xs">
            <h2 className="m-0">
                <button type="button" aria-expanded={false} aria-controls={bodyId} onClick={() => setOpen(true)}
                    className="flex w-full items-center gap-3 px-5 py-4 text-left hover:bg-(--app-surface-muted) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden><Building2 className="h-5 w-5" /></span>
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-base font-medium text-(--app-foreground)">{title}</span>
                        <span className="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-(--app-muted-foreground)">
                            <span>{nameOf(unit.organization, locale)}</span>
                            <span>{t('grievanceAdmin.committeeBoard.membersHeading')}: {unit.members.length}</span>
                            {head && <span>{u('head')}: {employeeName(head.employee, locale)}</span>}
                            {marker}
                        </span>
                    </span>
                    <span className="hidden text-xs font-medium text-primary sm:inline">{u('expand')}</span>
                    <ChevronRight className="h-5 w-5 shrink-0 text-(--app-muted-foreground)" aria-hidden />
                </button>
            </h2>
        </article>;
    }

    return <article id={bodyId} className="relative overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface) shadow-xs" aria-labelledby={`unit-${unit.id}`}>
        <header className="flex flex-col gap-4 p-5 lg:flex-row lg:items-start lg:justify-between">
            <div className="flex min-w-0 gap-4 pr-20 lg:pr-0">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden><Building2 className="h-6 w-6" /></span>
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 id={`unit-${unit.id}`} className="text-base font-semibold text-(--app-foreground)">{nameOf(unit.unit, locale)}</h2>
                        <GPill group="unit_kind" value={unit.kind} tone="info" />
                        <GPill group="committee_status" value={unit.status} />
                        {marker}
                    </div>
                    <p className="mt-1 text-sm text-(--app-muted-foreground)">{nameOf(unit.organization, locale)}</p>
                    {(locale === 'am' ? unit.description_am || unit.description_en : unit.description_en || unit.description_am) && <p className="mt-1 text-sm text-(--app-foreground)">{locale === 'am' ? unit.description_am || unit.description_en : unit.description_en || unit.description_am}</p>}
                </div>
            </div>
            <div className="absolute top-4 right-4 flex items-center gap-1 lg:static lg:shrink-0">
                <button type="button" aria-expanded={true} aria-controls={bodyId} onClick={() => setOpen(false)} aria-label={`${u('collapse')}: ${title}`} title={u('collapse')}
                    className="rounded-md p-1.5 text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                    <ChevronRight className="h-5 w-5 -rotate-90" aria-hidden />
                </button>
                <AppActionMenu label={`${t('common.actions')}: ${title}`} items={[
                    { label: u('registerMember'), icon: <UserPlusIcon className="h-4 w-4" />, onClick: () => onOpen({ kind: 'register', unit }), show: unit.can_manage_members && unit.status === 'active' },
                    { label: u('editDesignation'), icon: <PencilIcon className="h-4 w-4" />, onClick: () => onOpen({ kind: 'edit', unit }), show: unit.can_update },
                    { label: unit.status === 'active' ? u('deactivate') : u('reactivate'), icon: unit.status === 'active' ? <XCircle className="h-4 w-4" /> : <CheckCircle className="h-4 w-4" />, variant: unit.status === 'active' ? 'danger' : 'default',
                        onClick: () => status.patch(route('grievances.units.update', unit.id), { preserveScroll: true }), show: unit.can_update, separatorBefore: true, disabled: status.processing },
                ]} />
            </div>
        </header>

        {unit.problems.length > 0 && <ul className="space-y-1 border-t border-amber-200 bg-amber-50/60 px-5 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
            {unit.problems.map(problem => <li key={problem} className="flex items-start gap-2"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden /><span>{problem}</span></li>)}
        </ul>}

        <div className="border-t border-(--app-border) bg-(--app-surface-muted)/40 px-5 py-4">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('grievanceAdmin.committeeBoard.membersHeading')} · {unit.members.length}</h3>
            {unit.members.length === 0
                ? <p className="text-sm text-(--app-muted-foreground)">{u('noMembers')}</p>
                : <ul className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">{unit.members.map(member => {
                    const name = employeeName(member.employee, locale);
                    return <li key={member.id} className="flex min-w-0 items-start gap-3 rounded-lg border border-(--app-border) bg-(--app-surface) p-3">
                        <MemberAvatar name={name} photoUrl={member.employee?.photo_url} tier={member.responsibility === 'head' ? 'lead' : 'member'} />
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2"><span className="truncate font-medium text-(--app-foreground)">{name}</span><GPill group={unit.kind === 'team' ? 'unit_responsibility_team' : 'unit_responsibility_directorate'} value={member.responsibility} /></div>
                            {!member.placed && <p className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-red-700 dark:text-red-400"><AlertTriangle className="h-3.5 w-3.5" aria-hidden />{u('movedOut')}</p>}
                            {member.employee?.position && <p className="mt-0.5 truncate text-sm text-(--app-foreground)">{nameOf(member.employee.position, locale)}</p>}
                            {member.employee?.unit && <p className="mt-0.5 truncate text-xs text-(--app-muted-foreground)">{nameOf(member.employee.unit, locale)}</p>}
                            {/* The signature image itself is not shown on the list; only a missing one is flagged. */}
                            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-(--app-muted-foreground)">
                                {!member.signature && <span className="inline-flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400"><AlertTriangle className="h-3.5 w-3.5" aria-hidden />{t('grievanceAdmin.signatureMissing')}</span>}
                                <LocalizedDateDisplay value={member.effective_from} />
                            </div>
                        </div>
                        <div className="shrink-0">
                            <AppActionMenu label={`${t('common.actions')}: ${name}`} items={[
                                { label: t('common.edit'), icon: <PencilIcon className="h-4 w-4" />, onClick: () => onOpen({ kind: 'editMember', unit, member }), show: unit.can_manage_members },
                                { label: t('grievanceAdmin.endMembership'), icon: <XCircle className="h-4 w-4" />, onClick: () => onOpen({ kind: 'endMember', unit, member }), variant: 'danger', show: unit.can_manage_members, separatorBefore: true },
                            ]} />
                        </div>
                    </li>;
                })}</ul>}
        </div>
    </article>;
}
