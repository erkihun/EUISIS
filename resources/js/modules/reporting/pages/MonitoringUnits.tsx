import ConfirmationDialog from '@/Components/ConfirmationDialog';
import { AlertTriangle, CheckCircle, ChevronRight, PencilIcon, Plus, XCircle } from '@/Components/Icons';
import Modal from '@/Components/Modal';
import { Building2, UsersTwoIcon } from '@/Components/NavIcons';
import PageHeader from '@/Components/PageHeader';
import { AppActionMenu, AppMetricCard, FilterField, FilterPanel, ListPagination, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ActionErrors } from '@/modules/reporting/components/Notices';
import { OrganizationPicker } from '@/modules/reporting/components/Pickers';
import { badge, inputCls, labelCls, primaryBtn, secondaryBtn, useReporting } from '@/modules/reporting/lib/helpers';
import type { OrganizationRef, Paginated, UnitRef } from '@/modules/reporting/types';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type JSX } from 'react';

interface StaffMember {
    id: number;
    name: string;
    email: string | null;
    /** The most they may do with a received report. */
    role: 'approve' | 'review' | 'view' | 'none';
}

interface UnitRow {
    organization: OrganizationRef;
    unit: UnitRef;
    unit_active: boolean;
    designated_at: string | null;
    staff: StaffMember[];
}

interface Props {
    units: Paginated<UnitRow>;
    summary: { designated: number; institutions: number };
    filters: { search: string };
    can: { manage: boolean };
}

const ROLE_TONE = { approve: 'success', review: 'info', view: 'neutral', none: 'warning' } as const;

/**
 * The work unit that follows up reports in each institution. Laid out as the
 * Grievance module lays out its teams: figures, a search, then one line per
 * unit that opens to the people placed in it. An institution names a unit
 * already in its structure; nothing is created, and the people are whoever
 * HR has placed there.
 */
