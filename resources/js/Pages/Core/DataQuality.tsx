import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { AppMetricCard, AppTabNav, FilterField, FilterPanel, ListPagination, TBody, THead, Table, TableCard, Td, Th, Tr, actionLinkClassName, laravelPageMeta } from '@/Components/ui';
import { AppDialog, Button, Card, EmptyState, FieldError, FormField, Input, Select, Textarea, type PaginationMeta } from '@euisis/ui';

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
// A severity figure draws attention only while it is above zero.
const SEVERITY_VARIANT: Record<Severity, 'danger' | 'warning' | 'neutral'> = { critical: 'danger', high: 'warning', medium: 'warning', low: 'neutral', info: 'neutral' };

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
    const hasFilters = Object.values(filters).some(Boolean);

    const apply = () => {
        router.get(url, { tab, ...Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '')) }, { preserveState: true, preserveScroll: true });
    };
    const reset = () => router.get(url, { tab }, { preserveState: false });
    const submitStep = (event: FormEvent) => {
        event.preventDefault();
        if (stepping) form.post(stepping.issue.step_url.replace('__step__', stepping.step), { preserveScroll: true, onSuccess: () => setStepping(null) });
    };
    const set = (key: keyof Filters) => (event: { target: { value: string } }) => setValues({ ...values, [key]: event.target.value });

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')}
            actions={scan_url ? <Button variant="outline" onClick={() => router.post(scan_url, {}, { preserveScroll: true })}>{label('scanNow')}</Button> : undefined} />}>
            <Head title={label('title')} />
            <div className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    {(['critical', 'high', 'medium', 'low'] as Severity[]).map((severity) => {
                        const count = summary.by_severity[severity] ?? 0;
                        return <AppMetricCard key={severity} label={label(`severities.${severity}`)} value={count} variant={count > 0 ? SEVERITY_VARIANT[severity] : 'neutral'} />;
                    })}
                    <AppMetricCard label={label('openTotal')} value={summary.open} variant="primary" />
                    <AppMetricCard label={label('tabs.mine')} value={summary.mine} variant="primary" />
                </div>

                <AppTabNav label={label('tabsLabel')} current={tab} items={tabs.map((name) => ({ key: name, label: label(`tabs.${name}`), href: url, data: { tab: name } }))} />

                {(tab === 'overview' || tab === 'definitions') && (() => {
                    const shown = checks.filter((check) => tab === 'definitions' || check.open > 0);
                    return (
                        <Card className="overflow-hidden p-0">
                            {shown.length === 0 ? <EmptyState title={label('clean')} /> : (
                                <Table>
                                    <THead>
                                        <Tr>
                                            <Th>{label('check')}</Th>
                                            <Th>{label('severity')}</Th>
                                            <Th>{label('entity')}</Th>
                                            <Th align={tab === 'overview' ? 'right' : 'left'}>{tab === 'overview' ? label('openIssues') : label('definition')}</Th>
                                        </Tr>
                                    </THead>
                                    <TBody>
                                        {shown.map((check) => (
                                            <Tr key={check.code}>
                                                <Td>{t(`core.dataQuality.checks.${check.code}`)}</Td>
                                                <Td><StatusBadge status={SEVERITY_TONE[check.severity]} label={label(`severities.${check.severity}`)} /></Td>
                                                <Td muted>{label(`kinds.${check.kind}`)}</Td>
                                                {tab === 'overview'
                                                    ? <Td numeric><Link href={url} data={{ tab: 'open', check: check.code }} className={actionLinkClassName({ size: 'sm' })}>{check.open}</Link></Td>
                                                    : <Td muted>{check.summary}</Td>}
                                            </Tr>
                                        ))}
                                    </TBody>
                                </Table>
                            )}
                        </Card>
                    );
                })()}

                {issues && (
                    <>
                        <FilterPanel label={label('title')} onSubmit={apply} onReset={reset} hasActiveFilters={hasFilters}>
                            <FilterField label={label('organization')}>
                                <Select value={values.organization} onChange={set('organization')}>
                                    <option value="">{label('allOrganizations')}</option>
                                    {options.organizations.map((org) => <option key={org.id} value={org.id}>{named(org)}</option>)}
                                </Select>
                            </FilterField>
                            <FilterField label={label('severity')}>
                                <Select value={values.severity} onChange={set('severity')}>
                                    <option value="">{label('anySeverity')}</option>
                                    {options.severities.map((s) => <option key={s} value={s}>{label(`severities.${s}`)}</option>)}
                                </Select>
                            </FilterField>
                            <FilterField label={label('status')}>
                                <Select value={values.status} onChange={set('status')}>
                                    <option value="">{label('anyStatus')}</option>
                                    {options.statuses.map((s) => <option key={s} value={s}>{label(`statuses.${s}`)}</option>)}
                                </Select>
                            </FilterField>
                            <FilterField label={label('check')}>
                                <Select value={values.check} onChange={set('check')}>
                                    <option value="">{label('anyCheck')}</option>
                                    {checks.map((c) => <option key={c.code} value={c.code}>{t(`core.dataQuality.checks.${c.code}`)}</option>)}
                                </Select>
                            </FilterField>
                            <FilterField label={label('entity')}>
                                <Select value={values.entity} onChange={set('entity')}>
                                    <option value="">{label('anyEntity')}</option>
                                    {options.entities.map((k) => <option key={k} value={k}>{label(`kinds.${k}`)}</option>)}
                                </Select>
                            </FilterField>
                            <FilterField label={label('from')}>
                                <Input type="date" value={values.from} onChange={set('from')} />
                            </FilterField>
                            <FilterField label={label('to')}>
                                <Input type="date" value={values.to} onChange={set('to')} />
                            </FilterField>
                        </FilterPanel>

                        <TableCard>
                            {issues.data.length === 0 ? <EmptyState title={label('noIssues')} /> : (
                                <ul className="divide-y divide-(--app-border)">
                                    {issues.data.map((issue) => (
                                        <li key={issue.id} className="flex flex-wrap items-start justify-between gap-3 px-4 py-4">
                                            <div className="min-w-0">
                                                <p className="font-medium text-(--app-foreground)">
                                                    <StatusBadge status={SEVERITY_TONE[issue.severity]} label={label(`severities.${issue.severity}`)} />{' '}
                                                    {t(`core.dataQuality.checks.${issue.check_code}`)}
                                                </p>
                                                <p className="mt-1 text-xs text-(--app-muted-foreground)">
                                                    {label(`kinds.${issue.entity_type}`)} · {issue.entity_url ? <Link href={issue.entity_url} className={actionLinkClassName({ className: 'font-mono' })}>{issue.entity_id}</Link> : <span className="font-mono">{issue.entity_id}</span>}
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
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <ListPagination meta={laravelPageMeta(issues.meta)} onPageChange={(page) => router.get(url, { tab, ...filters, page }, { preserveScroll: true, preserveState: true })} />
                </TableCard>
                    </>
                )}
            </div>

            <AppDialog open={stepping !== null} onClose={() => setStepping(null)} title={stepping ? label(`steps.${stepping.step}`) : ''} description={label('stepDescription')}
                footer={<><Button variant="outline" onClick={() => setStepping(null)}>{label('cancel')}</Button><Button variant="primary" type="submit" form="dq-step-form" loading={form.processing}>{label('confirm')}</Button></>}>
                <form id="dq-step-form" onSubmit={submitStep}>
                    <FieldError className="mb-2">{(form.errors as Record<string, string | undefined>).issue}</FieldError>
                    <FormField label={label('note')} required={stepping?.step !== 'investigate'} error={form.errors.note}>
                        {({ id }) => <Textarea id={id} rows={3} maxLength={2000} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />}
                    </FormField>
                </form>
            </AppDialog>
        </AuthenticatedLayout>
    );
}
