import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';

import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { useState } from 'react';

import { FilterField, FilterPanel, Input, ListPagination, Select, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableRowClassName, type LaravelPageMeta } from '@/Components/ui';
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


// Row actions use the shared small buttons.
const btnGray = buttonClassName({ variant: 'outline', size: 'sm' });
const btnBlue = buttonClassName({ variant: 'primary', size: 'sm' });
const btnAmber = buttonClassName({ variant: 'outline', size: 'sm' });
const btnRed = buttonClassName({ variant: 'destructiveOutline', size: 'sm' });

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
            description: `${(useAmharic ? a.position?.title_am : null) ?? a.position?.title_en ?? ''} — ${(useAmharic ? a.organization?.name_am : null) ?? a.organization?.name_en ?? ''}`,
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
            description: `${(useAmharic ? a.position?.title_am : null) ?? a.position?.title_en ?? ''} — ${(useAmharic ? a.organization?.name_am : null) ?? a.organization?.name_en ?? ''}`,
            confirmLabel: t('transfers.cancelAnnouncement'),
            cancelLabel: t('common.cancel'),
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

                {/* Table */}
                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[
                                    t('transfers.organization'),
                                    t('transfers.position'),
                                    t('transfers.gradeLevel'),
                                    t('transfers.vacancies'),
                                    t('transfers.openingDate'),
                                    t('transfers.closingDate'),
                                    t('transfers.applicationsCount'),
                                    t('common.status'),
                                    t('common.actions'),
                                ].map((h, i) => (
                                    <th
                                        key={h}
                                        className={`whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase text-[color:var(--app-muted-foreground)] ${i === 8 ? 'text-right' : ''}`}
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {announcements.data.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className={cx(tableCellClassName, 'text-center')}>
                                        <div className="flex flex-col items-center gap-2 text-[color:var(--app-muted-foreground)]">
                                            <svg className="h-10 w-10 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
                                            </svg>
                                            <p className="text-sm">{t('transfers.noAnnouncements')}</p>
                                            {can.create && (
                                                <Link href={route('transfer-announcements.create')} className="mt-1 text-sm font-medium text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">
                                                    {t('transfers.createAnnouncement')}
                                                </Link>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                announcements.data.map((a) => (
                                    <tr key={a.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>
                                            {(useAmharic ? a.organization?.name_am : null) ?? a.organization?.name_en ?? '—'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'font-medium text-[color:var(--app-foreground)]')}>
                                            {(useAmharic ? a.position?.title_am : null) ?? a.position?.title_en ?? '—'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>{a.grade_level ?? '—'}</td>
                                        <td className={cx(tableCellClassName, 'text-center font-semibold text-[color:var(--app-foreground)]')}>{a.number_of_vacancies}</td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-[color:var(--app-muted-foreground)]')}><LocalizedDateDisplay value={a.opening_date} /></td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-[color:var(--app-muted-foreground)]')}><LocalizedDateDisplay value={a.closing_date} /></td>
                                        <td className={cx(tableCellClassName, 'text-center')}>
                                            {a.applications_count > 0 ? (
                                                <Link href={route('transfer-applications.index') + `?announcement_id=${a.id}`} className="font-semibold text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">
                                                    {a.applications_count}
                                                </Link>
                                            ) : (
                                                <span className="text-[color:var(--app-muted-foreground)]">{a.applications_count}</span>
                                            )}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <StatusBadge status={a.status} />
                                        </td>
                                        <td className={tableCellClassName}>
                                            <div className="flex flex-wrap items-center justify-end gap-1.5">
                                                <Link href={route('transfer-announcements.show', a.id)} className={btnGray}>
                                                    {t('common.view')}
                                                </Link>
                                                {a.can.update && (
                                                    <Link href={route('transfer-announcements.edit', a.id)} className={btnGray}>
                                                        {t('common.edit')}
                                                    </Link>
                                                )}
                                                {a.can.publish && (
                                                    <button
                                                        type="button"
                                                        className={btnBlue}
                                                        disabled={processing === a.id + 'publish'}
                                                        onClick={() => handlePublish(a)}
                                                    >
                                                        {t('transfers.publishAnnouncement')}
                                                    </button>
                                                )}
                                                {a.can.close && (
                                                    <button
                                                        type="button"
                                                        className={btnAmber}
                                                        disabled={processing === a.id + 'close'}
                                                        onClick={() => handleClose(a)}
                                                    >
                                                        {t('transfers.closeAnnouncement')}
                                                    </button>
                                                )}
                                                {a.can.cancel && (
                                                    <button
                                                        type="button"
                                                        className={btnRed}
                                                        disabled={processing === a.id + 'cancel'}
                                                        onClick={() => handleCancel(a)}
                                                    >
                                                        {t('transfers.cancelAnnouncement')}
                                                    </button>
                                                )}
                                                {a.can.delete && (
                                                    <button
                                                        type="button"
                                                        className={btnRed}
                                                        disabled={processing === a.id + 'delete'}
                                                        onClick={() => handleDelete(a)}
                                                    >
                                                        {t('common.delete')}
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    </div>
                    <ListPagination meta={announcements} />
                </div>

            </div>
        </AuthenticatedLayout>
    );
}