export default function MonitoringUnits({ units, summary, filters, can }: Props): JSX.Element {
    const { rt, name } = useReporting();
    const [search, setSearch] = useState(filters.search);
    const [dialog, setDialog] = useState<{ organization: OrganizationRef | null; unitId: string } | null>(null);
    const [removing, setRemoving] = useState<UnitRow | null>(null);
    const [bulk, setBulk] = useState<{ open: boolean; n: number } | null>(null);
    const filtered = filters.search !== '';
    const withoutUnit = Math.max(0, summary.institutions - summary.designated);
    const unready = units.data.filter((row) => !isReady(row)).length;
    const designate = can.manage && (
        <button type="button" className={cx(primaryBtn, 'gap-2')} onClick={() => setDialog({ organization: null, unitId: '' })}>
            <Plus className="h-4 w-4" aria-hidden />{rt('units.designate')}
        </button>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={rt('units.title')} description={rt('units.description')} actions={designate} />}>
            <Head title={rt('units.title')} />
            <ActionErrors />
            <div className="space-y-5 pb-4">
                <section aria-label={rt('units.title')} className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-3">
                    <AppMetricCard label={rt('units.designated')} value={summary.designated} detail={rt('units.designatedHelp')} variant="primary" icon={<Building2 className="h-5 w-5" />} />
                    <AppMetricCard label={rt('units.withoutUnit')} value={withoutUnit} detail={rt('units.withoutUnitHelp')} variant={withoutUnit > 0 ? 'warning' : 'success'} icon={<AlertTriangle className="h-5 w-5" />} />
                    <AppMetricCard label={rt('units.needAttention')} value={unready} detail={rt('units.needAttentionHelp')} variant={unready > 0 ? 'warning' : 'success'} icon={<UsersTwoIcon className="h-5 w-5" />} />
                </section>

                <details className="group rounded-card border border-(--app-border) bg-(--app-surface) text-sm">
                    <summary className="flex cursor-pointer list-none items-center gap-2 px-5 py-3 font-medium text-(--app-foreground) [&::-webkit-details-marker]:hidden">
                        <ChevronRight className="h-4 w-4 text-(--app-muted-foreground) transition-transform group-open:rotate-90" aria-hidden />{rt('units.guideTitle')}
                    </summary>
                    <dl className="grid gap-4 border-t border-(--app-border) px-5 py-4 md:grid-cols-3">
                        {(['who', 'sees', 'role'] as const).map((key) => (
                            <div key={key}>
                                <dt className="font-semibold text-(--app-foreground)">{rt(`units.guide.${key}Title`)}</dt>
                                <dd className="mt-1 text-(--app-muted-foreground)">{rt(`units.guide.${key}`)}</dd>
                            </div>
                        ))}
                    </dl>
                </details>

                <FilterPanel onSubmit={() => router.get(route('reporting.monitoring-units.index'), search.trim() === '' ? {} : { search: search.trim() }, { preserveState: true })} onReset={() => router.get(route('reporting.monitoring-units.index'))} hasActiveFilters={filtered}>
                    <FilterField label={rt('units.search')} wide><input type="search" className={inputCls} value={search} onChange={(event) => setSearch(event.target.value)} /></FilterField>
                </FilterPanel>

                {units.data.length === 0 ? (
                    <div className="flex flex-col items-center rounded-card border border-dashed border-(--app-border-strong) bg-(--app-surface) px-6 py-14 text-center">
                        <span className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"><Building2 className="h-6 w-6" /></span>
                        <h2 className="mt-4 text-base font-semibold text-(--app-foreground)">{rt(filtered ? 'units.noMatches' : 'units.emptyTitle')}</h2>
                        {!filtered && <p className="mt-1 max-w-md text-sm text-(--app-muted-foreground)">{rt('units.emptyHelp')}</p>}
                        {!filtered && can.manage && <div className="mt-5">{designate}</div>}
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div className="flex justify-end gap-2">
                            <button type="button" className="text-sm font-medium text-primary hover:underline" onClick={() => setBulk((previous) => ({ open: true, n: (previous?.n ?? 0) + 1 }))}>{rt('units.expandAll')}</button>
                            <span className="text-(--app-muted-foreground)" aria-hidden>·</span>
                            <button type="button" className="text-sm font-medium text-primary hover:underline" onClick={() => setBulk((previous) => ({ open: false, n: (previous?.n ?? 0) + 1 }))}>{rt('units.collapseAll')}</button>
                        </div>
                        {units.data.map((row) => (
                            <UnitCard key={row.organization.id} row={row} bulk={bulk} canManage={can.manage}
                                onChange={() => setDialog({ organization: row.organization, unitId: row.unit.id })} onRemove={() => setRemoving(row)} />
                        ))}
                        <ListPagination meta={units} />
                    </div>
                )}
            </div>

            <Modal show={dialog !== null} onClose={() => setDialog(null)} maxWidth="2xl">
                {dialog && <DesignateForm key={dialog.organization?.id ?? 'new'} initial={dialog} onDone={() => setDialog(null)} />}
            </Modal>

            <ConfirmationDialog
                open={removing !== null} onClose={() => setRemoving(null)}
                title={rt('settings.unitsRemoveTitle')}
                description={rt('settings.unitsRemoveDescription', { unit: removing ? name(removing.unit) : '', institution: removing ? name(removing.organization) : '' })}
                confirmLabel={rt('common.remove')}
                onConfirm={() => removing && router.delete(route('reporting.monitoring-units.destroy', removing.organization.id), { preserveScroll: true, onFinish: () => setRemoving(null) })}
            />
        </AuthenticatedLayout>
    );
}

/** Ready when the unit is still active and someone placed in it can review or approve. */
function isReady(row: UnitRow): boolean {
    return row.unit_active && row.staff.some((member) => member.role === 'approve' || member.role === 'review');
}

