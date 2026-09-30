import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AdminForm, choices, handlerFields, today, type AdminField } from '@/Components/grievances/AdminForm';
import { Section, Table, Empty, Pager, HandlerName, GPill, nameOf, pageCls, thCls, tdCls, primaryBtn, secondaryBtn, inputCls, useEnumLabel } from '@/Components/grievances/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PageProps } from '@/types';
import type { RoutingIndexProps, RouteRow } from '@/types/grievances';

export default function Index({ routes, filters, options, can }: RoutingIndexProps) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const { auth } = usePage<PageProps>().props;
    const [editing, setEditing] = useState<RouteRow | 'new' | null>(null);
    const [query, setQuery] = useState({ movement_type: filters.movement_type ?? '', source_handler_type: filters.source_handler_type ?? '', target_handler_type: filters.target_handler_type ?? '', state: filters.state ?? '' });
    const action = useForm({ deactivate: true });
    const row = typeof editing === 'object' ? editing : null;
    const fields: AdminField[] = [
        ...handlerFields({ prefix: 'source_', title: t('grievanceAdmin.source'), options, locale, required: true, allowOrganization: true, initial: row?.source }),
        { key: 'include_descendants', type: 'boolean', visible: data => data.source_handler_type === 'organization' },
        ...handlerFields({ prefix: 'target_', title: t('grievanceAdmin.target'), options, locale, required: true, initial: row?.target }),
        { key: 'movement_type', type: 'select', required: true, options: choices(options.movement_types), enumGroup: 'movement_type', help: t('grievanceAdmin.initialSourceHelp') },
        { key: 'category_id', type: 'select', options: options.categories.map(value => ({ value: value.id!, label: nameOf(value, locale) })) },
        { key: 'sla_profile_id', type: 'select', options: options.sla_profiles.map(value => ({ value: value.id!, label: nameOf(value, locale) })) },
        { key: 'priority', type: 'number', required: true, min: 1, max: 1000 }, { key: 'effective_from', type: 'date', required: true }, { key: 'effective_to', type: 'date' }, { key: 'notes', type: 'textarea' },
    ];
    return <AuthenticatedLayout header={<PageHeader title={t('grievanceAdmin.routing')} description={t('grievanceAdmin.routingHelp')} actions={can.manage && <button className={primaryBtn} onClick={() => setEditing('new')}>{t('grievanceAdmin.newRoute')}</button>} />}>
        <Head title={t('grievanceAdmin.routing')} /><div className={pageCls}>
            {editing && can.manage && <Section title={t(row ? 'grievanceAdmin.editRoute' : 'grievanceAdmin.newRoute')}><AdminForm key={row?.id ?? 'new'} initial={{ source_handler_type: row?.source.type ?? 'organization', source_handler_id: row?.source.id ?? '', source_lookup_organization_id: '', target_handler_type: row?.target.type ?? 'committee', target_handler_id: row?.target.id ?? '', target_lookup_organization_id: '', include_descendants: row?.include_descendants ?? false, movement_type: row?.movement_type ?? 'initial_assignment', category_id: row?.category?.id ?? '', sla_profile_id: row?.sla_profile?.id ?? '', priority: row?.priority ?? 100, effective_from: row?.effective_from ?? today(), effective_to: row?.effective_to ?? '', notes: row?.notes ?? '' }} fields={fields} url={row ? route('grievances.routes.update', row.id) : route('grievances.routes.store')} method={row ? 'patch' : 'post'} onCancel={() => setEditing(null)} onSaved={() => setEditing(null)} /></Section>}
            <Section title={t('grievances.common.filter')}><form onSubmit={event => { event.preventDefault(); router.get(route('grievances.routes.index'), query, { preserveState: true }); }} className="flex flex-wrap gap-3">{(['movement_type', 'source_handler_type', 'target_handler_type', 'state'] as const).map(key => <label key={key} className="min-w-40 flex-1"><span className="block text-sm">{t(`grievanceAdmin.fields.${key}`)}</span><select className={inputCls} value={query[key]} onChange={event => setQuery({ ...query, [key]: event.target.value })}><option value="">{t('grievances.common.all')}</option>{(key === 'state' ? ['active', 'pending', 'inactive'] : key === 'movement_type' ? options.movement_types : options.handler_types).map(value => <option value={value} key={value}>{label(key === 'state' ? 'committee_status' : key === 'movement_type' ? key : 'handler_type', value)}</option>)}</select></label>)}<button className={primaryBtn}>{t('grievances.common.filter')}</button></form></Section>
            {Object.values(action.errors).map((error, index) => <p role="alert" key={index} className="text-sm text-red-600">{error}</p>)}
            <Section title={t('grievanceAdmin.routing')} flush>{routes.data.length ? <Table head={<>{['source', 'target', 'movement_type', 'priority', 'status', 'effective_from', 'actions'].map(key => <th key={key} className={thCls}>{t(`grievanceAdmin.fields.${key}`)}</th>)}</>}>{routes.data.map(item => <tr key={item.id}><td className={tdCls}><HandlerName handler={item.source} showType />{item.include_descendants && <p className="text-xs text-gray-500">{t('grievanceAdmin.fields.include_descendants')}</p>}</td><td className={tdCls}><HandlerName handler={item.target} showType />{item.cross_organization && <p className="text-xs text-amber-700">{t('grievanceAdmin.crossOrganization')}</p>}</td><td className={tdCls}>{label('movement_type', item.movement_type)}<p className="text-xs text-gray-500">{nameOf(item.category, locale)} · {nameOf(item.sla_profile, locale)}</p></td><td className={tdCls}>{item.priority}</td><td className={tdCls}><GPill group="committee_status" value={!item.is_active ? 'inactive' : item.approved_at ? 'active' : 'pending_approval'} /></td><td className={tdCls}><LocalizedDateDisplay value={item.effective_from} /><span className="block text-xs"><LocalizedDateDisplay value={item.effective_to} /></span></td><td className={tdCls}><div className="flex flex-wrap gap-2">{can.manage && <button className={secondaryBtn} onClick={() => setEditing(item)}>{t('grievances.common.edit')}</button>}{can.manage && item.is_active && <button disabled={action.processing} className={secondaryBtn} onClick={() => action.patch(route('grievances.routes.update', item.id), { preserveScroll: true })}>{t('grievanceAdmin.deactivate')}</button>}{can.approve && item.is_active && !item.approved_at && (auth.isSuperAdmin || item.created_by_id !== auth.user?.id) && <button disabled={action.processing} className={secondaryBtn} onClick={() => action.post(route('grievances.routes.approve', item.id), { preserveScroll: true })}>{t('grievanceAdmin.approve')}</button>}</div></td></tr>)}</Table> : <Empty>{t('grievanceAdmin.empty')}</Empty>}<Pager page={routes} /></Section>
        </div>
    </AuthenticatedLayout>;
}
