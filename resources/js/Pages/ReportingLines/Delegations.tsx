import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import DelegationForm from '@/Components/ReportingLines/DelegationForm';
import DelegationTable from '@/Components/ReportingLines/DelegationTable';
import type { ActionOption, DelegationRow, PageMeta } from '@/Components/ReportingLines/types';
import { Head, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { Button, FilterField, FilterPanel, ListPagination, Select, tableContainerClassName } from '@/Components/ui';
import { useState } from 'react';

type OrganizationOption = { id: string; name_en: string; name_am: string | null };

export default function DelegationsPage({ organizations, filters, delegations, meta, actions, can }: {
    organizations: OrganizationOption[];
    filters: { organization_id: string | null; status: string };
    delegations: DelegationRow[];
    meta: PageMeta;
    actions: ActionOption[];
    can: { manage: boolean };
}) {
    const { t, locale } = useLocale();
    const [organizationId, setOrganizationId] = useState(filters.organization_id ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [showForm, setShowForm] = useState(false);

    function submitFilters() {
        router.get(route('reporting-lines.delegations.index'), { organization_id: organizationId, status }, { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader backHref={route('reporting-lines.overview', { organization_id: organizationId })} title={t('reportingLines.delegations')} description={t('reportingLines.delegationsDescription')} actions={can.manage ? <Button type="button" onClick={() => setShowForm((v) => !v)}>{t('reportingLines.newDelegation')}</Button> : undefined} />}>
            <Head title={t('reportingLines.delegations')} />
            <div className="space-y-6">
                <FilterPanel onSubmit={submitFilters} hasActiveFilters={status !== ''} onReset={() => { setStatus(''); router.get(route('reporting-lines.delegations.index'), { organization_id: organizationId }); }}>
                    <FilterField label={t('reportingLines.organization')} wide>
                        <Select value={organizationId} onChange={(e) => setOrganizationId(e.target.value)}>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{localizedName(o.name_en, o.name_am, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('common.status')}>
                        <Select value={status} onChange={(e) => setStatus(e.target.value)}>
                            <option value="">{t('common.allStatuses')}</option>
                            <option value="active">{t('reportingLines.active')}</option>
                            <option value="revoked">{t('reportingLines.revoked')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                {showForm && can.manage && (
                    <DelegationForm organizationId={organizationId || null} actions={actions} storeRoute={route('reporting-lines.delegations.store')} pickDelegator onDone={() => setShowForm(false)} />
                )}

                <section className={tableContainerClassName}>
                    <DelegationTable delegations={delegations} revokeRoute={(id) => route('reporting-lines.delegations.revoke', id)} canRevoke={() => can.manage} />
                    <ListPagination meta={meta} />
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
