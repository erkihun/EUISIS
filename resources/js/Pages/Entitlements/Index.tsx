import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { AlertTriangle } from '@/Components/Icons';
import SearchPicker from '@/Components/fieldWork/SearchPicker';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

import { ListPagination, actionLinkClassName, buttonClassName, cx, inputClassName, type LaravelPageMeta } from '@/Components/ui';
type EntitlementRow = {
    id: string;
    status: string;
    quota_limit?: number | null;
    quota_used?: number | null;
    employee?: { employee_number: string; full_name: string } | null;
    service_type?: { name_en: string } | null;
    service_provider?: { name: string } | null;
};
type EmployeeOption = { id: string; employee_number: string; full_name: string };
type ServiceTypeOption = { id: string; name_en: string };
type ProviderOption = { id: string; name: string; service_type_id: string };
type CafeteriaProviderRow = {
    id: string;
    code: string;
    name_en: string;
    name_am?: string | null;
    is_active: boolean;
    location?: string | null;
    organization?: { name_en: string } | null;
};

const WARN_STATUSES = new Set(['paused', 'revoked', 'expired', 'exhausted']);

function QuotaBar({ used, limit, usedLabel }: { used: number; limit: number | null; usedLabel: string }) {
    if (!limit) return null;
    const pct = Math.min(100, Math.round((used / limit) * 100));
    const color =
        pct >= 90 ? 'bg-red-500' : pct >= 70 ? 'bg-orange-400' : 'bg-emerald-500';
    return (
        <div className="mt-2">
            <div className="flex items-center justify-between text-xs text-(--app-muted-foreground)">
                <span>{used} / {limit} {usedLabel}</span>
                <span>{pct}%</span>
            </div>
            <div className="mt-1 h-1.5 w-full rounded-full bg-(--app-surface-muted)">
                <div className={`h-1.5 rounded-full ${color}`} style={{ width: `${pct}%` }} />
            </div>
        </div>
    );
}

