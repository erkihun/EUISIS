import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

export default function EntitlementRulesShow({
    rule,
}: {
    rule: {
        id: string;
        name: string;
        service_type_id: string;
        service_type: { id: string; name_en: string; name_am?: string | null } | null;
        rule_definition: { quota_limit?: number; period_days?: number; notes?: string } | null;
        is_active: boolean;
        entitlements_count: number | null;
        can: { update: boolean; archive: boolean; restore: boolean };
    };
}) {
    const { t, locale } = useLocale();

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    backHref={route('entitlement-rules.index')}
                    title={rule.name}
                    actions={(
                        <div className="flex gap-3">
                            {rule.can.update && (
                                <Link href={route('entitlement-rules.edit', rule.id)} className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">
                                    {t('common.edit')}
                                </Link>
                            )}
                            {rule.can.archive && rule.is_active && (
                                <button type="button" onClick={() => router.delete(route('entitlement-rules.archive', rule.id))} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                                    {t('common.delete')}
                                </button>
                            )}
                            {rule.can.restore && !rule.is_active && (
                                <button type="button" onClick={() => router.post(route('entitlement-rules.restore', rule.id))} className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                                    {t('common.restore')}
                                </button>
                            )}
                        </div>
                    )}
                />
            )}
        >
            <Head title={rule.name} />

            <div className="grid gap-6 lg:grid-cols-3">
                <section className="rounded-panel border border-gray-200 bg-white p-6 lg:col-span-2 dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('entitlementRules.ruleName')}</p>
                            <p className="mt-1 text-sm text-gray-900 dark:text-slate-100">{rule.name}</p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('common.status')}</p>
                            <div className="mt-1">
                                <StatusBadge status={rule.is_active ? 'active' : 'inactive'} />
                            </div>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('entitlementRules.serviceType')}</p>
                            <p className="mt-1 text-sm text-gray-900 dark:text-slate-100">
                                {rule.service_type ? (locale === 'am' ? (rule.service_type.name_am ?? rule.service_type.name_en) : rule.service_type.name_en) : '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('entitlementRules.entitlementsCount')}</p>
                            <p className="mt-1 text-sm text-gray-900 dark:text-slate-100">{rule.entitlements_count ?? 0}</p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('entitlementRules.quotaLimit')}</p>
                            <p className="mt-1 text-sm text-gray-900 dark:text-slate-100">{rule.rule_definition?.quota_limit ?? '—'}</p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('entitlementRules.periodDays')}</p>
                            <p className="mt-1 text-sm text-gray-900 dark:text-slate-100">{rule.rule_definition?.period_days ?? '—'}</p>
                        </div>
                    </div>

                    <div className="mt-6">
                        <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('entitlementRules.notes')}</p>
                        <p className="mt-1 text-sm leading-6 text-gray-700 dark:text-slate-300">{rule.rule_definition?.notes ?? '—'}</p>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
