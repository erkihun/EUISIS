import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Modal from '@/Components/Modal';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AdminForm, choices, today } from '@/Components/grievances/AdminForm';
import { Section, Table, Empty, Problems, GPill, nameOf, employeeName, pageCls, thCls, tdCls, secondaryBtn, fmt } from '@/Components/grievances/ui';
import { useCommitteeRoutes } from '@/Components/grievances/committeeRoutes';
import { useLocale } from '@/hooks/useLocale';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { CommitteeShowProps } from '@/types/grievances';

import { TableTools } from '@/Components/ui';

export default function Show({ committee, members, problems, openCases, policy, roles, can }: CommitteeShowProps) {
    const { t, locale } = useLocale();
    const { cr } = useCommitteeRoutes();
    const [ending, setEnding] = useState<string | null>(null);
    const [editing, setEditing] = useState<string | null>(null);
    const action = useForm({ status: 'inactive' });
    const edited = members.find(member => member.id === editing) ?? null;
    return <AuthenticatedLayout header={<PageHeader title={nameOf(committee, locale)} description={nameOf(committee.organization, locale)} backHref={cr('index')} />}>
        <Head title={nameOf(committee, locale)} /><div className={pageCls}>
            <Section title={t('grievances.common.details')}><div className="flex flex-wrap items-center gap-4"><GPill group="committee_status" value={committee.status} /><span>{t('grievanceAdmin.fields.openCases')}: {openCases}</span><LocalizedDateDisplay value={committee.effective_from} /><span>–</span><LocalizedDateDisplay value={committee.effective_to} />{can.approve && <button className={secondaryBtn} disabled={action.processing} onClick={() => action.post(cr('approve', committee.id), { preserveScroll: true })}>{t('grievanceAdmin.approve')}</button>}{can.update && committee.status !== 'inactive' && <button className={secondaryBtn} disabled={action.processing} onClick={() => action.patch(cr('update', committee.id), { preserveScroll: true })}>{t('grievanceAdmin.deactivate')}</button>}</div>{Object.values(action.errors).map((error, index) => <p role="alert" key={index} className="mt-2 text-sm text-red-600">{error}</p>)}</Section>
            {problems.length > 0 && <Problems title={t('grievanceAdmin.membershipProblems')} problems={problems} />}
            <Section title={t('grievanceAdmin.committeeDetails')}><AdminForm initial={{ name_en: committee.name_en, name_am: committee.name_am ?? '', description_en: committee.description_en ?? '', description_am: committee.description_am ?? '', effective_to: committee.effective_to ?? '' }} fields={[
                { key: 'name_en', required: true }, { key: 'name_am' }, { key: 'effective_to', type: 'date' }, { key: 'description_en', type: 'textarea' }, { key: 'description_am', type: 'textarea' },
            ]} method="patch" url={cr('update', committee.id)} editable={can.update} /></Section>
            <Section title={t('grievanceAdmin.members')} description={fmt(t('grievanceAdmin.memberPolicy'), { min: policy.min, max: policy.max }) + (policy.require_writer ? ` ${t('grievanceAdmin.writerRequired')}` : '')}>
                {members.length ? <TableTools id="grievances-committees-show"><Table head={<>{['employee_id', 'role', 'effective_from', 'effective_to', 'status', 'signature', 'appointment_reference', 'actions'].map(key => <th key={key} className={thCls}>{t(`grievanceAdmin.fields.${key}`)}</th>)}</>}>{members.map(member => <tr key={member.id}><td className={tdCls}>{employeeName(member.employee, locale)}<span className="block text-xs text-gray-500">{member.employee?.employee_number}</span>{member.employee?.unit && <span className="block text-xs text-(--app-muted-foreground)">{nameOf(member.employee.unit, locale)}</span>}</td><td className={tdCls}><GPill group="committee_role" value={member.role} /></td><td className={tdCls}><LocalizedDateDisplay value={member.effective_from} /></td><td className={tdCls}><LocalizedDateDisplay value={member.effective_to} /></td><td className={tdCls}><GPill group="status" value={member.status} /></td><td className={tdCls}>{member.signature_url ? <img src={member.signature_url} alt={`${t('grievanceAdmin.signatureOnFile')}: ${employeeName(member.employee, locale)}`} className="h-10 max-w-32 rounded-sm border border-(--app-border) bg-white object-contain p-1" /> : <span className="text-xs text-amber-700 dark:text-amber-400">{t('grievanceAdmin.signatureMissing')}</span>}</td><td className={tdCls}>{member.appointment_reference ?? '—'}{member.end_reason && <p className="text-xs">{member.end_reason}</p>}</td><td className={tdCls}>{can.manage_members && member.is_active && <div className="flex flex-wrap gap-2"><button type="button" className={secondaryBtn} onClick={() => setEditing(member.id)}>{t('grievanceAdmin.editMember')}</button><button type="button" className={secondaryBtn} onClick={() => setEnding(ending === member.id ? null : member.id)}>{t('grievanceAdmin.endMembership')}</button></div>}</td></tr>)}</Table></TableTools> : <Empty>{t('grievanceAdmin.empty')}</Empty>}
                {ending && can.manage_members && <div className="mt-4 border-t pt-4"><h3 className="mb-3 font-medium">{t('grievanceAdmin.endMembership')}: {employeeName(members.find(member => member.id === ending)?.employee, locale)}</h3><AdminForm key={ending} initial={{ reason: '', effective_to: today() }} fields={[{ key: 'reason', type: 'textarea', required: true, maxLength: 1000 }, { key: 'effective_to', type: 'date' }]} url={cr('members.end', [committee.id, ending])} onSaved={() => setEnding(null)} onCancel={() => setEnding(null)} /></div>}
            </Section>
            {can.manage_members && <Section title={t('grievanceAdmin.addMember')} description={t('grievanceAdmin.addMemberHelp')}><AdminForm initial={{ employee_id: '', role: 'member', selection_source: '', term_number: '1', effective_from: today(), effective_to: '', appointment_reference: '', signature: null, organization_id: committee.organization?.id ?? '' }} fields={[{ key: 'employee_id', type: 'lookup', lookup: 'employees', organizationKey: 'organization_id', lookupParams: { exclude_committee_id: committee.id }, required: true }, { key: 'role', type: 'select', options: choices(roles), enumGroup: 'committee_role', required: true }, { key: 'selection_source', type: 'select', options: choices(['appointed', 'elected', 'hr_representative']), enumGroup: 'committee_source' }, { key: 'term_number', type: 'select', options: choices(['1', '2']) }, { key: 'effective_from', type: 'date', required: true }, { key: 'effective_to', type: 'date' }, { key: 'appointment_reference' }, { key: 'signature', type: 'file', accept: 'image/png,image/jpeg,image/webp', required: true, help: t('grievanceAdmin.signatureHelp') }]} url={cr('members.store', committee.id)} /></Section>}
        </div>
        {/* Edit the current term of a member in a popup; the history row is kept. */}
        <Modal show={edited !== null} onClose={() => setEditing(null)} maxWidth="2xl">
            {edited && <div className="p-6">
                <h2 className="text-lg font-semibold text-(--app-foreground)">{t('grievanceAdmin.editMember')}: {employeeName(edited.employee, locale)}</h2>
                <p className="mt-1 mb-4 text-sm text-(--app-muted-foreground)">{t('grievanceAdmin.editMemberHelp')}</p>
                <AdminForm key={edited.id} initial={{ role: edited.role, selection_source: edited.selection_source ?? '', term_number: String(edited.term_number ?? 1), effective_from: edited.effective_from ?? today(), effective_to: edited.effective_to ?? '', appointment_reference: edited.appointment_reference ?? '', signature: null }}
                    fields={[{ key: 'role', type: 'select', options: choices(roles), enumGroup: 'committee_role', required: true }, { key: 'selection_source', type: 'select', options: choices(['appointed', 'elected', 'hr_representative']), enumGroup: 'committee_source' }, { key: 'term_number', type: 'select', options: choices(['1', '2']) }, { key: 'effective_from', type: 'date', required: true }, { key: 'effective_to', type: 'date' }, { key: 'appointment_reference' }, { key: 'signature', type: 'file', accept: 'image/png,image/jpeg,image/webp', help: t('grievanceAdmin.signatureReplaceHelp') }]}
                    url={cr('members.update', [committee.id, edited.id])} onSaved={() => setEditing(null)} onCancel={() => setEditing(null)} />
            </div>}
        </Modal>
    </AuthenticatedLayout>;
}
