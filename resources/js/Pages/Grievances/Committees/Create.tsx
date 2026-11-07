import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { AdminForm, today, type AdminField } from '@/Components/grievances/AdminForm';
import { Section, nameOf, pageCls } from '@/Components/grievances/ui';
import { useCommitteeRoutes } from '@/Components/grievances/committeeRoutes';
import { useLocale } from '@/hooks/useLocale';
import { Head, router } from '@inertiajs/react';
import type { CommitteeCreateProps } from '@/types/grievances';

/** Registers a committee. Saving lands on the new committee's page, where members are added. */
export default function Create({ options }: CommitteeCreateProps) {
    const { t, locale } = useLocale();
    const { cr, discipline } = useCommitteeRoutes();
    const organizations = options.organizations.map(org => ({ value: org.id!, label: nameOf(org, locale) }));
    const fields: AdminField[] = [
        { key: 'name_en', required: true }, { key: 'name_am' },
        // A committee belongs to the institution, not to one work unit: its
        // members are searched among the institution's employees from any unit.
        { key: 'organization_id', type: 'select', required: true, options: organizations, clearOnChange: ['organization_unit_id'] },
        // Optional: two committees in one institution (e.g. a school's teachers and its other staff) split by work unit.
        { key: 'organization_unit_id', label: t('grievanceAdmin.committeeUnit'), type: 'lookup', lookup: 'units', organizationKey: 'organization_id', emptyLabel: t('grievanceAdmin.committeeBoard.allStaff'), help: t('grievanceAdmin.committeeUnitHelp') },
        { key: 'effective_from', type: 'date' }, { key: 'effective_to', type: 'date' },
        { key: 'description_en', type: 'textarea' }, { key: 'description_am', type: 'textarea' },
    ];
    return <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('grievanceAdmin.newCommittee')} description={t(discipline ? 'grievanceAdmin.disciplineCommitteesHelp' : 'grievanceAdmin.committeesHelp')} backHref={cr('index')} />}>
        <Head title={t('grievanceAdmin.newCommittee')} /><div className={pageCls}>
            <Section title={t('grievanceAdmin.newCommittee')}>
                <AdminForm initial={{ name_en: '', name_am: '', organization_id: '', organization_unit_id: '', effective_from: today(), effective_to: '', description_en: '', description_am: '' }} fields={fields}
                    url={cr('store')} onCancel={() => router.visit(cr('index'))} />
            </Section>
        </div>
    </AuthenticatedLayout>;
}
