import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

import { buttonClassName } from '@/Components/ui';
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
                                <Link href={route('entitlement-rules.edit', rule.id)} className={buttonClassName({ variant: 'primary' })}>
                                    {t('common.edit')}
                                </Link>
                            )}
                            {rule.can.archive && rule.is_active && (
                                <button type="button" onClick={() => router.delete(route('entitlement-rules.archive', rule.id))} className={buttonClassName({ variant: 'destructive' })}>
                                    {t('common.delete')}
                                </button>
                            )}
                            {rule.can.restore && !rule.is_active && (
                                <button type="button" onClick={() => router.post(route('entitlement-rules.restore', rule.id))} className={buttonClassName({ variant: 'primary' })}>
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
                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6 lg:col-span-2">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('entitlementRules.ruleName')}</p>
                            <p className="mt-1 text-sm text-[color:var(--app-foreground)]">{rule.name}</p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('common.status')}</p>
                            <div className="mt-1">
                                <StatusBadge status={rule.is_active ? 'active' : 'inactive'} />
                            </div>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('entitlementRules.serviceType')}</p>
                            <p className="mt-1 text-sm text-[color:var(--app-foreground)]">
                                {rule.service_type ? (locale === 'am' ? (rule.service_type.name_am ?? rule.service_type.name_en) : rule.service_type.name_en) : '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('entitlementRules.entitlementsCount')}</p>
                            <p className="mt-1 text-sm text-[color:var(--app-foreground)]">{rule.entitlements_count ?? 0}</p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('entitlementRules.quotaLimit')}</p>
                            <p className="mt-1 text-sm text-[color:var(--app-foreground)]">{rule.rule_definition?.quota_limit ?? '—'}</p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('entitlementRules.periodDays')}</p>
                            <p className="mt-1 text-sm text-[color:var(--app-foreground)]">{rule.rule_definition?.period_days ?? '—'}</p>
                        </div>
                    </div>

                    <div className="mt-6">
                        <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('entitlementRules.notes')}</p>
                        <p className="mt-1 text-sm leading-6 text-[color:var(--app-foreground)]">{rule.rule_definition?.notes ?? '—'}</p>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
