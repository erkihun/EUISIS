import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, FilterField, FilterPanel, Select } from '@/Components/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Organization = { id: string; code: string; name_en: string; name_am: string | null };

type Kpis = {
    organizations: number; baselines_published: number; baselines_in_progress: number; baselines_failing_validation: number;
    organizations_without_mandate: number; legal_sources: number; services_without_function: number; tasks_without_standard: number;
    required_fte: string; approved_headcount: number; positions: number; positions_vacant: number; positions_without_jd: number; positions_without_profile: number; imports_waiting: number;
};

type AsOf = {
    date: string;
    mandates: { id: string; code: string; version_no: number; title_en: string; title_am: string | null; status: string; effective_from: string | null; effective_to: string | null }[];
    job_descriptions_in_force: number;
    baseline: { id: string; reference_number: string; version_no: number; title_en: string; title_am: string | null; status: string; effective_date: string | null; counts: Record<string, number> | null } | null;
    plan: { id: string; reference_number: string; title_en: string; title_am: string | null; period_start: string | null; period_end: string | null } | null;
};

type Props = {
    categories: { key: string; href: string }[];
    kpis: Kpis;
    organizations: Organization[];
    filters: { organization_id: string | null; as_of: string | null };
    asOf: AsOf | null;
};

/** Figures that point at work to do read as warnings when they are not zero. */
const ATTENTION: (keyof Kpis)[] = ['baselines_failing_validation', 'organizations_without_mandate', 'services_without_function', 'tasks_without_standard', 'positions_without_jd', 'positions_without_profile', 'imports_waiting'];

/**
 * The module's front door: one card per area the user may open, the
 * figures that say where the design stands, and "what applied on this
 * date" for one organization. Required FTE and approved headcount sit side
 * by side and are never merged.
 */
