import { Head, Link, useForm } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { PencilIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import RelationshipPanel, { type RelationshipRow } from '@/Components/relationships/RelationshipPanel';
import ReportingLinesPanel from '@/Components/relationships/ReportingLinesPanel';

interface ChildOffice {
    id: string;
    office_code: string;
    name_en: string | null;
    office_level: string;
    status: string;
}

interface OfficeDetail {
    id: string;
    institution_id: string;
    institution: { id: string; name_en: string; code: string } | null;
    geographic_organization_id: string | null;
    geographicOrganization: { id: string; name_en: string; code: string } | null;
    parent_office_id: string | null;
    parentOffice: { id: string; name_en: string; office_code: string } | null;
    office_code: string;
    name_en: string | null;
    name_am: string | null;
    short_name_en: string | null;
    short_name_am: string | null;
    office_level: string;
    status: string;
    is_head_office: boolean;
    assigned_scope_type: string;
    opened_on: string | null;
    closed_on: string | null;
    address_en: string | null;
    address_am: string | null;
    phone_number: string | null;
    email: string | null;
    notes: string | null;
    child_offices_count: number;
    children: ChildOffice[];
}

interface CanProps {
    update: boolean;
    delete: boolean;
    restore: boolean;
    move: boolean;
    create: boolean;
    manageRelationships: boolean;
}

interface Props {
    office: OfficeDetail;
    can: CanProps;
    relationships: RelationshipRow[];
    relationshipOptions: ComponentProps<typeof RelationshipPanel>['options'];
}

export default function InstitutionOfficesShow({ office, can, relationships, relationshipOptions }: Props) {
    const { t } = useLocale();
    const deleteForm = useForm({});

    function handleDelete() {
        if (!confirm(t('common.confirmDelete'))) return;
        deleteForm.delete(route('institution-offices.destroy', office.id), {
            onSuccess: () => {},
        });
    }

    const fields = [
        { label: t('institutionOffices.officeCode'), value: office.office_code },
        { label: t('institutionOffices.officeLevel'), value: office.office_level },
        { label: t('institutionOffices.institution'), value: office.institution?.name_en ?? '—' },
        { label: t('institutionOffices.geographicArea'), value: office.geographicOrganization?.name_en ?? '—' },
        {
            label: t('institutionOffices.parentOffice'),
            value: office.parentOffice
                ? `${office.parentOffice.name_en} (${office.parentOffice.office_code})`
                : '—',
        },
        { label: t('institutionOffices.assignedScopeType'), value: office.assigned_scope_type },
        { label: t('institutionOffices.openedOn'), value: office.opened_on ?? '—' },
        { label: t('institutionOffices.closedOn'), value: office.closed_on ?? '—' },
        { label: t('institutionOffices.phone'), value: office.phone_number ?? '—' },
        { label: t('institutionOffices.email'), value: office.email ?? '—' },
    ];

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('institution-offices.index')}
                    title={office.name_en ?? office.office_code}
                    description={office.office_code}
                    actions={
                        <div className="flex items-center gap-2">
                            {can.create && office.institution_id && (
                                <Link
                                    href={`${route('institution-offices.create')}?institution_id=${office.institution_id}&parent_office_id=${office.id}`}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                                >
                                    + {t('institutionOffices.addOffice')}
                                </Link>
                            )}
                            {can.update && (
                                <Link
                                    href={route('institution-offices.edit', office.id)}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                                >
                                    <PencilIcon className="h-3.5 w-3.5" />
                                    {t('common.edit')}
                                </Link>
                            )}
                            {can.delete && (
                                <button
                                    type="button"
                                    disabled={deleteForm.processing}
                                    onClick={handleDelete}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-60 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20"
                                >
                                    {t('institutionOffices.deleteOffice')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={office.name_en ?? office.office_code} />

            <div className="grid gap-6 lg:grid-cols-[1.2fr_1fr]">
                {/* Main details */}
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-medium text-gray-400 dark:text-slate-500">
                                {office.office_code}
                            </p>
                            <h3 className="mt-1 text-2xl font-semibold text-gray-900 dark:text-slate-100">
                                {office.name_en ?? office.office_code}
                            </h3>
                            {office.name_am && (
                                <p className="mt-0.5 text-sm text-gray-500 dark:text-slate-400">
                                    {office.name_am}
                                </p>
                            )}
                            {office.is_head_office && (
                                <span className="mt-2 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                                    {t('institutionOffices.headOffice')}
                                </span>
                            )}
                        </div>
                        <StatusBadge status={office.status} />
                    </div>

                    <dl className="mt-6 grid gap-4 sm:grid-cols-2">
                        {fields.map(({ label, value }) => (
                            <div key={label}>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                    {label}
                                </dt>
                                <dd className="mt-1 text-sm text-gray-800 dark:text-slate-200">{value}</dd>
                            </div>
                        ))}
                    </dl>

                    {office.notes && (
                        <div className="mt-4 rounded-lg bg-gray-50 p-3 dark:bg-slate-800">
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                {t('institutionOffices.notes')}
                            </p>
                            <p className="mt-1 text-sm text-gray-700 dark:text-slate-300">{office.notes}</p>
                        </div>
                    )}
                </section>

                {/* Child offices */}
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                        {t('institutionOffices.childOffices')} ({office.child_offices_count})
                    </h3>
                    {office.children.length === 0 ? (
                        <p className="mt-4 text-sm text-gray-400 dark:text-slate-500">
                            {t('institutionOffices.noOffices')}
                        </p>
                    ) : (
                        <ul className="mt-4 space-y-2">
                            {office.children.map((child) => (
                                <li
                                    key={child.id}
                                    className="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 dark:border-slate-800"
                                >
                                    <div>
                                        <Link
                                            href={route('institution-offices.show', child.id)}
                                            className="text-sm font-medium text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                        >
                                            {child.name_en ?? child.office_code}
                                        </Link>
                                        <span className="ml-2 rounded-full bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                            {child.office_level}
                                        </span>
                                    </div>
                                    <StatusBadge status={child.status} />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <div className="mt-6 grid gap-6 lg:grid-cols-[1.2fr_1fr]">
                <RelationshipPanel
                    rows={relationships}
                    options={relationshipOptions}
                    storeRoute={route('institution-offices.relationships.store', office.id)}
                    canManage={can.manageRelationships}
                />
                <ReportingLinesPanel rows={relationships.filter((relationship) => relationship.relationship_type !== 'structural_parent')} />
            </div>
        </AuthenticatedLayout>
    );
}
