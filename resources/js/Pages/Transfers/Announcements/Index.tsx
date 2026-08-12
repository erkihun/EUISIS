import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';

function paginationLabel(label: string): string {
    return label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&amp;/g, '&');
}
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { useCallback, useState } from 'react';

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

type PaginationLink = { url: string | null; label: string; active: boolean };

type Props = {
    announcements: { data: Announcement[]; links: PaginationLink[] };
    filters: { status?: string; search?: string };
    can: { create: boolean };
};

const STATUSES = ['draft', 'published', 'closed', 'cancelled'] as const;


const btnBase = 'inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 disabled:opacity-50 disabled:pointer-events-none';
const btnGray  = `${btnBase} border border-gray-200 text-gray-700 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-300 dark:hover:border-blue-500 dark:hover:text-blue-300`;
const btnBlue  = `${btnBase} bg-blue-600 text-white hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600`;
const btnAmber = `${btnBase} border border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-400 dark:hover:bg-amber-950/30`;
const btnRed   = `${btnBase} border border-red-300 text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-950/30`;

export default function TransferAnnouncementsIndex({ announcements, filters, can }: Props) {
    const { locale, t } = useLocale();
    const { confirm } = useConfirm();
    const useAmharic = locale === 'am';
    const [processing, setProcessing] = useState<string | null>(null);

    const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    const applyFilters = useCallback((patch: Record<string, string>) => {
        const next = { ...(filters as Record<string, string>), ...patch };
        Object.keys(next).forEach((k) => { if (!next[k]) delete next[k]; });
        router.get(route('transfer-announcements.index'), next, { preserveState: true, replace: true });
    }, [filters]);

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
                                className="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
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
                {/* Filter bar */}
                <div className="flex flex-wrap items-center gap-2">
                    <input
                        type="search"
                        placeholder={t('common.search')}
                        className={inputCls}
                        defaultValue={filters.search ?? ''}
                        onKeyDown={(e) => { if (e.key === 'Enter') applyFilters({ search: (e.target as HTMLInputElement).value }); }}
                        onBlur={(e) => applyFilters({ search: e.target.value })}
                    />
                    <select
                        className={inputCls}
                        value={filters.status ?? ''}
                        onChange={(e) => applyFilters({ status: e.target.value })}
                    >
                        <option value="">{t('common.status')} — {t('common.all')}</option>
                        {STATUSES.map((s) => (
                            <option key={s} value={s}>
                                {t(`transfers.status${s.charAt(0).toUpperCase()}${s.slice(1)}` as never)}
                            </option>
                        ))}
                    </select>
                </div>

                {/* Table */}
                <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
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
                                        className={`whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400 ${i === 8 ? 'text-right' : ''}`}
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {announcements.data.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="px-4 py-12 text-center">
                                        <div className="flex flex-col items-center gap-2 text-gray-400 dark:text-slate-500">
                                            <svg className="h-10 w-10 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
                                            </svg>
                                            <p className="text-sm">{t('transfers.noAnnouncements')}</p>
                                            {can.create && (
                                                <Link href={route('transfer-announcements.create')} className="mt-1 text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                                                    {t('transfers.createAnnouncement')}
                                                </Link>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                announcements.data.map((a) => (
                                    <tr key={a.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                        <td className="px-4 py-3 text-gray-700 dark:text-slate-200">
                                            {(useAmharic ? a.organization?.name_am : null) ?? a.organization?.name_en ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">
                                            {(useAmharic ? a.position?.title_am : null) ?? a.position?.title_en ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{a.grade_level ?? '—'}</td>
                                        <td className="px-4 py-3 text-center font-semibold text-gray-700 dark:text-slate-200">{a.number_of_vacancies}</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={a.opening_date} /></td>
                                        <td className="whitespace-nowrap px-4 py-3 text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={a.closing_date} /></td>
                                        <td className="px-4 py-3 text-center">
                                            {a.applications_count > 0 ? (
                                                <Link href={route('transfer-applications.index') + `?announcement_id=${a.id}`} className="font-semibold text-blue-600 hover:underline dark:text-blue-400">
                                                    {a.applications_count}
                                                </Link>
                                            ) : (
                                                <span className="text-gray-400">{a.applications_count}</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={a.status} />
                                        </td>
                                        <td className="px-4 py-3">
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

                {/* Pagination */}
                {announcements.links && announcements.links.length > 3 && (
                    <div className="flex flex-wrap items-center justify-center gap-1">
                        {announcements.links.map((link, i) => (
                            <button
                                key={i}
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true })}
                                className={[
                                    'rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors',
                                    link.active
                                        ? 'border-blue-600 bg-blue-600 text-white'
                                        : 'border-gray-200 text-gray-600 hover:border-blue-300 hover:text-blue-700 disabled:opacity-40 disabled:pointer-events-none dark:border-slate-700 dark:text-slate-300',
                                ].join(' ')}
                            >{paginationLabel(link.label)}</button>
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
