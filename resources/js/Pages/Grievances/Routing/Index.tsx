import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Modal from '@/Components/Modal';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AdminForm, choices, handlerFields, today, type AdminField } from '@/Components/grievances/AdminForm';
import { Section, Table, Empty, Pager, HandlerName, GPill, nameOf, fmt, pageCls, thCls, tdCls, primaryBtn, inputCls, useEnumLabel } from '@/Components/grievances/ui';
import { AppActionMenu, AppMetricCard, FilterField, FilterPanel } from '@/Components/ui';
import { AlertTriangle, CheckCircle, ChevronRight, ClockIcon, PencilIcon, Plus, XCircle } from '@/Components/Icons';
import { SignpostIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PageProps } from '@/types';
import type { HandlerRef, RoutingIndexProps, RouteRow } from '@/types/grievances';

/**
 * Grievance routing. Each route is an explicit move between handlers. A Team or
 * Directorate target is picked from the designated units (ADR 0029), and every
 * row says whether that unit can take cases (designated, head named).
 */
/** In the form, a unit is a Grievance Team or the Grievance Directorate (both organization units underneath). */
function formType(type: string, kind: string | null | undefined): string {
    return type === 'organization_unit' && kind ? kind : type;
}

/** Shown with its Team / Directorate label instead of the generic unit label. */
function withKind(handler: HandlerRef, kind: string | null | undefined): HandlerRef {
    return handler.type === 'organization_unit' && kind ? { ...handler, type: kind as HandlerRef['type'] } : handler;
}

