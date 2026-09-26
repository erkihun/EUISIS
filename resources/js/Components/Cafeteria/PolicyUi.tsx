import { Link, router } from '@inertiajs/react';
import { Alert, AppDialog, Button, StatusBadge, type Tone } from '@euisis/ui';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { useLocale } from '@/hooks/useLocale';
import { useState, type ReactNode } from 'react';

/**
 * A workflow action (approve, end, cancel, finalize…) behind a confirmation
 * dialog, optionally asking for a date (dual calendar) or a reason.
 */
export function ActionButton({ label, url, field, description, destructive = false, primary = false }: {
    label: string;
    url: string;
    field?: 'effective_to' | 'reason';
    description?: string;
    destructive?: boolean;
    primary?: boolean;
}) {
    const { t } = useLocale();
    const [open, setOpen] = useState(false);
    const [value, setValue] = useState(field === 'effective_to' ? new Date().toISOString().slice(0, 10) : '');
    const [busy, setBusy] = useState(false);

    const confirm = () => {
        setBusy(true);
        router.post(url, field ? { [field]: value } : {}, {
            preserveScroll: true,
            onFinish: () => { setBusy(false); setOpen(false); },
        });
    };

    return (
        <>
            <Button type="button" size="sm" variant={destructive ? 'destructive' : primary ? 'primary' : 'outline'} onClick={() => setOpen(true)}>{label}</Button>
            <AppDialog open={open} onClose={() => setOpen(false)} title={label} description={description ?? t('cafeteriaPolicy.confirmTitle')}
                footer={<>
                    <Button type="button" variant="ghost" onClick={() => setOpen(false)}>{t('cafeteriaPolicy.cancel')}</Button>
                    <Button type="button" variant={destructive ? 'destructive' : 'primary'} disabled={busy || (field === 'effective_to' && !value)} onClick={confirm}>{label}</Button>
                </>}>
                {field === 'effective_to' && (
                    <label className="block space-y-1 text-sm">
                        <span className="font-medium">{t('cafeteriaPolicy.endOn')}</span>
                        <LocalizedDatePicker id="action-date" className={inputCls} value={value} onChange={(v: string) => setValue(v)} />
                    </label>
                )}
                {field === 'reason' && (
                    <label className="block space-y-1 text-sm">
                        <span className="font-medium">{t('cafeteriaPolicy.reason')}</span>
                        <textarea className={inputCls} rows={3} value={value} onChange={(e) => setValue(e.target.value)} />
                    </label>
                )}
            </AppDialog>
        </>
    );
}

/** Shared building blocks for the cafeteria network / access / policy / settlement pages. */

export type NamePair = { id: string; code?: string | null; name_en?: string | null; name_am?: string | null } | null;

export type Meta = { currentPage: number; lastPage: number; total: number; perPage: number };

export const inputCls = 'w-full rounded-[var(--radius-control)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-3 py-2 text-sm text-[color:var(--app-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] disabled:opacity-60';

export function useNames() {
    const { locale, t } = useLocale();
    const label = (pair: NamePair | undefined, fallback = '—'): string => {
        if (!pair) return fallback;
        const name = locale === 'am' ? pair.name_am || pair.name_en : pair.name_en || pair.name_am;
        return name || pair.code || fallback;
    };
    const status = (value: string | null | undefined) => (value ? t(`cafeteriaPolicy.statuses.${value}`) : '—');
    return { label, status };
}

const STATUS_TONES: Record<string, Tone> = {
    active: 'success', approved: 'info', finalized: 'success', open: 'success',
    pending_approval: 'warning', under_review: 'warning', draft: 'neutral', temporarily_closed: 'warning',
    suspended: 'danger', ended: 'neutral', cancelled: 'neutral', superseded: 'neutral', expired: 'neutral',
    inactive: 'neutral', closed: 'danger',
};

export function StatusPill({ status }: { status: string | null | undefined }) {
    const { status: label } = useNames();
    return <StatusBadge tone={STATUS_TONES[status ?? ''] ?? 'neutral'}>{label(status)}</StatusBadge>;
}

export function Money({ value, currency = 'ETB' }: { value: string | number | null | undefined; currency?: string | null }) {
    if (value === null || value === undefined || value === '') return <>—</>;
    const amount = Number(value);
    return <span className="tabular-nums">{amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} {currency ?? ''}</span>;
}

