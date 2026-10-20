import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';

import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { useState } from 'react';

import EmptyState from '@/Components/EmptyState';
import { AppActionMenu, FilterField, FilterPanel, Input, ListPagination, Select, Table, TableCard, TBody, Td, Th, THead, Tr, buttonClassName, type LaravelPageMeta } from '@/Components/ui';
type AnnouncementCan = {
    update: boolean;
    publish: boolean;
    close: boolean;
    cancel: boolean;
    delete: boolean;
};

type Announcement = {
    id: string;
    organization: { name_en: string; name_am: string | null } | null;
    position: { title_en: string; title_am: string | null } | null;
    grade_level: string | null;
    number_of_vacancies: number;
    opening_date: string;
    closing_date: string;
    status: string;
    applications_count: number;
    can: AnnouncementCan;
};

type Props = {
    announcements: LaravelPageMeta & { data: Announcement[] };
    filters: { status?: string; search?: string };
    can: { create: boolean };
};

const STATUSES = ['draft', 'published', 'closed', 'cancelled'] as const;


export default function TransferAnnouncementsIndex({ announcements, filters, can }: Props) {
    const { locale, t } = useLocale();
    const { confirm } = useConfirm();
    const useAmharic = locale === 'am';
    const [processing, setProcessing] = useState<string | null>(null);

    const [draft, setDraft] = useState({ search: filters.search ?? '', status: filters.status ?? '' });

    function applyFilters() {
        router.get(route('transfer-announcements.index'), Object.fromEntries(Object.entries(draft).filter(([, value]) => value)), { preserveState: true, replace: true });
    }

    async function handlePublish(a: Announcement) {
        const { confirmed } = await confirm({
            title: t('transfers.publishAnnouncement'),
            description: `${(useAmharic ? a.position?.title_am : null) ?? a.position?.title_en ?? ''} — ${(useAmharic ? a.organization?.name_am : null) ?? a.organization?.name_en ?? ''}`,
            confirmLabel: t('transfers.publishAnnouncement'),
            cancelLabel: t('common.cancel'),
            variant: 'default',
        });
        if (!confirmed) return;
        setProcessing(a.id + 'publish');
        router.post(route('transfer-announcements.publish', a.id), {}, {
            preserveScroll: true,
            onFinish: () => setProcessing(null),
        });
    }

    async function handleClose(a: Announcement) {
        const { confirmed } = await confirm({
            title: t('transfers.closeAnnouncement'),
            description: t('transfers.closeConfirmation'),
            confirmLabel: t('transfers.closeAnnouncement'),
            cancelLabel: t('common.cancel'),
            variant: 'warning',
        });
        if (!confirmed) return;
        setProcessing(a.id + 'close');
        router.post(route('transfer-announcements.close', a.id), {}, {
            preserveScroll: true,
            onFinish: () => setProcessing(null),
        });
    }

    async function handleCancel(a: Announcement) {
        const { confirmed } = await confirm({
            title: t('transfers.cancelAnnouncement'),
            description: t('transfers.cancelConfirmation'),
            // Both buttons would read "Cancel" otherwise.
            confirmLabel: t('transfers.cancelAnnouncementConfirm'),
            cancelLabel: t('transfers.keepAnnouncement'),
            variant: 'danger',
        });
        if (!confirmed) return;
        setProcessing(a.id + 'cancel');
        router.post(route('transfer-announcements.cancel', a.id), {}, {
            preserveScroll: true,
            onFinish: () => setProcessing(null),
        });
    }

    async function handleDelete(a: Announcement) {
        const { confirmed } = await confirm({
            title: t('common.delete'),
            description: `${(useAmharic ? a.position?.title_am : null) ?? a.position?.title_en ?? ''} — ${(useAmharic ? a.organization?.name_am : null) ?? a.organization?.name_en ?? ''}`,
            confirmLabel: t('common.delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (!confirmed) return;
        setProcessing(a.id + 'delete');
        router.delete(route('transfer-announcements.destroy', a.id), {
            preserveScroll: true,
            onFinish: () => setProcessing(null),
        });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('transfers.announcements')}
                    description={t('transfers.multiInstitutionHint')}
                    actions={
                        can.create ? (
                            <Link
                                href={route('transfer-announcements.create')}
                                className={buttonClassName({ variant: 'primary' })}
                            >
                                {t('transfers.createAnnouncement')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('transfers.announcements')} />

            <div className="space-y-4">
                <FilterPanel onSubmit={applyFilters} onReset={() => router.get(route('transfer-announcements.index'))} hasActiveFilters={Boolean(filters.search || filters.status)}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </FilterField>
                    <FilterField label={t('common.status')}>
                        <Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                            <option value="">{t('common.allStatuses')}</option>
                            {STATUSES.map((s) => (
                                <option key={s} value={s}>
                                    {t(`transfers.status${s.charAt(0).toUpperCase()}${s.slice(1)}` as never)}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('transfers.position')}</Th>
                                <Th>{t('transfers.organization')}</Th>
                                <Th align="right">{t('transfers.vacancies')}</Th>
                                <Th>{t('transfers.applicationPeriod')}</Th>
                                <Th align="right">{t('transfers.applicationsCount')}</Th>
                                <Th>{t('common.status')}</Th>
                                <Th align="right"><span className="sr-only">{t('common.actions')}</span></Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {announcements.data.map((a) => (
                                <Tr key={a.id}>
                                    <Td>
                                        <Link href={route('transfer-announcements.show', a.id)} className="font-medium text-primary hover:underline">
                                            {(useAmharic ? a.position?.title_am : null) ?? a.position?.title_en ?? '—'}
                                        </Link>
                                        {a.grade_level && <span className="block text-xs text-(--app-muted-foreground)">{t('transfers.gradeLevel')}: {a.grade_level}</span>}
                                    </Td>
                                    <Td className="text-(--app-foreground)">{(useAmharic ? a.organization?.name_am : null) ?? a.organization?.name_en ?? '—'}</Td>
                                    <Td numeric>{a.number_of_vacancies}</Td>
                                    <Td className="whitespace-nowrap text-(--app-muted-foreground)">
                                        <LocalizedDateDisplay value={a.opening_date} /> – <LocalizedDateDisplay value={a.closing_date} />
                                    </Td>
                                    <Td numeric>
                                        {a.applications_count > 0 ? (
                                            <Link href={`${route('transfer-applications.index')}?announcement_id=${a.id}`} className="font-semibold text-primary hover:underline">
                                                {a.applications_count}
                                            </Link>
                                        ) : (
                                            <span className="text-(--app-muted-foreground)">0</span>
                                        )}
                                    </Td>
                                    <Td><StatusBadge status={a.status} /></Td>
                                    <Td align="right">
                                        <AppActionMenu
                                            label={`${t('common.actions')}: ${(useAmharic ? a.position?.title_am : null) ?? a.position?.title_en ?? ''}`}
                                            items={[
                                                { label: t('common.view'), href: route('transfer-announcements.show', a.id) },
                                                { label: t('common.edit'), href: route('transfer-announcements.edit', a.id), show: a.can.update },
                                                { label: t('transfers.publishAnnouncement'), onClick: () => handlePublish(a), show: a.can.publish, disabled: processing !== null },
                                                { label: t('transfers.applications'), href: `${route('transfer-applications.index')}?announcement_id=${a.id}`, show: a.status !== 'draft' },
                                                { label: t('transfers.closeAnnouncement'), onClick: () => handleClose(a), show: a.can.close, variant: 'warning', separatorBefore: true, disabled: processing !== null },
                                                { label: t('transfers.cancelAnnouncement'), onClick: () => handleCancel(a), show: a.can.cancel, variant: 'danger', disabled: processing !== null },
                                                { label: t('common.delete'), onClick: () => handleDelete(a), show: a.can.delete, variant: 'danger', disabled: processing !== null },
                                            ]}
                                        />
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                    {announcements.data.length === 0 && (
                        <EmptyState
                            title={t('transfers.noAnnouncements')}
                            description={filters.search || filters.status ? t('common.noMatchingRecords') : t('transfers.noAnnouncementsHint')}
                            action={can.create && !filters.search && !filters.status ? (
                                <Link href={route('transfer-announcements.create')} className={buttonClassName({ variant: 'primary' })}>{t('transfers.createAnnouncement')}</Link>
                            ) : undefined}
                        />
                    )}
                    <ListPagination meta={announcements} />
                </TableCard>
            </div>
        </AuthenticatedLayout>
    );
}
