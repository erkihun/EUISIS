import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { AppDialog, Button, Card, cx, EmptyState, FormField, Input, Pagination, Select, Textarea, type PaginationMeta } from '@euisis/ui';

type Severity = 'critical' | 'high' | 'medium' | 'low' | 'info';
type Named = { name_en: string; name_am: string | null } | null;
type Issue = {
    id: string; check_code: string; severity: Severity; status: string; summary: string; entity_type: string; entity_id: string; entity_url: string | null;
    organization: Named; detected_at: string | null; last_detected_at: string | null; times_reopened: number; assignee: string | null;
    resolution: string | null; auto_resolved: boolean; resolved_at: string | null; steps: string[]; step_url: string;
};
type Check = { code: string; kind: string; severity: Severity; summary: string; open: number };
type Filters = Record<'organization' | 'severity' | 'status' | 'check' | 'entity' | 'from' | 'to', string>;

const SEVERITY_TONE: Record<Severity, string> = { critical: 'danger', high: 'warning', medium: 'pending', low: 'info', info: 'inactive' };

/**
 * Core › Data Quality (ADR 0011): the issues the integrity checks found,
 * inside the reader's organizations, with their lifecycle: open →
 * investigating → resolved, accepted risk or false positive.
 */
export default function DataQuality({ tab, tabs, filters, summary, checks, issues, options, scan_url, url }: {
    tab: string;
    tabs: string[];
    filters: Filters;
    summary: { by_severity: Record<Severity, number>; open: number; mine: number };
    checks: Check[];
    issues: { data: Issue[]; meta: PaginationMeta } | null;
    options: { severities: Severity[]; statuses: string[]; entities: string[]; organizations: Array<{ id: string; name_en: string; name_am: string | null }> };
    scan_url: string | null;
    url: string;
}) {
    const { t, locale } = useLocale();
    const label = (key: string) => t(`core.dataQuality.${key}`);
    const named = (item: Named) => (item ? (locale === 'am' && item.name_am ? item.name_am : item.name_en) : label('noOrganization'));
    const [values, setValues] = useState<Filters>(filters);
    const [stepping, setStepping] = useState<{ issue: Issue; step: string } | null>(null);
    const form = useForm({ note: '' });

    const apply = (event: FormEvent) => {
        event.preventDefault();
        router.get(url, { tab, ...Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '')) }, { preserveState: true, preserveScroll: true });
    };
    const submitStep = (event: FormEvent) => {
        event.preventDefault();
        if (stepping) form.post(stepping.issue.step_url.replace('__step__', stepping.step), { preserveScroll: true, onSuccess: () => setStepping(null) });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')}
            actions={scan_url ? <Button variant="outline" onClick={() => router.post(scan_url, {}, { preserveScroll: true })}>{label('scanNow')}</Button> : undefined} />}>
            <Head title={label('title')} />
            <div className="space-y-5">
                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {(['critical', 'high', 'medium', 'low'] as Severity[]).map((severity) => (
                        <Card key={severity} className="p-4">
                            <p className="text-xs text-(--app-muted-foreground)">{label(`severities.${severity}`)}</p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">{summary.by_severity[severity] ?? 0}</p>
                        </Card>
                    ))}
                    <Card className="p-4"><p className="text-xs text-(--app-muted-foreground)">{label('openTotal')}</p><p className="mt-1 text-2xl font-semibold tabular-nums">{summary.open}</p></Card>
                    <Card className="p-4"><p className="text-xs text-(--app-muted-foreground)">{label('tabs.mine')}</p><p className="mt-1 text-2xl font-semibold tabular-nums">{summary.mine}</p></Card>
                </div>

                <nav aria-label={label('tabsLabel')} className="flex gap-1 overflow-x-auto border-b border-(--app-border)">
                    {tabs.map((name) => (
                        <Link key={name} href={url} data={{ tab: name }} preserveScroll aria-current={tab === name ? 'page' : undefined}
                            className={cx('-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm', tab === name ? 'border-primary font-medium text-primary' : 'border-transparent text-(--app-muted-foreground) hover:text-(--app-foreground)')}>
                            {label(`tabs.${name}`)}
                        </Link>
                    ))}
                </nav>

                {(tab === 'overview' || tab === 'definitions') && (
                    <Card className="overflow-x-auto">
                        <table className="w-full min-w-[40rem] text-left text-sm">
                            <thead><tr className="border-b border-(--app-border) text-xs text-(--app-muted-foreground)">
                                <th scope="col" className="px-4 py-2 font-medium">{label('check')}</th>
                                <th scope="col" className="px-4 py-2 font-medium">{label('severity')}</th>
                                <th scope="col" className="px-4 py-2 font-medium">{label('entity')}</th>
                                <th scope="col" className="px-4 py-2 font-medium">{tab === 'overview' ? label('openIssues') : label('definition')}</th>
                            </tr></thead>
                            <tbody className="divide-y divide-(--app-border)">
                                {checks.filter((check) => tab === 'definitions' || check.open > 0).map((check) => (
                                    <tr key={check.code}>
                                        <td className="px-4 py-2">{t(`core.dataQuality.checks.${check.code}`)}</td>
                                        <td className="px-4 py-2"><StatusBadge status={SEVERITY_TONE[check.severity]} label={label(`severities.${check.severity}`)} /></td>
                                        <td className="px-4 py-2">{label(`kinds.${check.kind}`)}</td>
                                        <td className="px-4 py-2">{tab === 'overview'
                                            ? <Link href={url} data={{ tab: 'open', check: check.code }} className="tabular-nums text-primary hover:underline">{check.open}</Link>
                                            : <span className="text-(--app-muted-foreground)">{check.summary}</span>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {tab === 'overview' && checks.every((check) => check.open === 0) && <EmptyState title={label('clean')} />}
                    </Card>
                )}

                {issues && (
                    <>
                        <form onSubmit={apply} className="grid gap-3 rounded-panel border border-(--app-border) bg-(--app-surface) p-4 sm:grid-cols-4 lg:grid-cols-8">
                            <Select aria-label={label('organization')} value={values.organization} onChange={(e) => setValues({ ...values, organization: e.target.value })}>
                                <option value="">{label('allOrganizations')}</option>
                                {options.organizations.map((org) => <option key={org.id} value={org.id}>{named(org)}</option>)}
                            </Select>
                            <Select aria-label={label('severity')} value={values.severity} onChange={(e) => setValues({ ...values, severity: e.target.value })}>
                                <option value="">{label('anySeverity')}</option>
                                {options.severities.map((s) => <option key={s} value={s}>{label(`severities.${s}`)}</option>)}
                            </Select>
                            <Select aria-label={label('status')} value={values.status} onChange={(e) => setValues({ ...values, status: e.target.value })}>
                                <option value="">{label('anyStatus')}</option>
                                {options.statuses.map((s) => <option key={s} value={s}>{label(`statuses.${s}`)}</option>)}
                            </Select>
                            <Select aria-label={label('check')} value={values.check} onChange={(e) => setValues({ ...values, check: e.target.value })}>
                                <option value="">{label('anyCheck')}</option>
                                {checks.map((c) => <option key={c.code} value={c.code}>{t(`core.dataQuality.checks.${c.code}`)}</option>)}
                            </Select>
                            <Select aria-label={label('entity')} value={values.entity} onChange={(e) => setValues({ ...values, entity: e.target.value })}>
                                <option value="">{label('anyEntity')}</option>
                                {options.entities.map((k) => <option key={k} value={k}>{label(`kinds.${k}`)}</option>)}
                            </Select>
                            <Input type="date" aria-label={label('from')} value={values.from} onChange={(e) => setValues({ ...values, from: e.target.value })} />
                            <Input type="date" aria-label={label('to')} value={values.to} onChange={(e) => setValues({ ...values, to: e.target.value })} />
                            <Button type="submit" variant="primary">{label('apply')}</Button>
                        </form>

                        {issues.data.length === 0 ? <Card><EmptyState title={label('noIssues')} /></Card> : (
                            <Card className="divide-y divide-(--app-border)">
                                {issues.data.map((issue) => (
                                    <div key={issue.id} className="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                                        <div className="min-w-0">
                                            <p className="font-medium text-(--app-foreground)">
                                                <StatusBadge status={SEVERITY_TONE[issue.severity]} label={label(`severities.${issue.severity}`)} />{' '}
                                                {t(`core.dataQuality.checks.${issue.check_code}`)}
                                            </p>
                                            <p className="mt-1 text-xs text-(--app-muted-foreground)">
                                                {label(`kinds.${issue.entity_type}`)} · {issue.entity_url ? <Link href={issue.entity_url} className="font-mono text-primary hover:underline">{issue.entity_id}</Link> : <span className="font-mono">{issue.entity_id}</span>}
                                                {' · '}{named(issue.organization)} · {label('detected')} <LocalizedDateDisplay value={issue.detected_at} withTime />
                                                {' · '}{label('lastSeen')} <LocalizedDateDisplay value={issue.last_detected_at} withTime />
                                                {issue.times_reopened > 0 && <> · {label('reopened')} {issue.times_reopened}</>}
                                            </p>
                                            <p className="mt-1 text-xs"><StatusBadge status={issue.status === 'open' ? 'pending' : issue.status === 'investigating' ? 'warning' : 'inactive'} label={label(`statuses.${issue.status}`)} />
                                                {issue.assignee && <span className="ml-2 text-(--app-muted-foreground)">{label('assignedTo')} {issue.assignee}</span>}</p>
                                            {issue.resolution && <p className="mt-1 text-sm">{issue.auto_resolved ? label('autoResolved') : issue.resolution}</p>}
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {issue.steps.map((step) => (
                                                <Button key={step} size="sm" variant={step === 'resolve' ? 'primary' : 'outline'} onClick={() => { form.reset(); form.clearErrors(); setStepping({ issue, step }); }}>{label(`steps.${step}`)}</Button>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </Card>
                        )}
                        <Pagination meta={issues.meta} onPageChange={(page) => router.get(url, { tab, ...filters, page }, { preserveScroll: true, preserveState: true })} />
                    </>
                )}
            </div>

            <AppDialog open={stepping !== null} onClose={() => setStepping(null)} title={stepping ? label(`steps.${stepping.step}`) : ''} description={label('stepDescription')}
                footer={<><Button variant="outline" onClick={() => setStepping(null)}>{label('cancel')}</Button><Button variant="primary" type="submit" form="dq-step-form" loading={form.processing}>{label('confirm')}</Button></>}>
                <form id="dq-step-form" onSubmit={submitStep}>
                    {(form.errors as Record<string, string | undefined>).issue && <p className="mb-2 text-sm text-red-600">{(form.errors as Record<string, string | undefined>).issue}</p>}
                    <FormField label={label('note')} required={stepping?.step !== 'investigate'} error={form.errors.note}>
                        {({ id }) => <Textarea id={id} rows={3} maxLength={2000} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />}
                    </FormField>
                </form>
            </AppDialog>
        </AuthenticatedLayout>
    );
}