export default function EntitlementsIndex({
    entitlements,
    serviceTypes,
    providers,
    cafeteriaProviders,
}: {
    entitlements: LaravelPageMeta & { data: EntitlementRow[] };
    serviceTypes: ServiceTypeOption[];
    providers: ProviderOption[];
    cafeteriaProviders: CafeteriaProviderRow[];
}) {
    const { t } = useLocale();
    const [selectedEmployee, setSelectedEmployee] = useState<EmployeeOption | null>(null);

    const form = useForm({
        employee_id: '',
        service_type_id: serviceTypes[0]?.id ?? '',
        service_provider_id: '',
        quota_limit: '30',
    });

    const inputCls = cx(inputClassName, 'w-full');

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('entitlements.title')} description="" />}
        >
            <Head title={t('entitlements.title')} />

            {/* Cafeteria Providers */}
            <section className="mb-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                <h3 className="font-semibold text-(--app-foreground)">
                    {t('entitlements.cafeteriaProviders')}
                </h3>
                <div className="mt-4">
                    {cafeteriaProviders.length === 0 ? (
                        <p className="text-sm text-(--app-muted-foreground)">
                            {t('entitlements.noCafeteriaProviders')}
                        </p>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {cafeteriaProviders.map((cp) => (
                                <div
                                    key={cp.id}
                                    className="flex items-start justify-between gap-3 rounded-card border border-(--app-border) bg-(--app-surface-muted) p-4"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium text-(--app-foreground)">
                                            {cp.name_en}
                                        </p>
                                        <p className="mt-0.5 font-mono text-xs text-(--app-muted-foreground)">
                                            {t('entitlements.code')}: {cp.code}
                                        </p>
                                        {cp.organization && (
                                            <p className="mt-0.5 text-xs text-(--app-muted-foreground)">
                                                {cp.organization.name_en}
                                            </p>
                                        )}
                                        {cp.location && (
                                            <p className="mt-0.5 text-xs text-(--app-muted-foreground)">
                                                {t('entitlements.location')}: {cp.location}
                                            </p>
                                        )}
                                    </div>
                                    <span
                                        className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${
                                            cp.is_active
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                                : 'bg-(--app-surface-muted) text-(--app-muted-foreground)'
                                        }`}
                                    >
                                        {cp.is_active ? t('entitlements.active') : t('entitlements.inactive')}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </section>

            <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                    <h3 className="font-semibold text-(--app-foreground)">
                        {t('entitlements.activeEntitlements')}
                    </h3>
                    <div className="mt-4 space-y-3">
                        {entitlements.data.length === 0 ? (
                            <EmptyState
                                title={t('entitlements.noEntitlements')}
                                description={t('entitlements.grantFirst')}
                            />
                        ) : (
                            entitlements.data.map((ent) => {
                                const isWarn = WARN_STATUSES.has(ent.status);
                                return (
                                    <div
                                        key={ent.id}
                                        className={`rounded-card border p-4 ${
                                            isWarn
                                                ? 'border-orange-200 bg-orange-50 dark:border-orange-900/40 dark:bg-orange-900/10'
                                                : 'border-(--app-border) bg-(--app-surface-muted)'
                                        }`}
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <p className="font-medium text-(--app-foreground)">
                                                    {ent.employee?.employee_number} · {ent.employee?.full_name}
                                                </p>
                                                <p className="mt-0.5 text-sm text-(--app-muted-foreground)">
                                                    {ent.service_type?.name_en ?? t('entitlements.unknownService')}{' '}
                                                    · {ent.service_provider?.name ?? t('entitlements.globalScope')}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                {isWarn && (
                                                    <AlertTriangle className="h-4 w-4 text-orange-500" aria-hidden="true" />
                                                )}
                                                <StatusBadge status={ent.status} />
                                            </div>
                                        </div>
                                        <QuotaBar
                                            used={ent.quota_used ?? 0}
                                            limit={ent.quota_limit ?? null}
                                            usedLabel={t('entitlements.used')}
                                        />
                                    </div>
                                );
                            })
                        )}
                    </div>
                    <ListPagination meta={entitlements} label={t('entitlements.activeEntitlements')} bare className="mt-4" />
                </section>

                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                    <h3 className="font-semibold text-(--app-foreground)">
                        {t('entitlements.grantEntitlement')}
                    </h3>
                    <form
                        className="mt-4 space-y-3"
                        onSubmit={(e: FormEvent<HTMLFormElement>) => {
                            e.preventDefault();
                            form.post(route('entitlements.store'));
                        }}
                    >
                        <div>
                            {selectedEmployee ? (
                                <div>
                                    <span className="mb-1 block text-xs font-medium text-(--app-muted-foreground)">
                                        {t('entitlements.employee')}
                                    </span>
                                    <div className="flex items-center justify-between gap-2 rounded-lg border border-(--app-border-strong) px-3 py-2 text-sm">
                                        <span>{selectedEmployee.employee_number} · {selectedEmployee.full_name}</span>
                                        <button
                                            type="button"
                                            className={actionLinkClassName()}
                                            onClick={() => { setSelectedEmployee(null); form.setData('employee_id', ''); }}
                                        >
                                            {t('common.change')}
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <SearchPicker<EmployeeOption>
                                    url={route('lookup.employees')}
                                    label={t('entitlements.employee')}
                                    placeholder={t('common.searchEmployee')}
                                    render={(emp) => `${emp.employee_number} · ${emp.full_name}`}
                                    onPick={(emp) => { setSelectedEmployee(emp); form.setData('employee_id', emp.id); }}
                                />
                            )}
                            {form.errors.employee_id && (
                                <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.employee_id}</p>
                            )}
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-(--app-muted-foreground)">
                                {t('entitlements.serviceType')}
                            </label>
                            <select
                                className={inputCls}
                                value={form.data.service_type_id}
                                onChange={(e) => form.setData('service_type_id', e.target.value)}
                            >
                                {serviceTypes.map((st) => (
                                    <option key={st.id} value={st.id}>{st.name_en}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-(--app-muted-foreground)">
                                {t('entitlements.providerOptional')}
                            </label>
                            <select
                                className={inputCls}
                                value={form.data.service_provider_id}
                                onChange={(e) => form.setData('service_provider_id', e.target.value)}
                            >
                                <option value="">{t('entitlements.noProviderRestriction')}</option>
                                {providers.map((p) => (
                                    <option key={p.id} value={p.id}>{p.name}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-(--app-muted-foreground)">
                                {t('entitlements.quotaLimit')}
                            </label>
                            <input
                                type="number"
                                min={1}
                                className={inputCls}
                                value={form.data.quota_limit}
                                onChange={(e) => form.setData('quota_limit', e.target.value)}
                            />
                        </div>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {form.processing ? t('common.saving') : t('entitlements.grant')}
                        </button>
                    </form>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