export default function Index({ routes, filters, options, summary, can }: RoutingIndexProps) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const r = (key: string) => t(`grievanceAdmin.routeBoard.${key}`);
    const { auth } = usePage<PageProps>().props;
    const [editing, setEditing] = useState<RouteRow | 'new' | null>(null);
    const [query, setQuery] = useState({ movement_type: filters.movement_type ?? '', source_handler_type: filters.source_handler_type ?? '', target_handler_type: filters.target_handler_type ?? '', state: filters.state ?? '' });
    const action = useForm({ deactivate: true });
    const row = typeof editing === 'object' ? editing : null;
    const close = () => setEditing(null);
    const fields: AdminField[] = [
        ...handlerFields({ organizationLabel: t('common.organization'), prefix: 'source_', title: t('grievanceAdmin.source'), options, locale, required: true, allowOrganization: true, initial: row?.source, allLabels: { organization: r('allOrganizations'), committee: r('allCommittees'), team: r('allTeams') } }),
        { key: 'include_descendants', type: 'boolean', visible: data => data.source_handler_type === 'organization' },
        ...handlerFields({ organizationLabel: t('common.organization'), prefix: 'target_', title: t('grievanceAdmin.target'), options, locale, required: true, initial: row?.target, allLabels: { committee: r('ownCommittee'), team: r('nearestTeam') } }),
        { key: 'movement_type', type: 'select', required: true, options: choices(options.movement_types), enumGroup: 'movement_type', help: t('grievanceAdmin.initialSourceHelp') },
        { key: 'category_id', type: 'select', options: options.categories.map(value => ({ value: value.id!, label: nameOf(value, locale) })) },
        { key: 'sla_profile_id', type: 'select', options: options.sla_profiles.map(value => ({ value: value.id!, label: nameOf(value, locale) })) },
        { key: 'priority', type: 'number', required: true, min: 1, max: 1000 }, { key: 'effective_from', type: 'date', required: true }, { key: 'effective_to', type: 'date' }, { key: 'notes', type: 'textarea' },
    ];
    // Filter by Grievance Team and Grievance Directorate separately.
    const filterTypes = options.handler_types.flatMap(type => (type === 'organization_unit' ? ['team', 'directorate'] : [type]));
    const state = (item: RouteRow) => !item.is_active ? 'inactive' : item.approved_at ? 'active' : 'pending_approval';

    return <AuthenticatedLayout header={<PageHeader title={t('grievanceAdmin.routing')} description={t('grievanceAdmin.routingHelp')}
        actions={can.manage && <button type="button" className={`${primaryBtn} gap-2`} onClick={() => setEditing('new')}><Plus className="h-4 w-4" aria-hidden />{t('grievanceAdmin.newRoute')}</button>} />}>
        <Head title={t('grievanceAdmin.routing')} />
        <div className={pageCls}>
            <section aria-label={t('grievanceAdmin.routing')} className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <AppMetricCard label={r('active')} value={summary.active} detail={r('activeHelp')} variant="success" icon={<CheckCircle className="h-5 w-5" />} />
                <AppMetricCard label={r('pending')} value={summary.pending} detail={r('pendingHelp')} variant="warning" icon={<ClockIcon className="h-5 w-5" />} />
                <AppMetricCard label={r('inactive')} value={summary.inactive} detail={r('inactiveHelp')} variant="neutral" icon={<SignpostIcon className="h-5 w-5" />} />
                <AppMetricCard label={r('gaps')} value={summary.unit_gaps} detail={r('gapsHelp')} variant={summary.unit_gaps > 0 ? 'danger' : 'neutral'} icon={<AlertTriangle className="h-5 w-5" />} />
            </section>

            {summary.unit_gaps > 0 && <div role="status" className="flex flex-col gap-3 rounded-card border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 sm:flex-row sm:items-center dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                <AlertTriangle className="h-5 w-5 shrink-0" aria-hidden /><p className="flex-1">{r('gapsAlert')}</p>
                <Link href={route('grievances.teams.index')} className="inline-flex items-center gap-1 font-semibold underline-offset-2 hover:underline">{r('openUnits')}<ChevronRight className="h-4 w-4" aria-hidden /></Link>
            </div>}

            <FilterPanel onSubmit={() => router.get(route('grievances.routes.index'), query, { preserveState: true })} onReset={() => router.get(route('grievances.routes.index'))} hasActiveFilters={Object.values(filters).some(Boolean)}>
                {(['movement_type', 'source_handler_type', 'target_handler_type', 'state'] as const).map(key => <FilterField key={key} label={t(`grievanceAdmin.fields.${key}`)}><select className={inputCls} value={query[key]} onChange={event => setQuery({ ...query, [key]: event.target.value })}><option value="">{t('grievances.common.all')}</option>{(key === 'state' ? ['active', 'pending', 'inactive'] : key === 'movement_type' ? options.movement_types : filterTypes).map(value => <option value={value} key={value}>{label(key === 'state' ? 'committee_status' : key === 'movement_type' ? key : 'handler_type', value === 'pending' ? 'pending_approval' : value)}</option>)}</select></FilterField>)}
            </FilterPanel>
            {Object.values(action.errors).map((error, index) => <p role="alert" key={index} className="text-sm text-red-600">{error}</p>)}

            <Section title={t('grievanceAdmin.routing')} flush>{routes.data.length ? <Table head={<>{['source', 'target', 'movement_type', 'priority', 'status', 'effective_from', 'actions'].map(key => <th key={key} className={thCls}>{t(`grievanceAdmin.fields.${key}`)}</th>)}</>}>{routes.data.map(item => <tr key={item.id} className={item.is_active ? '' : 'opacity-60'}>
                <td className={tdCls}>{item.source_all ? <span className="font-medium text-(--app-foreground)">{item.source_kind === "team" ? r("allTeams") : item.source_kind === "directorate" ? r("theDirectorate") : item.source.type === "organization" ? r("allOrganizations") : r("allCommittees")}<span className="block text-xs font-normal text-(--app-muted-foreground)">{label("handler_type", item.source_kind ?? item.source.type)}</span></span> : <HandlerName handler={withKind(item.source, item.source_kind)} showType />}{item.include_descendants && <p className="text-xs text-(--app-muted-foreground)">{t('grievanceAdmin.fields.include_descendants')}</p>}</td>
                <td className={tdCls}>
                    <div className="flex items-start gap-2"><ChevronRight className="mt-0.5 h-4 w-4 shrink-0 text-(--app-muted-foreground)" aria-hidden /><div className="min-w-0">
                        {item.target_all ? <span className="font-medium text-(--app-foreground)">{item.target_kind === 'team' ? r('nearestTeam') : r('ownCommittee')}<span className="block text-xs font-normal text-(--app-muted-foreground)">{label('handler_type', item.target_kind ?? 'committee')}</span></span> : <HandlerName handler={withKind(item.target, item.target_kind)} showType />}
                        {item.target_unit && <UnitReadiness unit={item.target_unit} />}
                        {item.cross_organization && <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">{t('grievanceAdmin.crossOrganization')}</p>}
                    </div></div>
                </td>
                <td className={tdCls}><span className="font-medium text-(--app-foreground)">{label('movement_type', item.movement_type)}</span>{(item.category || item.sla_profile) && <p className="text-xs text-(--app-muted-foreground)">{[nameOf(item.category, locale), nameOf(item.sla_profile, locale)].filter(v => v && v !== '—').join(' · ')}</p>}</td>
                <td className={tdCls}>{item.priority}</td>
                <td className={tdCls}><GPill group="committee_status" value={state(item)} /></td>
                <td className={tdCls}><LocalizedDateDisplay value={item.effective_from} />{item.effective_to && <span className="block text-xs"><LocalizedDateDisplay value={item.effective_to} /></span>}</td>
                <td className={tdCls}><AppActionMenu label={`${t('common.actions')}: ${nameOf(item.source, locale)} → ${nameOf(item.target, locale)}`} items={[
                    { label: r('approve'), icon: <CheckCircle className="h-4 w-4" />, onClick: () => action.post(route('grievances.routes.approve', item.id), { preserveScroll: true }), show: can.approve && item.is_active && !item.approved_at && (Boolean(auth.isSuperAdmin) || item.created_by_id !== auth.user?.id), disabled: action.processing },
                    { label: r('edit'), icon: <PencilIcon className="h-4 w-4" />, onClick: () => setEditing(item), show: can.manage },
                    { label: r('deactivate'), icon: <XCircle className="h-4 w-4" />, variant: 'danger', onClick: () => action.patch(route('grievances.routes.update', item.id), { preserveScroll: true }), show: can.manage && item.is_active, separatorBefore: true, disabled: action.processing },
                ]} /></td>
            </tr>)}</Table> : <Empty>{t('grievanceAdmin.empty')}</Empty>}<Pager page={routes} /></Section>
        </div>

        <Modal show={editing !== null && can.manage} onClose={close} maxWidth="2xl">
            {editing && <div className="p-6">
                <h2 className="text-lg font-semibold text-(--app-foreground)">{t(row ? 'grievanceAdmin.editRoute' : 'grievanceAdmin.newRoute')}</h2>
                <p className="mt-1 text-sm text-(--app-muted-foreground)">{r('unitsOnly')}</p>
                <div className="mt-5">
                    <AdminForm key={row?.id ?? 'new'} initial={{ source_handler_type: row ? formType(row.source.type, row.source_kind) : 'organization', source_handler_id: row ? (row.source_all ? 'all' : row.source.id ?? '') : '', source_lookup_organization_id: '', target_handler_type: row ? formType(row.target.type, row.target_kind) : 'committee', target_handler_id: row ? (row.target_all ? 'all' : row.target.id ?? '') : '', target_lookup_organization_id: '', include_descendants: row?.include_descendants ?? false, movement_type: row?.movement_type ?? 'initial_assignment', category_id: row?.category?.id ?? '', sla_profile_id: row?.sla_profile?.id ?? '', priority: row?.priority ?? 100, effective_from: row?.effective_from ?? today(), effective_to: row?.effective_to ?? '', notes: row?.notes ?? '' }}
                        fields={fields} url={row ? route('grievances.routes.update', row.id) : route('grievances.routes.store')} method={row ? 'patch' : 'post'} onCancel={close} onSaved={close} />
                </div>
            </div>}
        </Modal>
    </AuthenticatedLayout>;
}

/** Whether a Team/Directorate target can take cases: text plus icon, never colour alone. */
function UnitReadiness({ unit }: { unit: NonNullable<RouteRow['target_unit']> }) {
    const { t } = useLocale();
    const label = useEnumLabel();
    const r = (key: string) => t(`grievanceAdmin.routeBoard.${key}`);
    if (!unit.designated) {
        return <p className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-red-700 dark:text-red-400"><XCircle className="h-3.5 w-3.5" aria-hidden />{r('notDesignated')}</p>;
    }
    return <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
        <GPill group="unit_kind" value={unit.kind} tone="info" />
        {unit.head
            ? <span className="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"><CheckCircle className="h-3.5 w-3.5" aria-hidden />{fmt(r('headIs'), { name: unit.head })}</span>
            : <span className="inline-flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400"><AlertTriangle className="h-3.5 w-3.5" aria-hidden />{r('noHead')}</span>}
        <span className="text-(--app-muted-foreground)">{fmt(r('members'), { count: unit.members })}</span>
        <span className="sr-only">{label('unit_kind', unit.kind)}</span>
    </p>;
}