function DesignateForm({ initial, onDone }: { initial: { organization: OrganizationRef | null; unitId: string }; onDone: () => void }): JSX.Element {
    const { rt, name } = useReporting();
    const [organization, setOrganization] = useState<OrganizationRef | null>(initial.organization);
    const [units, setUnits] = useState<UnitRef[]>([]);
    const [unitId, setUnitId] = useState(initial.unitId);
    const [loaded, setLoaded] = useState(false);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setUnits([]);
        setLoaded(false);
        if (!organization) return;
        let current = true;
        window.axios.get(route('reporting.lookups.units'), { params: { organization_id: organization.id } })
            .then((response: { data: { items: UnitRef[] } }) => {
                if (!current) return;
                setUnits(response.data.items);
                setLoaded(true);
                // Keep the unit being changed selected; a different institution starts empty.
                setUnitId((chosen) => (response.data.items.some((unit) => unit.id === chosen) ? chosen : ''));
            })
            .catch(() => undefined);

        return () => { current = false; };
    }, [organization?.id]);

    const save = (event: FormEvent) => {
        event.preventDefault();
        if (!organization || unitId === '') return;
        setBusy(true);
        router.post(route('reporting.monitoring-units.store'), { organization_id: organization.id, organization_unit_id: unitId }, { preserveScroll: true, onSuccess: onDone, onFinish: () => setBusy(false) });
    };

    return (
        <form onSubmit={save} className="p-6">
            <h2 className="text-lg font-semibold text-(--app-foreground)">{rt(initial.organization ? 'units.change' : 'units.designate')}</h2>
            <p className="mt-1 text-sm text-(--app-muted-foreground)">{rt('units.designateHelp')}</p>
            <div className="mt-5 space-y-4">
                <OrganizationPicker label={rt('register.col.institution')} value={organization} onChange={setOrganization} />
                <div>
                    <label htmlFor="mu-unit" className={labelCls}>{rt('settings.unitsUnit')}</label>
                    <select id="mu-unit" className={inputCls} required value={unitId} disabled={organization === null} onChange={(event) => setUnitId(event.target.value)}>
                        <option value="">{rt(loaded && units.length === 0 ? 'settings.unitsNone' : 'settings.unitsChoose')}</option>
                        {units.map((unit) => <option key={unit.id} value={unit.id}>{unit.code ? `${unit.code} · ` : ''}{name(unit)}</option>)}
                    </select>
                </div>
            </div>
            <div className="mt-6 flex justify-end gap-2">
                <button type="button" className={secondaryBtn} onClick={onDone}>{rt('common.cancel')}</button>
                <button type="submit" className={primaryBtn} disabled={busy || organization === null || unitId === ''}>{rt('common.save')}</button>
            </div>
        </form>
    );
}

