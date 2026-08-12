import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { localizedName } from '@/utils/localizedName';

export default function PositionsShow({ position }: { position: any }) {
    const { t, locale } = useLocale();
    const standardName = localizedName(position.title_en, position.title_am, locale);

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('positions.index')}
                    title={`${position.job_position_code} · ${standardName}`}
                    actions={
                        <div className="flex gap-3">
                            {position.can?.update && <Link href={route('positions.edit', position.id)} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">{t('common.edit')}</Link>}
                            {position.can?.archive && position.is_active && <button type="button" onClick={() => router.delete(route('positions.archive', position.id))} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">{t('positions.archivePosition')}</button>}
                            {position.can?.restore && !position.is_active && <button type="button" onClick={() => router.post(route('positions.restore', position.id))} className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">{t('positions.restorePosition')}</button>}
                        </div>
                    }
                />
            }
        >
            <Head title={standardName} />
            <div className="grid gap-6 lg:grid-cols-3">
                <section className="rounded-2xl border border-gray-200 bg-white p-5 lg:col-span-2 dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-4 md:grid-cols-2 text-sm">
                        <div><div className="text-xs text-gray-500 dark:text-slate-400">{t('positions.jobPositionCode')}</div><div className="mt-1 font-mono">{position.job_position_code}</div></div>
                        <div><div className="text-xs text-gray-500 dark:text-slate-400">{t('positions.oldCode')}</div><div className="mt-1 font-mono">{position.old_code ?? '—'}</div></div>
                        <div><div className="text-xs text-gray-500 dark:text-slate-400">{t('common.status')}</div><div className="mt-1"><StatusBadge status={position.is_active ? 'active' : 'inactive'} /></div></div>
                        <div><div className="text-xs text-gray-500 dark:text-slate-400">{t('positions.organization')}</div><div className="mt-1">{position.organization ? localizedName(position.organization.name_en, position.organization.name_am, locale) : '—'}</div></div>
                        <div><div className="text-xs text-gray-500 dark:text-slate-400">{t('positions.organizationUnit')}</div><div className="mt-1">{position.organization_unit ? localizedName(position.organization_unit.name_en, position.organization_unit.name_am, locale) : '—'}</div></div>
                        <div><div className="text-xs text-gray-500 dark:text-slate-400">{t('positions.gradeLevel')}</div><div className="mt-1">{position.grade_level ?? '—'}</div></div>
                        <div><div className="text-xs text-gray-500 dark:text-slate-400">{t('positions.standardName')}</div><div className="mt-1">{standardName}</div></div>
                        <div><div className="text-xs text-gray-500 dark:text-slate-400">{t('positions.bprName')}</div><div className="mt-1">{position.bpr_name ?? '—'}</div></div>
                        <div><div className="text-xs text-gray-500 dark:text-slate-400">{t('positions.occupation')}</div><div className="mt-1">{position.occupation ? localizedName(position.occupation.name_en, position.occupation.name_am, locale) : '—'}</div></div>
                        <div><div className="text-xs text-gray-500 dark:text-slate-400">{t('positions.createdAt')}</div><div className="mt-1"><LocalizedDateDisplay value={position.created_at} withTime /></div></div>
                        <div><div className="text-xs text-gray-500 dark:text-slate-400">{t('positions.updatedAt')}</div><div className="mt-1"><LocalizedDateDisplay value={position.updated_at} withTime /></div></div>
                    </div>
                </section>
                <aside className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div className="text-xs text-gray-500 dark:text-slate-400">{t('common.count')}</div>
                    <div className="mt-1 text-2xl font-semibold text-gray-900 dark:text-slate-100">{position.assignments_count ?? 0}</div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
