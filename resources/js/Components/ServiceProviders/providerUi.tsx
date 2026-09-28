import { useLocale } from '@/hooks/useLocale';
import { StatusBadge, cx, type Tone } from '@euisis/ui';
import type { JSX } from 'react';

export type NamedRef = { id?: string; code?: string; name_en: string; name_am?: string | null } | null | undefined;

export type ProviderStatus = 'active' | 'inactive' | 'suspended';

export const PROVIDER_STATUSES: ProviderStatus[] = ['active', 'inactive', 'suspended'];

/** Bilingual record name for the current locale. */
export function useLocalName(): (ref: NamedRef, fallback?: string) => string {
    const { locale } = useLocale();
    return (ref, fallback = '—') => (ref ? (locale === 'am' ? (ref.name_am || ref.name_en) : ref.name_en) : fallback);
}

/*
 * One tint per service type so a provider is recognisable by its avatar
 * before its name is read. Unknown types fall back to neutral.
 */
const TYPE_TINTS: Record<string, string> = {
    cafeteria: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    transport: 'bg-[color:var(--color-primary-100)] text-[color:var(--color-primary)] dark:bg-[color:var(--color-primary-900)] dark:text-[color:var(--color-primary-200)]',
    health: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    consumer_association: 'bg-pink-100 text-pink-800 dark:bg-pink-900/40 dark:text-pink-200',
    insurance: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    training: 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200',
};

export function typeTint(code: string | undefined): string {
    return (code && TYPE_TINTS[code]) || 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]';
}

export function initials(name: string | null | undefined): string {
    const letters = (name ?? '')
        .split(/\s+/)
        .filter((word) => /^[\p{L}\p{N}]/u.test(word))
        .slice(0, 2)
        .map((word) => word.charAt(0).toUpperCase())
        .join('');
    return letters || '?';
}

export function ProviderAvatar({ name, typeCode, size = 'md' }: { name: string | null | undefined; typeCode?: string; size?: 'md' | 'lg' }): JSX.Element {
    return (
        <span
            aria-hidden="true"
            className={cx(
                'inline-flex shrink-0 items-center justify-center font-bold',
                size === 'lg' ? 'h-[52px] w-[52px] rounded-xl text-lg' : 'h-9 w-9 rounded-lg text-[13px]',
                typeTint(typeCode),
            )}
        >
            {initials(name)}
        </span>
    );
}

const providerTones: Record<string, Tone> = { active: 'success', inactive: 'neutral', suspended: 'danger' };
const providerDots: Record<string, string> = { active: 'bg-emerald-600', inactive: 'bg-slate-400', suspended: 'bg-red-600' };

export function providerStatusKey(status: string): string {
    return `providers.status${status.charAt(0).toUpperCase()}${status.slice(1)}`;
}

export function ProviderStatusBadge({ status }: { status: string }): JSX.Element {
    const { t } = useLocale();
    return (
        <StatusBadge tone={providerTones[status] ?? 'neutral'} className="gap-1.5 whitespace-nowrap">
            <span aria-hidden="true" className={cx('h-1.5 w-1.5 rounded-full', providerDots[status] ?? 'bg-slate-400')} />
            {t(providerStatusKey(status))}
        </StatusBadge>
    );
}

export function StatusDot({ status }: { status: string }): JSX.Element {
    return <span aria-hidden="true" className={cx('inline-block h-2 w-2 rounded-full', providerDots[status] ?? 'bg-slate-400')} />;
}

const transactionTones: Record<string, Tone> = {
    settled: 'success',
    authorized: 'info',
    denied: 'danger',
    pending_sync: 'warning',
    reversed: 'neutral',
};

export const TRANSACTION_DOTS: Record<string, string> = {
    settled: 'bg-emerald-600',
    authorized: 'bg-[color:var(--color-primary-500)]',
    denied: 'bg-red-600',
    pending_sync: 'bg-amber-500',
    reversed: 'bg-slate-400',
};

export function transactionStatusKey(status: string): string {
    const camel = status.replace(/_(\w)/g, (_, letter: string) => letter.toUpperCase());
    return `providers.tx${camel.charAt(0).toUpperCase()}${camel.slice(1)}`;
}

export function TransactionStatusBadge({ status }: { status: string }): JSX.Element {
    const { t } = useLocale();
    return (
        <StatusBadge tone={transactionTones[status] ?? 'neutral'} className="whitespace-nowrap">
            {t(transactionStatusKey(status))}
        </StatusBadge>
    );
}

const money = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Birr amount with two decimals; empty amounts render as a dash. */
export function formatEtb(amount: string | number | null | undefined): string {
    if (amount === null || amount === undefined || amount === '') return '—';
    const value = typeof amount === 'number' ? amount : Number(amount);
    return Number.isFinite(value) ? `ETB ${money.format(value)}` : '—';
}

export function DemoTag(): JSX.Element {
    const { t } = useLocale();
    return (
        <span className="inline-flex h-[18px] items-center rounded px-1.5 text-[11px] font-semibold text-violet-800 ring-1 ring-inset ring-violet-200 bg-violet-50 dark:bg-violet-950/40 dark:text-violet-200 dark:ring-violet-900">
            {t('providers.demo')}
        </span>
    );
}