export function SelectOptions({ items, placeholder }: { items: Array<NamePair & { id: string }>; placeholder?: string }) {
    const { label } = useNames();
    return (
        <>
            <option value="">{placeholder ?? '—'}</option>
            {items.map((item) => (
                <option key={item.id} value={item.id}>{label(item)}{item.code ? ` (${item.code})` : ''}</option>
            ))}
        </>
    );
}

/** Adapts the server's page meta to the DataTable pagination. */
export function pageTo(url: string, filters: Record<string, unknown>) {
    return (page: number) => router.get(url, { ...filters, page }, { preserveState: true, preserveScroll: true });
}

type HealthItem = { id: string; organization?: NamePair; provider?: NamePair; network?: NamePair; organization_id?: string; effective_to?: string; version_no?: number };
export type Health = {
    missing_policy: HealthItem[];
    access_without_policy: HealthItem[];
    policy_without_access: HealthItem[];
    expiring: HealthItem[];
    pending: { access: number; assignments: number; policies: number };
};

/** Dashboard alert panel: configuration errors that would block or mis-bill scans. */
export function ConfigurationHealthPanel({ health }: { health: Health }) {
    const { t } = useLocale();
    const { label } = useNames();
    const problems = health.missing_policy.length + health.access_without_policy.length + health.policy_without_access.length;
    const pending = health.pending.access + health.pending.assignments + health.pending.policies;

    const List = ({ title, items, render, href }: { title: string; items: HealthItem[]; render: (item: HealthItem) => ReactNode; href: string }) => items.length === 0 ? null : (
        <div>
            <p className="text-sm font-semibold">{title} ({items.length})</p>
            <ul className="mt-1 list-inside list-disc text-sm">
                {items.slice(0, 5).map((item) => <li key={item.id}>{render(item)}</li>)}
            </ul>
            <Link href={href} className="mt-1 inline-block text-xs font-medium text-[color:var(--color-primary)] hover:underline">{t('cafeteriaPolicy.view')} →</Link>
        </div>
    );

    return (
        <section aria-labelledby="cafeteria-health" className="space-y-3">
            <h2 id="cafeteria-health" className="text-base font-semibold text-[color:var(--app-foreground)]">{t('cafeteriaPolicy.healthTitle')}</h2>
            {problems === 0 && health.expiring.length === 0 && <Alert tone="success">{t('cafeteriaPolicy.healthOk')}</Alert>}
            {problems > 0 && (
                <Alert tone="danger">
                    <div className="grid gap-4 md:grid-cols-3">
                        <List title={t('cafeteriaPolicy.missingPolicy')} items={health.missing_policy} href={route('cafeteria.assignments.index')}
                            render={(i) => `${label(i.organization)} — ${label(i.provider)}`} />
                        <List title={t('cafeteriaPolicy.accessWithoutPolicy')} items={health.access_without_policy} href={route('cafeteria.access.index')}
                            render={(i) => `${label(i.organization)} — ${label(i.network)}`} />
                        <List title={t('cafeteriaPolicy.policyWithoutAccess')} items={health.policy_without_access} href={route('cafeteria.policies.index')}
                            render={(i) => `v${i.version_no}`} />
                    </div>
                </Alert>
            )}
            {health.expiring.length > 0 && (
                <Alert tone="warning" title={t('cafeteriaPolicy.expiringPolicies')}>
                    <ul className="list-inside list-disc text-sm">
                        {health.expiring.slice(0, 5).map((i) => <li key={i.id}><Link className="hover:underline" href={route('cafeteria.policies.show', i.id)}>{i.effective_to}</Link></li>)}
                    </ul>
                </Alert>
            )}
            {pending > 0 && (
                <Alert tone="info" title={t('cafeteriaPolicy.pendingDecisions')}>
                    <div className="flex flex-wrap gap-4 text-sm">
                        <Link href={route('cafeteria.access.index', { status: 'pending_approval' })} className="hover:underline">{t('cafeteriaPolicy.navAccess')}: {health.pending.access}</Link>
                        <Link href={route('cafeteria.assignments.index', { status: 'pending_approval' })} className="hover:underline">{t('cafeteriaPolicy.navAssignments')}: {health.pending.assignments}</Link>
                        <Link href={route('cafeteria.policies.index', { status: 'under_review' })} className="hover:underline">{t('cafeteriaPolicy.navPolicies')}: {health.pending.policies}</Link>
                    </div>
                </Alert>
            )}
        </section>
    );
}