export default function DesignOverview({ categories, kpis, organizations, filters, asOf }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ organization_id: filters.organization_id ?? '', as_of: filters.as_of ?? '' });

    const figure = (key: keyof Kpis) => {
        const value = kpis[key];
        const attention = ATTENTION.includes(key) && Number(value) > 0;
        return (
            <div key={key} className="rounded-panel border border-(--app-border) bg-(--app-surface) p-4">
                <p className="text-xs font-medium text-(--app-muted-foreground)">{tt(`overview.kpis.${key}`)}</p>
                <p className={`mt-1 text-2xl font-semibold tabular-nums ${attention ? 'text-amber-700 dark:text-amber-300' : 'text-(--app-foreground)'}`}>{value}</p>
            </div>
        );
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('overview.title')} description={tt('overview.description')} />}>
            <Head title={tt('overview.title')} />

            <div className="space-y-6">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.overview'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.overview'))}
                    hasActiveFilters={Boolean(filters.organization_id || filters.as_of)}
                >
                    <FilterField label={tt('fields.organization')}>
                        <Select value={draft.organization_id} onChange={(e) => setDraft({ ...draft, organization_id: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('overview.asOfDate')}>
                        <LocalizedDatePicker className="w-full" value={draft.as_of} onChange={(iso) => setDraft({ ...draft, as_of: iso })} />
                    </FilterField>
                </FilterPanel>

                <section aria-labelledby="design-categories">
                    <h2 id="design-categories" className="sr-only">{tt('overview.categoriesTitle')}</h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {categories.map((c) => (
                            <Link key={c.key} href={c.href} className="group rounded-panel border border-(--app-border) bg-(--app-surface) p-4 transition-colors hover:border-(--app-border-strong) focus:outline-hidden focus-visible:ring-2 focus-visible:ring-primary">
                                <p className="text-sm font-semibold text-(--app-foreground) group-hover:underline">{tt(`overview.categories.${c.key}.title`)}</p>
                                <p className="mt-1 text-xs leading-relaxed text-(--app-muted-foreground)">{tt(`overview.categories.${c.key}.body`)}</p>
                            </Link>
                        ))}
                    </div>
                </section>

                <section aria-labelledby="design-figures" className="space-y-3">
                    <h2 id="design-figures" className="text-base font-semibold text-(--app-foreground)">{tt('overview.figuresTitle')}</h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {(['organizations', 'baselines_published', 'baselines_in_progress', 'baselines_failing_validation', 'organizations_without_mandate', 'legal_sources', 'services_without_function', 'tasks_without_standard'] as (keyof Kpis)[]).map(figure)}
                    </div>
                    <h3 className="pt-2 text-sm font-semibold text-(--app-foreground)">{tt('overview.workforceTitle')}</h3>
                    <p className="text-xs text-(--app-muted-foreground)">{tt('overview.workforceHelp')}</p>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {(['required_fte', 'approved_headcount', 'positions', 'positions_vacant', 'positions_without_jd', 'positions_without_profile', 'imports_waiting'] as (keyof Kpis)[]).map(figure)}
                    </div>
                </section>

                {filters.organization_id && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{tt('overview.asOfTitle')}</CardTitle>
                            <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{asOf ? <><LocalizedDateDisplay value={asOf.date} /> · {tt('overview.asOfHelp')}</> : tt('overview.asOfPick')}</p>
                        </CardHeader>
                        {asOf && (
                            <div className="grid gap-4 p-5 md:grid-cols-2">
                                <div>
                                    <h3 className="text-sm font-semibold text-(--app-foreground)">{tt('overview.asOfMandates')}</h3>
                                    {asOf.mandates.length === 0 ? <p className="mt-1 text-sm text-(--app-muted-foreground)">{tt('overview.none')}</p> : (
                                        <ul className="mt-2 space-y-1 text-sm">
                                            {asOf.mandates.map((m) => (
                                                <li key={m.id} className="flex flex-wrap items-center gap-2">
                                                    <Link href={route('organization-design.mandates.show', m.id)} className="font-medium text-primary hover:underline">{m.code} v{m.version_no}</Link>
                                                    <span className="text-(--app-foreground)">{titleOf(m, locale)}</span>
                                                    <StatusPill status={m.status} />
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                                <div className="space-y-3 text-sm">
                                    <div>
                                        <h3 className="font-semibold text-(--app-foreground)">{tt('overview.asOfBaseline')}</h3>
                                        {asOf.baseline ? (
                                            <p className="mt-1"><Link href={route('organization-design.legacy-baselines.show', asOf.baseline.id)} className="font-medium text-primary hover:underline">{asOf.baseline.reference_number} v{asOf.baseline.version_no}</Link> · {titleOf(asOf.baseline, locale)} · <LocalizedDateDisplay value={asOf.baseline.effective_date} /></p>
                                        ) : <p className="mt-1 text-(--app-muted-foreground)">{tt('overview.none')}</p>}
                                    </div>
                                    <div>
                                        <h3 className="font-semibold text-(--app-foreground)">{tt('overview.asOfPlan')}</h3>
                                        {asOf.plan ? (
                                            <p className="mt-1"><Link href={route('organization-design.manpower-plans.show', asOf.plan.id)} className="font-medium text-primary hover:underline">{asOf.plan.reference_number}</Link> · {titleOf(asOf.plan, locale)}</p>
                                        ) : <p className="mt-1 text-(--app-muted-foreground)">{tt('overview.none')}</p>}
                                    </div>
                                    <div>
                                        <h3 className="font-semibold text-(--app-foreground)">{tt('overview.asOfJds')}</h3>
                                        <p className="mt-1 tabular-nums">{asOf.job_descriptions_in_force}</p>
                                    </div>
                                </div>
                            </div>
                        )}
                        {!asOf && <div className="px-5 pb-5"><Button variant="outline" onClick={() => router.get(route('organization-design.overview'), { organization_id: filters.organization_id, as_of: new Date().toISOString().slice(0, 10) }, { preserveState: true })}>{tt('overview.asOfToday')}</Button></div>}
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
