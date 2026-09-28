import { FormEvent, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppMetricCard from '@/Components/ui/AppMetricCard';
import ApplicationFields from '@/Components/apiManagement/ApplicationFields';
import EndpointAssignment, { AssignableEndpoint } from '@/Components/apiManagement/EndpointAssignment';
import TokenReveal from '@/Components/apiManagement/TokenReveal';
import { ActivityIcon, ChevronRight, ComponentIcon, KeyIcon, NetworkIcon, Plus, ScrollText, ShieldCheck } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { formatRelative } from '@/lib/relativeTime';
import { Alert, Button, Card, EmptyState, SearchInput, cx } from '@euisis/ui';

type ScopeOption = { value: string; label: string };

type ApplicationRow = {
    id: string;
    name: string;
    code: string;
    owner_institution: string | null;
    status: string;
    allowed_scopes: string[];
    rate_limit_per_minute: number;
    tokens_count: number;
    last_used_at: string | null;
};

interface Props {
    applications: ApplicationRow[];
    scopes: ScopeOption[];
    assignableEndpoints: AssignableEndpoint[];
    endpoints_count: number;
    can: {
        create: boolean;
        update: boolean;
        delete: boolean;
        createTokens: boolean;
        revokeTokens: boolean;
        viewLogs: boolean;
        viewDocs: boolean;
        viewEndpoints: boolean;
    };
}

const STATUS_FILTERS = ['all', 'active', 'suspended', 'revoked'] as const;
type StatusFilter = (typeof STATUS_FILTERS)[number];

/** Section navigation shared across API Management; this page is "Applications". */
function SectionNav({ can }: { can: Props['can'] }) {
    const { t } = useLocale();
    const items = [
        { href: route('api-management.index'), label: t('apiManagement.applications'), Icon: ComponentIcon, active: true, show: true },
        { href: route('api-management.endpoints'), label: t('apiManagement.apiEndpoints'), Icon: NetworkIcon, active: false, show: can.viewEndpoints },
        { href: route('api-management.logs'), label: t('apiManagement.apiLogs'), Icon: ActivityIcon, active: false, show: can.viewLogs },
        { href: route('api-management.docs'), label: t('apiManagement.apiDocumentation'), Icon: ScrollText, active: false, show: can.viewDocs },
    ].filter((item) => item.show);

    return (
        <nav aria-label={t('apiManagement.sections')} className="flex gap-1 overflow-x-auto border-b border-[color:var(--app-border)]">
            {items.map(({ href, label, Icon, active }) => (
                <Link key={href} href={href} aria-current={active ? 'page' : undefined}
                    className={cx('-mb-px inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2 text-sm transition-colors',
                        active ? 'border-[color:var(--color-primary)] font-medium text-[color:var(--color-primary)]'
                            : 'border-transparent text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]')}>
                    <Icon className="h-4 w-4" aria-hidden="true" />{label}
                </Link>
            ))}
        </nav>
    );
}

function MetricLink({ href, children }: { href: string; children: ReactNode }) {
    return <Link href={href} className="block rounded-card focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">{children}</Link>;
}

/** One numbered block of the registration form. */
function FormStep({ step, title, description, children }: { step: number; title: string; description?: string; children: ReactNode }) {
    return (
        <section className="grid gap-4 px-5 py-5 lg:grid-cols-[14rem_1fr] lg:gap-8">
            <div>
                <p className="text-xs font-semibold tabular-nums text-[color:var(--color-primary)]">{String(step).padStart(2, '0')}</p>
                <h3 className="text-sm font-semibold text-[color:var(--app-foreground)]">{title}</h3>
                {description && <p className="mt-1 text-xs leading-relaxed text-[color:var(--app-muted-foreground)]">{description}</p>}
            </div>
            <div className="min-w-0">{children}</div>
        </section>
    );
}

export default function ApiManagementIndex({ applications, scopes, assignableEndpoints, endpoints_count, can }: Props) {
    const { t, locale } = useLocale();
    const flash = (usePage().props as { flash?: { generated_token?: string } }).flash;
    const [showForm, setShowForm] = useState(false);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<StatusFilter>('all');
    const formRef = useRef<HTMLDivElement>(null);

    const form = useForm({
        name: '',
        code: '',
        owner_institution: '',
        contact_person: '',
        contact_email: '',
        callback_url: '',
        status: 'active',
        allowed_scopes: [] as string[],
        rate_limit_per_minute: 60,
        allowed_ips: [] as string[],
        endpoint_ids: [] as string[],
    });

    // Scopes the server will add automatically because a selected endpoint
    // asserts them. Surfaced so the saved scope list is never a surprise.
    const autoScopes = useMemo(() => {
        const required = new Set(
            assignableEndpoints
                .filter((endpoint) => form.data.endpoint_ids.includes(endpoint.id))
                .map((endpoint) => endpoint.required_scope)
                .filter(Boolean) as string[],
        );

        return [...required].filter((scope) => !form.data.allowed_scopes.includes(scope));
    }, [assignableEndpoints, form.data.endpoint_ids, form.data.allowed_scopes]);

    const counts = useMemo(() => {
        const byStatus: Record<StatusFilter, number> = { all: applications.length, active: 0, suspended: 0, revoked: 0 };
        applications.forEach((application) => {
            if (application.status in byStatus) byStatus[application.status as StatusFilter] += 1;
        });
        return byStatus;
    }, [applications]);

    const visible = useMemo(() => {
        const needle = search.trim().toLowerCase();
        return applications.filter((application) => (status === 'all' || application.status === status)
            && (!needle || `${application.name} ${application.code} ${application.owner_institution ?? ''}`.toLowerCase().includes(needle)));
    }, [applications, search, status]);

    const tokenTotal = applications.reduce((total, application) => total + application.tokens_count, 0);
    const statusSummary = (['active', 'suspended', 'revoked'] as const)
        .filter((key) => counts[key] > 0).map((key) => `${counts[key]} ${t(`common.${key}`)}`).join(' · ');

    useEffect(() => {
        if (showForm) formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [showForm]);

    function toggleScope(scope: string) {
        form.setData(
            'allowed_scopes',
            form.data.allowed_scopes.includes(scope)
                ? form.data.allowed_scopes.filter((value) => value !== scope)
                : [...form.data.allowed_scopes, scope],
        );
    }

    function closeForm() {
        setShowForm(false);
        form.clearErrors();
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route('api-management.store'), { onSuccess: () => setShowForm(false) });
    }

    const newApplication = can.create
        ? <Button variant="primary" icon={<Plus className="h-4 w-4" />} onClick={() => setShowForm(true)}>{t('apiManagement.newApplication')}</Button>
        : undefined;
    const lastUsed = (value: string | null) => formatRelative(value, locale);
    const cell = 'px-4 py-3';

    return (
        <AuthenticatedLayout header={<PageHeader title={t('apiManagement.title')} description={t('apiManagement.description')} backHref={route('system-settings.index')} actions={newApplication} />}>
            <Head title={t('apiManagement.title')} />

            <div className="space-y-6">
                <SectionNav can={can} />

                {/* Shown exactly once, immediately after generation. */}
                {flash?.generated_token && <TokenReveal key={flash.generated_token} token={flash.generated_token} hint={t('apiManagement.tokenEnvHint')} />}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <AppMetricCard label={t('apiManagement.externalApplications')} value={applications.length} variant="primary"
                        icon={<ComponentIcon className="h-5 w-5" />} detail={statusSummary || t('apiManagement.externalApplicationsHint')} />
                    <AppMetricCard label={t('apiManagement.apiTokens')} value={tokenTotal} variant="accent"
                        icon={<KeyIcon className="h-5 w-5" />} detail={t('apiManagement.apiTokensHint')} />
                    {can.viewEndpoints && (
                        <MetricLink href={route('api-management.endpoints')}>
                            <AppMetricCard label={t('apiManagement.apiEndpoints')} value={endpoints_count} variant="success"
                                icon={<NetworkIcon className="h-5 w-5" />} detail={t('apiManagement.apiEndpointsHint')} />
                        </MetricLink>
                    )}
                    {can.viewDocs && (
                        <MetricLink href={route('api-management.docs')}>
                            <AppMetricCard label={t('apiManagement.apiScopes')} value={scopes.length} variant="neutral"
                                icon={<ShieldCheck className="h-5 w-5" />} detail={t('apiManagement.apiScopesHint')} />
                        </MetricLink>
                    )}
                </div>

                {showForm && can.create && (
                    <div ref={formRef} className="scroll-mt-6">
                        <form onSubmit={submit}>
                            <Card className="overflow-hidden p-0">
                                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-[color:var(--app-border)] px-5 py-4">
                                    <div>
                                        <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{t('apiManagement.registerApplication')}</h2>
                                        <p className="mt-1 max-w-2xl text-sm text-[color:var(--app-muted-foreground)]">{t('apiManagement.registerApplicationHint')}</p>
                                    </div>
                                </div>
                                <div className="divide-y divide-[color:var(--app-border)]">
                                    <FormStep step={1} title={t('apiManagement.applicationDetails')}>
                                        <ApplicationFields values={form.data} onChange={(values) => form.setData({ ...form.data, ...values })} errors={form.errors} disabled={form.processing} />
                                    </FormStep>
                                    <FormStep step={2} title={t('apiManagement.apiScopes')} description={t('apiManagement.scopesHint')}>
                                        <fieldset>
                                            <legend className="sr-only">{t('apiManagement.apiScopes')}</legend>
                                            <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                                {scopes.map((scope) => {
                                                    const checked = form.data.allowed_scopes.includes(scope.value);
                                                    return (
                                                        <label key={scope.value} className={cx('flex cursor-pointer items-start gap-3 rounded-[var(--radius-control)] border px-3 py-2.5 text-sm transition-colors',
                                                            checked ? 'border-[color:var(--color-primary)] bg-[color:var(--color-primary-50)] dark:bg-[color:var(--color-primary-950)]'
                                                                : 'border-[color:var(--app-border)] hover:bg-[color:var(--app-surface-muted)]')}>
                                                            <input type="checkbox" className="mt-0.5 rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]"
                                                                checked={checked} onChange={() => toggleScope(scope.value)} />
                                                            <span className="min-w-0">
                                                                <span className="block font-medium text-[color:var(--app-foreground)]">{scope.label}</span>
                                                                {scope.label !== scope.value && <code className="block truncate font-mono text-xs text-[color:var(--app-muted-foreground)]">{scope.value}</code>}
                                                            </span>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                            <p className="mt-2 text-xs text-[color:var(--app-muted-foreground)]">{form.data.allowed_scopes.length} / {scopes.length} {t('apiManagement.scopesSelected')}</p>
                                        </fieldset>
                                    </FormStep>
                                    <FormStep step={3} title={t('apiManagement.endpointAssignment')} description={t('apiManagement.endpointAssignmentHint')}>
                                        <EndpointAssignment endpoints={assignableEndpoints} selected={form.data.endpoint_ids} onChange={(ids) => form.setData('endpoint_ids', ids)} />
                                        {autoScopes.length > 0 && (
                                            <Alert tone="info" className="mt-3">
                                                {t('apiManagement.scopesAutoAdded')} <code className="font-mono text-xs">{autoScopes.join(', ')}</code>
                                            </Alert>
                                        )}
                                    </FormStep>
                                </div>
                                {Object.keys(form.errors).length > 0 && (
                                    <div className="px-5 pb-4"><Alert tone="danger" title={t('apiManagement.formErrors')}>{Object.values(form.errors).join(' ')}</Alert></div>
                                )}
                                <div className="flex flex-wrap justify-end gap-2 border-t border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-5 py-4">
                                    <Button variant="outline" onClick={closeForm} disabled={form.processing}>{t('common.cancel')}</Button>
                                    <Button type="submit" variant="primary" loading={form.processing}>{t('apiManagement.saveApplication')}</Button>
                                </div>
                            </Card>
                        </form>
                    </div>
                )}

                <Card className="overflow-hidden p-0">
                    <div className="flex flex-col gap-3 border-b border-[color:var(--app-border)] px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{t('apiManagement.externalApplications')}</h2>
                            <p className="mt-0.5 text-sm text-[color:var(--app-muted-foreground)]">{t('apiManagement.externalApplicationsHint')}</p>
                        </div>
                        {applications.length > 0 && (
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <div role="group" aria-label={t('common.status')} className="inline-flex w-fit rounded-[var(--radius-control)] bg-[color:var(--app-surface-muted)] p-0.5">
                                    {STATUS_FILTERS.map((key) => (
                                        <button key={key} type="button" aria-pressed={status === key} onClick={() => setStatus(key)}
                                            className={cx('rounded-[calc(var(--radius-control)-2px)] px-2.5 py-1 text-xs font-medium transition-colors',
                                                status === key ? 'bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] shadow-sm'
                                                    : 'text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]')}>
                                            {key === 'all' ? t('common.all') : t(`common.${key}`)} <span className="tabular-nums opacity-70">{counts[key]}</span>
                                        </button>
                                    ))}
                                </div>
                                <SearchInput value={search} onChange={setSearch} className="sm:w-72" label={t('apiManagement.searchApplications')} placeholder={t('apiManagement.searchApplications')} />
                            </div>
                        )}
                    </div>

                    {applications.length === 0 ? (
                        <EmptyState icon={<ComponentIcon className="h-8 w-8" />} title={t('apiManagement.noApplications')} description={t('apiManagement.noApplicationsHint')} action={newApplication} />
                    ) : visible.length === 0 ? (
                        <EmptyState title={t('apiManagement.noMatchingApplications')}
                            action={<Button variant="outline" size="sm" onClick={() => { setSearch(''); setStatus('all'); }}>{t('apiManagement.clearFilters')}</Button>} />
                    ) : (
                        <>
                            <div className="hidden overflow-x-auto md:block">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-[color:var(--app-surface-muted)] text-xs text-[color:var(--app-muted-foreground)]">
                                        <tr>
                                            <th scope="col" className={cx(cell, 'pl-5 font-medium')}>{t('apiManagement.application')}</th>
                                            <th scope="col" className={cx(cell, 'font-medium')}>{t('common.status')}</th>
                                            <th scope="col" className={cx(cell, 'text-right font-medium')}>{t('apiManagement.apiScopes')}</th>
                                            <th scope="col" className={cx(cell, 'text-right font-medium')}>{t('apiManagement.rateLimit')}</th>
                                            <th scope="col" className={cx(cell, 'text-right font-medium')}>{t('apiManagement.apiTokens')}</th>
                                            <th scope="col" className={cx(cell, 'font-medium')}>{t('apiManagement.lastUsed')}</th>
                                            <th scope="col" className={cx(cell, 'pr-5')}><span className="sr-only">{t('apiManagement.openApplication')}</span></th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[color:var(--app-border)]">
                                        {visible.map((application) => (
                                            // The name link is the keyboard path; the whole row is a larger mouse target.
                                            <tr key={application.id} className="group cursor-pointer transition-colors hover:bg-[color:var(--app-surface-muted)]"
                                                onClick={() => router.visit(route('api-management.show', application.id))}>
                                                <td className={cx(cell, 'pl-5')}>
                                                    <Link href={route('api-management.show', application.id)} onClick={(event) => event.stopPropagation()}
                                                        className="font-medium text-[color:var(--app-foreground)] group-hover:text-[color:var(--color-primary)]">
                                                        {application.name}
                                                    </Link>
                                                    <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-[color:var(--app-muted-foreground)]">
                                                        <code className="font-mono">{application.code}</code>
                                                        {application.owner_institution && <span>· {application.owner_institution}</span>}
                                                    </div>
                                                </td>
                                                <td className={cell}><StatusBadge status={application.status} label={t(`common.${application.status}`)} /></td>
                                                <td className={cx(cell, 'text-right tabular-nums')} title={application.allowed_scopes.join(', ') || undefined}>{application.allowed_scopes.length}</td>
                                                <td className={cx(cell, 'text-right tabular-nums')}>{application.rate_limit_per_minute}<span className="text-[color:var(--app-muted-foreground)]">{t('apiManagement.perMinute')}</span></td>
                                                <td className={cx(cell, 'text-right tabular-nums')}>{application.tokens_count}</td>
                                                <td className={cx(cell, 'whitespace-nowrap')} title={application.last_used_at ?? undefined}>
                                                    {lastUsed(application.last_used_at) ?? <span className="text-[color:var(--app-muted-foreground)]">{t('apiManagement.neverUsed')}</span>}
                                                </td>
                                                <td className={cx(cell, 'pr-5 text-right')}><ChevronRight className="inline h-4 w-4 text-[color:var(--app-muted-foreground)] group-hover:text-[color:var(--color-primary)]" aria-hidden="true" /></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <ul className="divide-y divide-[color:var(--app-border)] md:hidden">
                                {visible.map((application) => (
                                    <li key={application.id}>
                                        <Link href={route('api-management.show', application.id)} className="flex items-start justify-between gap-3 px-5 py-4 hover:bg-[color:var(--app-surface-muted)]">
                                            <div className="min-w-0">
                                                <p className="font-medium text-[color:var(--app-foreground)]">{application.name}</p>
                                                <p className="mt-0.5 truncate text-xs text-[color:var(--app-muted-foreground)]">
                                                    <code className="font-mono">{application.code}</code>{application.owner_institution && ` · ${application.owner_institution}`}
                                                </p>
                                                <p className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-[color:var(--app-muted-foreground)]">
                                                    <span>{application.allowed_scopes.length} {t('apiManagement.scopesLabel')}</span>
                                                    <span>{application.rate_limit_per_minute}{t('apiManagement.perMinute')}</span>
                                                    <span>{application.tokens_count} {t('apiManagement.tokensLabel')}</span>
                                                    <span>{lastUsed(application.last_used_at) ?? t('apiManagement.neverUsed')}</span>
                                                </p>
                                            </div>
                                            <StatusBadge status={application.status} label={t(`common.${application.status}`)} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
