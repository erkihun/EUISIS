import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Button from '@/Components/Button';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Select } from '@/Components/ui';

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

const PERIODS = [
    ['today', 'dashboard.filters.today'],
    ['7d', 'dashboard.filters.last7Days'],
    ['30d', 'dashboard.filters.last30Days'],
    ['90d', 'dashboard.filters.last90Days'],
    ['custom', 'dashboard.filters.customRange'],
] as const;

const controlCls = 'h-9 rounded-control border-(--app-border-strong) bg-(--app-surface) text-sm text-(--app-foreground) focus:border-primary focus:ring-primary disabled:cursor-wait disabled:opacity-60';

/**
 * Scope controls for the dashboard: how far back, and whose data.
 *
 * The period is a segmented control — five choices, all visible, one tap —
 * and applies immediately; the organization stays a select because the list
 * is long. "Custom" alone waits for its two dates and the one button that is
 * genuinely required, because a half-typed range must not fire a query.
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

    const applyNow = (patch: Partial<typeof form.data>) => {
        const next = { ...form.data, ...patch };
        form.setData(next);
        if (next.date_range !== 'custom') {
            apply(next);
        }
    };

    return (
        <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center" aria-busy={applying}>
            <div role="group" aria-label={t('dashboard.dateRange')} className="inline-flex max-w-full overflow-x-auto rounded-control border border-(--app-border) bg-(--app-surface-muted) p-0.5">
                {PERIODS.map(([value, labelKey]) => {
                    const active = form.data.date_range === value;
                    return (
                        <Button
                            key={value}
                            type="button"
                            size="sm"
                            variant={active ? 'primary' : 'ghost'}
                            aria-pressed={active}
                            disabled={applying}
                            onClick={() => applyNow({ date_range: value })}
                            className="shrink-0 whitespace-nowrap"
                        >
                            {t(labelKey)}
                        </Button>
                    );
                })}
            </div>

            {isCustom && (
                <>
                    <LocalizedDatePicker value={form.data.date_from} onChange={(iso) => form.setData('date_from', iso)} disabled={applying} className={`${controlCls} w-full sm:w-auto`} />
                    <span aria-hidden="true" className="hidden text-sm text-(--app-muted-foreground) sm:inline">–</span>
                    <LocalizedDatePicker value={form.data.date_to} onChange={(iso) => form.setData('date_to', iso)} disabled={applying} className={`${controlCls} w-full sm:w-auto`} />
                    <Button type="button" size="sm" loading={applying} disabled={applying} onClick={() => apply()}>
                        {t('common.filter')}
                    </Button>
                </>
            )}

            {/* Hidden entirely when the viewer is scoped to one organization — a dropdown with a single choice is not a choice. */}
            {filters.organizationOptions.length > 1 && (
                <div className="w-full sm:w-64">
                    <Select
                        aria-label={t('organizations.organization')}
                        value={form.data.organization_id}
                        disabled={applying}
                        onChange={(event) => applyNow({ organization_id: event.target.value })}
                    >
                        <option value="">{t('dashboard.filters.allOrganizations')}</option>
                        {filters.organizationOptions.map((organization) => (
                            <option key={organization.id} value={organization.id}>{organization.name}</option>
                        ))}
                    </Select>
                </div>
            )}
        </div>
    );
}
