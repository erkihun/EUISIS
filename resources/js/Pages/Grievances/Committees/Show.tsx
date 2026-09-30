import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AdminForm, choices, today } from '@/Components/grievances/AdminForm';
import { Section, Table, Empty, Problems, GPill, nameOf, employeeName, pageCls, thCls, tdCls, secondaryBtn, fmt } from '@/Components/grievances/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { CommitteeShowProps } from '@/types/grievances';

export default function Show({ committee, members, problems, openCases, policy, roles, units, can }: CommitteeShowProps) {
    const { t, locale } = useLocale();
    const [ending, setEnding] = useState<string | null>(null);
    const action = useForm({ status: 'inactive' });
    return <AuthenticatedLayout header={<PageHeader title={nameOf(committee, locale)} description={nameOf(committee.organization, locale)} backHref={route('grievances.committees.index')} />}>
        <Head title={nameOf(committee, locale)} /><div className={pageCls}>
            <Section title={t('grievances.common.details')}><div className="flex flex-wrap items-center gap-4"><GPill group="committee_status" value={committee.status} /><span>{t('grievanceAdmin.fields.openCases')}: {openCases}</span><LocalizedDateDisplay value={committee.effective_from} /><span>–</span><LocalizedDateDisplay value={committee.effective_to} />{can.approve && <button className={secondaryBtn} disabled={action.processing} onClick={() => action.post(route('grievances.committees.approve', committee.id), { preserveScroll: true })}>{t('grievanceAdmin.approve')}</button>}{can.update && committee.status !== 'inactive' && <button className={secondaryBtn} disabled={action.processing} onClick={() => action.patch(route('grievances.committees.update', committee.id), { preserveScroll: true })}>{t('grievanceAdmin.deactivate')}</button>}</div>{Object.values(action.errors).map((error, index) => <p role="alert" key={index} className="mt-2 text-sm text-red-600">{error}</p>)}</Section>
            {problems.length > 0 && <Problems title={t('grievanceAdmin.membershipProblems')} problems={problems} />}
            <Section title={t('grievanceAdmin.committeeDetails')}><AdminForm initial={{ name_en: committee.name_en, name_am: committee.name_am ?? '', description_en: committee.description_en ?? '', description_am: committee.description_am ?? '', organization_unit_id: committee.organization_unit?.id ?? '', effective_to: committee.effective_to ?? '' }} fields={[
                { key: 'name_en', required: true }, { key: 'name_am' }, { key: 'organization_unit_id', type: 'select', options: units.map(unit => ({ value: unit.id!, label: nameOf(unit, locale) })) }, { key: 'effective_to', type: 'date' }, { key: 'description_en', type: 'textarea' }, { key: 'description_am', type: 'textarea' },
            ]} method="patch" url={route('grievances.committees.update', committee.id)} editable={can.update} /></Section>
            <Section title={t('grievanceAdmin.members')} description={fmt(t('grievanceAdmin.memberPolicy'), { min: policy.min, max: policy.max }) + (policy.require_writer ? ` ${t('grievanceAdmin.writerRequired')}` : '')}>
                {members.length ? <Table head={<>{['employee_id', 'role', 'effective_from', 'effective_to', 'status', 'appointment_reference', 'actions'].map(key => <th key={key} className={thCls}>{t(`grievanceAdmin.fields.${key}`)}</th>)}</>}>{members.map(member => <tr key={member.id}><td className={tdCls}>{employeeName(member.employee, locale)}<span className="block text-xs text-gray-500">{member.employee?.employee_number}</span></td><td className={tdCls}><GPill group="committee_role" value={member.role} /></td><td className={tdCls}><LocalizedDateDisplay value={member.effective_from} /></td><td className={tdCls}><LocalizedDateDisplay value={member.effective_to} /></td><td className={tdCls}><GPill group="status" value={member.status} /></td><td className={tdCls}>{member.appointment_reference ?? '—'}{member.end_reason && <p className="text-xs">{member.end_reason}</p>}</td><td className={tdCls}>{can.manage_members && member.is_active && <button className={secondaryBtn} onClick={() => setEnding(ending === member.id ? null : member.id)}>{t('grievanceAdmin.endMembership')}</button>}</td></tr>)}</Table> : <Empty>{t('grievanceAdmin.empty')}</Empty>}
                {ending && can.manage_members && <div className="mt-4 border-t pt-4"><h3 className="mb-3 font-medium">{t('grievanceAdmin.endMembership')}: {employeeName(members.find(member => member.id === ending)?.employee, locale)}</h3><AdminForm key={ending} initial={{ reason: '', effective_to: today() }} fields={[{ key: 'reason', type: 'textarea', required: true, maxLength: 1000 }, { key: 'effective_to', type: 'date' }]} url={route('grievances.committees.members.end', [committee.id, ending])} onSaved={() => setEnding(null)} onCancel={() => setEnding(null)} /></div>}
            </Section>
            {can.manage_members && <Section title={t('grievanceAdmin.addMember')}><AdminForm initial={{ employee_id: '', role: 'member', effective_from: today(), appointment_reference: '', organization_id: committee.organization?.id ?? '' }} fields={[{ key: 'employee_id', type: 'lookup', lookup: 'employees', organizationKey: 'organization_id', required: true }, { key: 'role', type: 'select', options: choices(roles), enumGroup: 'committee_role', required: true }, { key: 'effective_from', type: 'date', required: true }, { key: 'appointment_reference' }]} url={route('grievances.committees.members.store', committee.id)} /></Section>}
        </div>
    </AuthenticatedLayout>;
}
