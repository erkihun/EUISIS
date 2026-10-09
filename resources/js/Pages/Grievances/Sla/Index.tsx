import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AdminForm, choices, handlerFields, today, type AdminField } from '@/Components/grievances/AdminForm';
import { Section, Table, Empty, HandlerName, GPill, nameOf, pageCls, thCls, tdCls, primaryBtn, secondaryBtn, useEnumLabel } from '@/Components/grievances/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { SlaIndexProps, SlaProfileRow } from '@/types/grievances';

export default function Index({ profiles, options, can }: SlaIndexProps) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const [editing, setEditing] = useState<SlaProfileRow | 'new' | null>(null);
    const action = useForm({ deactivate: true });
    const row = typeof editing === 'object' ? editing : null;
    const fields: AdminField[] = [
        { key: 'name_en', required: true }, { key: 'name_am' },
        { key: 'purpose', type: 'select', required: true, options: choices(options.purposes), enumGroup: 'sla_purpose' },
        { key: 'organization_id', type: 'select', options: options.organizations.map(org => ({ value: org.id!, label: nameOf(org, locale) })) },
        ...handlerFields({ options, locale, title: t('grievanceAdmin.fields.handler_type'), initial: row?.handler }),
        { key: 'category_id', type: 'select', options: options.categories.map(category => ({ value: category.id!, label: nameOf(category, locale) })) },
        { key: 'resolution_days', type: 'number', min: 1, max: 365, required: true },
        { key: 'day_type', type: 'select', required: true, options: choices(options.day_types), enumGroup: 'day_type' },
        { key: 'start_point', type: 'select', required: true, options: choices(options.start_points), enumGroup: 'start_point' },
        { key: 'warning_percent', type: 'numbers', min: 1, max: 99 }, { key: 'warning_days_remaining', type: 'numbers', min: 1, max: 60 },
        { key: 'warning_due_today', type: 'boolean' }, { key: 'auto_escalate', type: 'boolean' },
        { key: 'priority', type: 'number', required: true, min: 1, max: 1000 }, { key: 'effective_from', type: 'date', required: true }, { key: 'effective_to', type: 'date' },
    ];
    return <AuthenticatedLayout header={<PageHeader title={t('grievanceAdmin.sla')} description={t('grievanceAdmin.slaHelp')} actions={can.manage && <button className={primaryBtn} onClick={() => setEditing('new')}>{t('grievanceAdmin.newSla')}</button>} />}>
        <Head title={t('grievanceAdmin.sla')} /><div className={pageCls}>
            {editing && can.manage && <Section title={t(row ? 'grievanceAdmin.editSla' : 'grievanceAdmin.newSla')} description={row ? t('grievanceAdmin.slaVersionHelp') : undefined}><AdminForm key={row?.id ?? 'new'} initial={{ name_en: row?.name_en ?? '', name_am: row?.name_am ?? '', purpose: row?.purpose ?? 'resolution', organization_id: row?.organization?.id ?? '', handler_type: row?.handler_type ?? '', handler_id: row?.handler_id ?? '', lookup_organization_id: '', category_id: row?.category?.id ?? '', resolution_days: row?.resolution_days ?? 15, day_type: row?.day_type ?? 'working_days', start_point: row?.start_point ?? 'on_receipt', warning_percent: row?.warning_thresholds?.percent ?? [75, 90], warning_days_remaining: row?.warning_thresholds?.days_remaining ?? [2], warning_due_today: row?.warning_thresholds?.due_today ?? true, auto_escalate: row?.auto_escalate ?? false, priority: row?.priority ?? 100, effective_from: row ? today() : today(), effective_to: row?.effective_to ?? '' }} fields={fields} url={row ? route('grievances.sla.update', row.id) : route('grievances.sla.store')} method={row ? 'patch' : 'post'} onCancel={() => setEditing(null)} onSaved={() => setEditing(null)} /></Section>}
            {Object.values(action.errors).map((error, index) => <p role="alert" key={index} className="text-sm text-red-600">{error}</p>)}
            <Section title={t('grievanceAdmin.sla')} flush>{profiles.length ? <Table head={<>{['name', 'purpose', 'handler_type', 'resolution_days', 'start_point', 'status', 'effective_from', 'actions'].map(key => <th className={thCls} key={key}>{t(`grievanceAdmin.fields.${key}`)}</th>)}</>}>{profiles.map(profile => <tr key={profile.id}><td className={tdCls}>{nameOf(profile, locale)}<p className="text-xs text-gray-500">{nameOf(profile.organization, locale)} · {nameOf(profile.category, locale)}</p></td><td className={tdCls}>{label('sla_purpose', profile.purpose)}</td><td className={tdCls}><HandlerName handler={profile.handler} /></td><td className={tdCls}>{profile.resolution_days} {label('day_type', profile.day_type)}</td><td className={tdCls}>{label('start_point', profile.start_point)}</td><td className={tdCls}><GPill group="committee_status" value={profile.is_active ? 'active' : 'inactive'} /></td><td className={tdCls}><LocalizedDateDisplay value={profile.effective_from} /><p className="text-xs"><LocalizedDateDisplay value={profile.effective_to} /></p></td><td className={tdCls}>{can.manage && <div className="flex gap-2"><button className={secondaryBtn} onClick={() => setEditing(profile)}>{t('grievances.common.edit')}</button>{profile.is_active && <button className={secondaryBtn} disabled={action.processing} onClick={() => action.patch(route('grievances.sla.update', profile.id), { preserveScroll: true })}>{t('grievanceAdmin.deactivate')}</button>}</div>}</td></tr>)}</Table> : <Empty>{t('grievanceAdmin.empty')}</Empty>}</Section>
        </div>
    </AuthenticatedLayout>;
}
