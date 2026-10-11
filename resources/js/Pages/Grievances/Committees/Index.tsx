import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AdminForm, choices, today, type AdminField } from '@/Components/grievances/AdminForm';
import { Section, Table, Empty, Pager, GPill, nameOf, pageCls, thCls, tdCls, inputCls, primaryBtn, linkBtn, useEnumLabel } from '@/Components/grievances/ui';
import { FilterField, FilterPanel } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { CommitteesIndexProps } from '@/types/grievances';

export default function Index({ committees, filters, options, can }: CommitteesIndexProps) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const [query, setQuery] = useState({ search: filters.search ?? '', organization_id: filters.organization_id ?? '', committee_type: filters.committee_type ?? '', status: filters.status ?? '' });
    const [create, setCreate] = useState(false);
    const organizations = options.organizations.map(org => ({ value: org.id!, label: nameOf(org, locale) }));
    const fields: AdminField[] = [
        { key: 'name_en', required: true }, { key: 'name_am' },
        { key: 'organization_id', type: 'select', required: true, options: organizations, clearOnChange: ['organization_unit_id'] },
        { key: 'organization_unit_id', type: 'lookup', lookup: 'units', organizationKey: 'organization_id' },
        { key: 'committee_type', type: 'select', required: true, options: choices(options.types), enumGroup: 'committee_type' },
        { key: 'effective_from', type: 'date' }, { key: 'effective_to', type: 'date' },
        { key: 'description_en', type: 'textarea' }, { key: 'description_am', type: 'textarea' },
    ];
    return <AuthenticatedLayout header={<PageHeader title={t('grievanceAdmin.committees')} description={t('grievanceAdmin.committeesHelp')} actions={can.create && <button className={primaryBtn} onClick={() => setCreate(!create)}>{t('grievanceAdmin.newCommittee')}</button>} />}>
        <Head title={t('grievanceAdmin.committees')} /><div className={pageCls}>
            {create && can.create && <Section title={t('grievanceAdmin.newCommittee')}><AdminForm initial={{ name_en: '', name_am: '', organization_id: '', organization_unit_id: '', committee_type: '', effective_from: today(), effective_to: '', description_en: '', description_am: '' }} fields={fields} url={route('grievances.committees.store')} onCancel={() => setCreate(false)} /></Section>}
            <FilterPanel onSubmit={() => router.get(route('grievances.committees.index'), query, { preserveState: true })} onReset={() => router.get(route('grievances.committees.index'))} hasActiveFilters={Object.values(filters).some(Boolean)}>
                <FilterField label={t('grievances.common.search')} wide><input type="search" className={inputCls} value={query.search} onChange={event => setQuery({ ...query, search: event.target.value })} /></FilterField>
                {(['organization_id', 'committee_type', 'status'] as const).map(key => <FilterField key={key} label={t(`grievanceAdmin.fields.${key}`)}><select className={inputCls} value={query[key]} onChange={event => setQuery({ ...query, [key]: event.target.value })}><option value="">{t('grievances.common.all')}</option>{(key === 'organization_id' ? organizations : choices(key === 'status' ? options.statuses : options.types)).map(option => <option key={option.value} value={option.value}>{key === 'organization_id' ? option.label : label(key === 'status' ? 'committee_status' : key, option.value)}</option>)}</select></FilterField>)}
            </FilterPanel>
            <Section title={t('grievanceAdmin.committees')} flush>{committees.data.length ? <Table head={<>{['name', 'organization_id', 'committee_type', 'status', 'members', 'openCases', 'effective_from'].map(key => <th className={thCls} key={key}>{t(`grievanceAdmin.fields.${key}`)}</th>)}</>}>{committees.data.map(committee => <tr key={committee.id}>
                <td className={tdCls}><Link className={linkBtn} href={route('grievances.committees.show', committee.id)}>{nameOf(committee, locale)}</Link>{committee.problems.length > 0 && <ul className="mt-1 text-xs text-amber-700">{committee.problems.map(problem => <li key={problem}>{problem}</li>)}</ul>}</td><td className={tdCls}>{nameOf(committee.organization, locale)}</td><td className={tdCls}>{label('committee_type', committee.committee_type)}</td><td className={tdCls}><GPill group="committee_status" value={committee.status} /></td><td className={tdCls}>{committee.active_members_count}</td><td className={tdCls}>{committee.open_cases_count}</td><td className={tdCls}><LocalizedDateDisplay value={committee.effective_from} /></td>
            </tr>)}</Table> : <Empty>{t('grievanceAdmin.empty')}</Empty>}<Pager page={committees} /></Section>
        </div>
    </AuthenticatedLayout>;
}
