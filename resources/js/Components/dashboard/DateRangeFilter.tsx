import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Button from '@/Components/Button';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

interface OrganizationOption {
    id: string;
    name: string;
    code: string;
}

interface Props {
    filters: {
        dateRange: string;
        dateFrom: string;
        dateTo: string;
        organizationId: string | null;
        organizationOptions: OrganizationOption[];
    };
    t: (key: string) => string;
}

const controlCls =
    'h-9 w-full rounded-control border-gray-300 text-sm focus:border-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)] disabled:cursor-wait disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 sm:w-auto';

/**
 * Scope controls for the dashboard: how far back, and whose data.
 *
 * Previously a full-width bordered card carrying four stacked label/control
 * pairs and two buttons — "Refresh" and "Filter" — which called the same
 * submit handler and did exactly the same thing. It was the tallest element
 * above the fold and the first thing a reader met after the page title.
 *
 * Now an inline toolbar. Changing the range or the organization applies
 * immediately, so neither button is needed; the custom date pair appears only
 * when "Custom range" is chosen, and brings the one button that is genuinely
 * required, because a half-typed date range must not fire a query.
 */
export default function DateRangeFilter({ filters, t }: Props) {
    const [applying, setApplying] = useState(false);
    const form = useForm({
        date_range: filters.dateRange,
        date_from: filters.dateFrom,
        date_to: filters.dateTo,
        organization_id: filters.organizationId ?? '',
    });

    const isCustom = form.data.date_range === 'custom';

    const apply = (data = form.data) => {
        router.get(route('dashboard'), data, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setApplying(true),
            onFinish: () => setApplying(false),
        });
    };

    /* Applies on change, except for "custom" — that one waits for the dates. */
    const applyNow = (patch: Partial<typeof form.data>) => {
        const next = { ...form.data, ...patch };
        form.setData(next);

        if (next.date_range !== 'custom') {
            apply(next);
        }
    };

    return (
        <div
            className="flex min-w-0 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center"
            aria-busy={applying}
        >
            <select
                aria-label={t('dashboard.dateRange')}
                value={form.data.date_range}
                disabled={applying}
                onChange={(event) => applyNow({ date_range: event.target.value })}
                className={controlCls}
            >
                <option value="today">{t('dashboard.filters.today')}</option>
                <option value="7d">{t('dashboard.filters.last7Days')}</option>
                <option value="30d">{t('dashboard.filters.last30Days')}</option>
                <option value="90d">{t('dashboard.filters.last90Days')}</option>
                <option value="custom">{t('dashboard.filters.customRange')}</option>
            </select>

            {isCustom && (
                <>
                    <LocalizedDatePicker
                        value={form.data.date_from}
                        onChange={(iso) => form.setData('date_from', iso)}
                        disabled={applying}
                        className={controlCls}
                    />
                    <span aria-hidden="true" className="hidden text-sm text-gray-400 dark:text-slate-600 sm:inline">
                        –
                    </span>
                    <LocalizedDatePicker
                        value={form.data.date_to}
                        onChange={(iso) => form.setData('date_to', iso)}
                        disabled={applying}
                        className={controlCls}
                    />
                    <Button type="button" size="sm" loading={applying} disabled={applying} onClick={() => apply()}>
                        {t('common.filter')}
                    </Button>
                </>
            )}

            {/* Hidden entirely when the viewer is scoped to one organization —
                a dropdown with a single choice is not a choice. */}
            {filters.organizationOptions.length > 1 && (
                <select
                    aria-label={t('organizations.organization')}
                    value={form.data.organization_id}
                    disabled={applying}
                    onChange={(event) => applyNow({ organization_id: event.target.value })}
                    className={`${controlCls} sm:max-w-[16rem]`}
                >
                    <option value="">{t('dashboard.filters.allOrganizations')}</option>
                    {filters.organizationOptions.map((organization) => (
                        <option key={organization.id} value={organization.id}>
                            {organization.name}
                        </option>
                    ))}
                </select>
            )}
        </div>
    );
}