function UnitCard({ row, bulk, canManage, onChange, onRemove }: { row: UnitRow; bulk: { open: boolean; n: number } | null; canManage: boolean; onChange: () => void; onRemove: () => void }): JSX.Element {
    const { rt, name, date } = useReporting();
    const [open, setOpen] = useState(false);
    useEffect(() => { if (bulk) setOpen(bulk.open); }, [bulk]);
    const ready = isReady(row);
    const title = name(row.unit);
    const bodyId = `mu-${row.organization.id}-body`;
    const problems = [
        !row.unit_active && rt('units.problem.inactive'),
        row.staff.length === 0 && rt('units.problem.noStaff'),
        row.staff.length > 0 && !row.staff.some((member) => member.role === 'approve' || member.role === 'review') && rt('units.problem.noReviewer'),
    ].filter((problem): problem is string => typeof problem === 'string');
    const marker = (
        <span className={cx('inline-flex items-center gap-1 text-xs font-medium', ready ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400')}>
            {ready ? <CheckCircle className="h-3.5 w-3.5" aria-hidden /> : <AlertTriangle className="h-3.5 w-3.5" aria-hidden />}{rt(ready ? 'units.ready' : 'units.attention')}
        </span>
    );

    if (!open) {
        return (
            <article className="overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface) shadow-xs">
                <h2 className="m-0">
                    <button type="button" aria-expanded={false} aria-controls={bodyId} onClick={() => setOpen(true)}
                        className="flex w-full items-center gap-3 px-5 py-4 text-start hover:bg-(--app-surface-muted) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden><Building2 className="h-5 w-5" /></span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-base font-medium text-(--app-foreground)">{title}</span>
                            <span className="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-(--app-muted-foreground)">
                                <span>{name(row.organization)}</span>
                                <span>{rt('units.staffCount', { count: row.staff.length })}</span>
                                {marker}
                            </span>
                        </span>
                        <span className="hidden text-xs font-medium text-primary sm:inline">{rt('units.expand')}</span>
                        <ChevronRight className="h-5 w-5 shrink-0 text-(--app-muted-foreground)" aria-hidden />
                    </button>
                </h2>
            </article>
        );
    }

    return (
        <article id={bodyId} className="relative overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface) shadow-xs" aria-labelledby={`mu-${row.organization.id}`}>
            <header className="flex flex-col gap-4 p-5 lg:flex-row lg:items-start lg:justify-between">
                <div className="flex min-w-0 gap-4 pe-20 lg:pe-0">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden><Building2 className="h-6 w-6" /></span>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 id={`mu-${row.organization.id}`} className="text-base font-semibold text-(--app-foreground)">{title}</h2>
                            {row.unit.code && <span className={badge('neutral')}>{row.unit.code}</span>}
                            {marker}
                        </div>
                        <p className="mt-1 text-sm text-(--app-muted-foreground)">{row.organization.code} · {name(row.organization)}</p>
                        {row.designated_at && <p className="mt-1 text-xs text-(--app-muted-foreground)">{rt('units.designatedOn', { date: date(row.designated_at) })}</p>}
                    </div>
                </div>
                <div className="absolute end-4 top-4 flex items-center gap-1 lg:static lg:shrink-0">
                    <button type="button" aria-expanded={true} aria-controls={bodyId} onClick={() => setOpen(false)} aria-label={`${rt('units.collapse')}: ${title}`} title={rt('units.collapse')}
                        className="rounded-md p-1.5 text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                        <ChevronRight className="h-5 w-5 -rotate-90" aria-hidden />
                    </button>
                    <AppActionMenu label={`${rt('units.actions')}: ${title}`} items={[
                        { label: rt('units.change'), icon: <PencilIcon className="h-4 w-4" />, onClick: onChange, show: canManage },
                        { label: rt('common.remove'), icon: <XCircle className="h-4 w-4" />, onClick: onRemove, variant: 'danger', show: canManage, separatorBefore: true },
                    ]} />
                </div>
            </header>

            {problems.length > 0 && (
                <ul className="space-y-1 border-t border-amber-200 bg-amber-50/60 px-5 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                    {problems.map((problem) => <li key={problem} className="flex items-start gap-2"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden /><span>{problem}</span></li>)}
                </ul>
            )}

            <div className="border-t border-(--app-border) bg-(--app-surface-muted)/40 px-5 py-4">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{rt('units.staffHeading')} · {row.staff.length}</h3>
                {row.staff.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{rt('units.noStaff')}</p> : (
                    <ul className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">
                        {row.staff.map((member) => (
                            <li key={member.id} className="flex min-w-0 items-start gap-3 rounded-lg border border-(--app-border) bg-(--app-surface) p-3">
                                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary" aria-hidden>{member.name.trim().charAt(0).toUpperCase()}</span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium text-(--app-foreground)">{member.name}</p>
                                    {member.email && <p className="truncate text-xs text-(--app-muted-foreground)">{member.email}</p>}
                                    <span className={cx(badge(ROLE_TONE[member.role]), 'mt-1.5')}>{rt(`units.role.${member.role}`)}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </article>
    );
}
