import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import EmptyState from '@/Components/EmptyState';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { FilterField, FilterPanel, Input, ListPagination, Select, Table, TableCard, TBody, Td, Th, THead, Tr, type LaravelPageMeta } from '@/Components/ui';
import { Alert, Button } from '@euisis/ui';
import type { TransferApplicationRecord } from '@/Components/transfers/types';

type Props = {
    applications: LaravelPageMeta & { data: TransferApplicationRecord[] };
    filters: { status?: string; search?: string; announcement_id?: string };
    announcement: { id: string; title_en: string | null; title_am: string | null } | null;
};

const STATUSES = [
    'submitted', 'under_review', 'selected',
    'release_pending', 'receiving_pending', 'final_approval_pending',
    'approved', 'implementation_failed', 'transferred', 'rejected', 'withdrawn', 'cancelled',
] as const;

export default function TransferApplicationsIndex({ applications, filters, announcement }: Props) {
    const { locale, t } = useLocale();
    const { organizationName } = useDisplayFormat();
    const [draft, setDraft] = useState({ search: filters.search ?? '', status: filters.status ?? '' });

    // The announcement filter comes from a link, so it is kept until cleared here.
    const query = (next: Record<string, string | undefined>) =>
        Object.fromEntries(Object.entries({ announcement_id: filters.announcement_id, ...next }).filter(([, value]) => value));

    const org = (value: TransferApplicationRecord['releasing_organization']) => (value ? organizationName(value.name_en, value.name_am) : '—');

    return (
        <AuthenticatedLayout header={<PageHeader backHref={route('transfers.dashboard')} title={t('transfers.applications')} />}>
            <Head title={t('transfers.applications')} />

            <div className="space-y-4">
                {announcement && (
                    <Alert tone="info">
                        <span className="flex flex-wrap items-center justify-between gap-2">
                            <span>{t('transfers.filteredByAnnouncement').replace(':title', localizedName(announcement.title_en, announcement.title_am, locale))}</span>
                            <Button variant="link" size="sm" onClick={() => router.get(route('transfer-applications.index'), query({ announcement_id: undefined, ...draft }))}>
                                {t('transfers.showAllApplications')}
                            </Button>
                        </span>
                    </Alert>
                )}

                <FilterPanel
                    onSubmit={() => router.get(route('transfer-applications.index'), query(draft), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('transfer-applications.index'), query({}))}
                    hasActiveFilters={Boolean(filters.status || filters.search)}
                >
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={t('transfers.searchApplicationsHint')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </FilterField>
                    <FilterField label={t('common.status')}>
                        <Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                            <option value="">{t('common.allStatuses')}</option>
                            {STATUSES.map((status) => <option key={status} value={status}>{t(`status.${status}`)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('common.employee')}</Th>
                                <Th>{t('transfers.destinationPosition')}</Th>
                                <Th>{t('transfers.releasingOrganization')}</Th>
                                <Th>{t('transfers.receivingOrganization')}</Th>
                                <Th>{t('common.submittedAt')}</Th>
                                <Th>{t('common.status')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {applications.data.map((application) => (
                                <Tr key={application.id}>
                                    <Td>
                                        <Link href={route('transfer-applications.show', application.id)} className="font-medium text-primary hover:underline">
                                            {application.employee?.full_name ?? '—'}
                                        </Link>
                                        <span className="block text-xs text-(--app-muted-foreground)">
                                            {[application.employee?.employee_number, application.application_number].filter(Boolean).join(' · ')}
                                        </span>
                                    </Td>
                                    <Td className="text-(--app-foreground)">
                                        {application.position ? localizedName(application.position.title_en, application.position.title_am, locale) : '—'}
                                    </Td>
                                    <Td className="text-(--app-muted-foreground)">{org(application.releasing_organization)}</Td>
                                    <Td className="text-(--app-muted-foreground)">{org(application.receiving_organization)}</Td>
                                    <Td className="whitespace-nowrap text-(--app-muted-foreground)"><LocalizedDateDisplay value={application.submitted_at} fallback="—" /></Td>
                                    <Td><StatusBadge status={application.status} /></Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                    {applications.data.length === 0 && (
                        <EmptyState
                            title={t('transfers.noApplications')}
                            description={filters.status || filters.search ? t('common.noMatchingRecords') : t('transfers.noApplicationsHint')}
                        />
                    )}
                    <ListPagination meta={applications} />
                </TableCard>
            </div>
        </AuthenticatedLayout>
    );
}
