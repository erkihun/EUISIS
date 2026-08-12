import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

type Exclusion = {
    id: string; employee_name: string | null; employee_number: string | null;
    exclusion_type: string; exclusion_type_label: string;
    starts_on: string; ends_on: string | null; return_to_work_on: string | null;
    is_open_ended: boolean; status: string; status_label: string;
    reason_en: string | null; reason_am: string | null;
    ended_at: string | null;
    can: { update: boolean; end: boolean; archive: boolean };
};

const STATUS_COLORS: Record<string, string> = {
    active:    'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
    ended:     'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-400',
    cancelled: 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400',
};

export default function EmployeeExclusionShow({ exclusion }: { exclusion: Exclusion }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();

    async function handleEnd() {
        const { confirmed } = await confirm({ title: t('cafeteria.endExclusion'), description: t('cafeteria.employeeReturnedToWork'), confirmLabel: t('cafeteria.endExclusion'), cancelLabel: t('confirmations.cancel'), variant: 'warning' });
        if (confirmed) router.post(route('cafeteria.employee-exclusions.end', exclusion.id));
    }

    async function handleArchive() {
        const { confirmed } = await confirm({ title: t('confirmations.confirmDeleteTitle'), description: '', confirmLabel: t('confirmations.delete'), cancelLabel: t('confirmations.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('cafeteria.employee-exclusions.archive', exclusion.id));
    }

    const rowCls = 'flex items-start gap-4 py-3 border-b border-gray-100 dark:border-slate-800 last:border-0';
    const keyC = 'w-48 flex-shrink-0 text-sm text-gray-500 dark:text-slate-400';
    const valC = 'text-sm font-medium text-gray-800 dark:text-slate-200';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('cafeteria.employee-exclusions.index')}
                    title={t('cafeteria.employeeExclusion')}
                    actions={
                        <div className="flex gap-2">
                            {exclusion.can.update && (
                                <Link href={route('cafeteria.employee-exclusions.edit', exclusion.id)} className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300">
                                    {t('common.edit')}
                                </Link>
                            )}
                            {exclusion.can.end && exclusion.status === 'active' && (
                                <button onClick={handleEnd} className="rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700">
                                    {t('cafeteria.endExclusion')}
                                </button>
                            )}
                            {exclusion.can.archive && (
                                <button onClick={handleArchive} className="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700">
                                    {t('common.delete')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={t('cafeteria.employeeExclusion')} />
            <div className="mx-auto max-w-2xl">
                <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className={rowCls}>
                        <span className={keyC}>{t('common.employee')}</span>
                        <div>
                            <div className={valC}>{exclusion.employee_name ?? '—'}</div>
                            {exclusion.employee_number && <div className="text-xs text-gray-400">{exclusion.employee_number}</div>}
                        </div>
                    </div>
                    <div className={rowCls}>
                        <span className={keyC}>{t('cafeteria.exclusionType')}</span>
                        <span className={valC}>{exclusion.exclusion_type_label}</span>
                    </div>
                    <div className={rowCls}>
                        <span className={keyC}>Status</span>
                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLORS[exclusion.status] ?? ''}`}>
                            {exclusion.status_label}
                        </span>
                    </div>
                    <div className={rowCls}>
                        <span className={keyC}>{t('cafeteria.startsOn')}</span>
                        <span className={valC}><LocalizedDateDisplay value={exclusion.starts_on} /></span>
                    </div>
                    <div className={rowCls}>
                        <span className={keyC}>{t('cafeteria.endsOn')}</span>
                        <span className={valC}>{exclusion.is_open_ended ? <em className="text-gray-400">Open-ended</em> : <LocalizedDateDisplay value={exclusion.ends_on} />}</span>
                    </div>
                    {exclusion.return_to_work_on && (
                        <div className={rowCls}>
                            <span className={keyC}>{t('cafeteria.returnToWorkOn')}</span>
                            <span className={valC}><LocalizedDateDisplay value={exclusion.return_to_work_on} /></span>
                        </div>
                    )}
                    {exclusion.ended_at && (
                        <div className={rowCls}>
                            <span className={keyC}>{t('cafeteria.exclusionEnded')}</span>
                            <span className={valC}><LocalizedDateDisplay value={exclusion.ended_at} withTime /></span>
                        </div>
                    )}
                    {exclusion.reason_en && (
                        <div className={rowCls}>
                            <span className={keyC}>{t('cafeteria.reasonEn')}</span>
                            <span className={valC}>{exclusion.reason_en}</span>
                        </div>
                    )}
                    {exclusion.reason_am && (
                        <div className={rowCls}>
                            <span className={keyC}>{t('cafeteria.reasonAm')}</span>
                            <span className={valC}>{exclusion.reason_am}</span>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
