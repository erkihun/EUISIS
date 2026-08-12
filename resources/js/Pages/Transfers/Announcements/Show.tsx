import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

type Application = {
    id: string;
    status: string;
    submitted_at: string;
    employee: { employee_number: string; full_name: string } | null;
    releasingOrganization: { name_en: string; name_am: string | null } | null;
};

type Announcement = {
    id: string;
    organization: { name_en: string; name_am: string | null } | null;
    position: { title_en: string; title_am: string | null } | null;
    grade_level: string | null;
    salary_min: string | null;
    salary_max: string | null;
    number_of_vacancies: number;
    eligibility_rules: string[] | null;
    required_documents: string[] | null;
    opening_date: string;
    closing_date: string;
    status: string;
    published_at: string | null;
    published_by: { name: string } | null;
    applications: Application[];
};

type Props = {
    announcement: Announcement;
    can: { update: boolean; publish: boolean; close: boolean; cancel: boolean; delete: boolean };
};

const btnBase = 'inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none';

export default function TransferAnnouncementShow({ announcement, can }: Props) {
    const { locale, t } = useLocale();
    const { confirm } = useConfirm();
    const useAmharic = locale === 'am';

    const positionLabel = (useAmharic ? announcement.position?.title_am : null) ?? announcement.position?.title_en ?? t('transfers.announcement');
    const orgLabel      = (useAmharic ? announcement.organization?.name_am : null) ?? announcement.organization?.name_en ?? '';

    async function handlePublish() {
        const { confirmed } = await confirm({
            title: t('transfers.publishAnnouncement'),
            description: `${positionLabel} — ${orgLabel}`,
            confirmLabel: t('transfers.publishAnnouncement'),
            cancelLabel: t('common.cancel'),
            variant: 'default',
        });
        if (confirmed) router.post(route('transfer-announcements.publish', announcement.id), {}, { preserveScroll: true });
    }

    async function handleClose() {
        const { confirmed } = await confirm({
            title: t('transfers.closeAnnouncement'),
            description: `${positionLabel} — ${orgLabel}`,
            confirmLabel: t('transfers.closeAnnouncement'),
            cancelLabel: t('common.cancel'),
            variant: 'warning',
        });
        if (confirmed) router.post(route('transfer-announcements.close', announcement.id), {}, { preserveScroll: true });
    }

    async function handleCancel() {
        const { confirmed } = await confirm({
            title: t('transfers.cancelAnnouncement'),
            description: `${positionLabel} — ${orgLabel}`,
            confirmLabel: t('transfers.cancelAnnouncement'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.post(route('transfer-announcements.cancel', announcement.id), {}, { preserveScroll: true });
    }

    async function handleDelete() {
        const { confirmed } = await confirm({
            title: t('common.delete'),
            description: `${positionLabel} — ${orgLabel}`,
            confirmLabel: t('common.delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('transfer-announcements.destroy', announcement.id));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('transfer-announcements.index')}
                    title={positionLabel}
                    description={orgLabel}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.update && announcement.status === 'draft' && (
                                <Link
                                    href={route('transfer-announcements.edit', announcement.id)}
                                    className={`${btnBase} border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800`}
                                >
                                    {t('common.edit')}
                                </Link>
                            )}
                            {can.publish && announcement.status === 'draft' && (
                                <button
                                    type="button"
                                    onClick={handlePublish}
                                    className={`${btnBase} bg-blue-600 text-white hover:bg-blue-700`}
                                >
                                    {t('transfers.publishAnnouncement')}
                                </button>
                            )}
                            {can.close && announcement.status === 'published' && (
                                <button
                                    type="button"
                                    onClick={handleClose}
                                    className={`${btnBase} border border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-400`}
                                >
                                    {t('transfers.closeAnnouncement')}
                                </button>
                            )}
                            {can.cancel && !['closed', 'cancelled'].includes(announcement.status) && (
                                <button
                                    type="button"
                                    onClick={handleCancel}
                                    className={`${btnBase} border border-red-300 text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400`}
                                >
                                    {t('transfers.cancelAnnouncement')}
                                </button>
                            )}
                            {can.delete && announcement.status === 'draft' && (
                                <button
                                    type="button"
                                    onClick={handleDelete}
                                    className={`${btnBase} border border-red-300 text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400`}
                                >
                                    {t('common.delete')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={announcement.position?.title_en ?? t('transfers.announcement')} />

            <div className="grid gap-6 lg:grid-cols-[1.5fr_1fr]">
                {/* Details */}
                <div className="space-y-6">
                    <section className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h3 className="font-semibold text-gray-900 dark:text-slate-100">{positionLabel}</h3>
                                <p className="text-sm text-gray-500 dark:text-slate-400">{orgLabel || '—'}</p>
                            </div>
                            <StatusBadge status={announcement.status} />
                        </div>

                        <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                            {[
                                { label: t('transfers.gradeLevel'),        value: announcement.grade_level ?? '—' },
                                { label: t('transfers.numberOfVacancies'), value: String(announcement.number_of_vacancies) },
                            ].map(({ label, value }) => (
                                <div key={label}>
                                    <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{label}</dt>
                                    <dd className="mt-1 text-gray-800 dark:text-slate-200">{value}</dd>
                                </div>
                            ))}
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('transfers.openingDate')}</dt>
                                <dd className="mt-1 text-gray-800 dark:text-slate-200"><LocalizedDateDisplay value={announcement.opening_date} /></dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('transfers.closingDate')}</dt>
                                <dd className="mt-1 text-gray-800 dark:text-slate-200"><LocalizedDateDisplay value={announcement.closing_date} /></dd>
                            </div>
                        </dl>

                        {announcement.eligibility_rules && announcement.eligibility_rules.length > 0 && (
                            <div className="mt-4">
                                <p className="mb-2 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">
                                    {t('transfers.eligibilityRules')}
                                </p>
                                <ul className="space-y-1">
                                    {announcement.eligibility_rules.map((rule, i) => (
                                        <li key={i} className="flex items-start gap-2 text-sm text-gray-700 dark:text-slate-300">
                                            <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-500" />
                                            {rule}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {announcement.required_documents && announcement.required_documents.length > 0 && (
                            <div className="mt-4">
                                <p className="mb-2 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">
                                    {t('transfers.requiredDocuments')}
                                </p>
                                <ul className="space-y-1">
                                    {announcement.required_documents.map((doc, i) => (
                                        <li key={i} className="flex items-start gap-2 text-sm text-gray-700 dark:text-slate-300">
                                            <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" />
                                            {doc}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </section>
                </div>

                {/* Applications */}
                <section className="rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="font-semibold text-gray-900 dark:text-slate-100">{t('transfers.applications')}</h3>
                        <span className="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-slate-800 dark:text-slate-300">
                            {announcement.applications.length}
                        </span>
                    </div>

                    {announcement.applications.length === 0 ? (
                        <p className="text-sm text-gray-400 dark:text-slate-500">{t('transfers.noApplications')}</p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {announcement.applications.map((app) => (
                                <li key={app.id} className="flex items-center justify-between gap-3">
                                    <Link
                                        href={route('transfer-applications.show', app.id)}
                                        className="truncate text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        {app.employee?.full_name ?? app.employee?.employee_number ?? app.id}
                                    </Link>
                                    <StatusBadge status={app.status} />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
