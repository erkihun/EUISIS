import { badgeClassName, buttonClassName, cx, inputClassName, textareaClassName } from '@/Components/ui';
import type { Tone } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { Bilingual, Labelled } from '@/modules/reporting/types';

/* Form and surface classes, from the application's one visual system. */
export const inputCls = cx(inputClassName, 'w-full');
export const textareaCls = cx(textareaClassName, 'w-full');
export const primaryBtn = buttonClassName({ variant: 'primary' });
export const secondaryBtn = buttonClassName({ variant: 'outline' });
export const dangerBtn = buttonClassName({ variant: 'destructiveOutline' });
export const panelCls = 'rounded-panel border border-(--app-border) bg-(--app-surface)';
export const labelCls = 'mb-1 block text-xs font-medium text-(--app-muted-foreground)';
export const errorCls = 'mt-1 text-xs text-red-700 dark:text-red-400';
export const mutedCls = 'text-sm text-(--app-muted-foreground)';
export const sectionTitleCls = 'text-base font-semibold text-(--app-foreground)';
export const badge = badgeClassName;

/** The business timezone. Every moment is stored in UTC and shown in this zone, labelled. */
export const ZONE = 'Africa/Addis_Ababa';

const ETHIOPIAN_MONTHS_AM = ['መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'];
const ETHIOPIAN_MONTHS_EN = ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazya', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'];

/** The Ethiopian calendar date of a Gregorian civil date (the server's EthiopianDate, for display only). */
export function toEthiopian(year: number, month: number, day: number): { year: number; month: number; day: number } {
    const a = Math.trunc((month - 14) / 12);
    const jdn = Math.trunc((1461 * (year + 4800 + a)) / 4) + Math.trunc((367 * (month - 2 - 12 * a)) / 12) - Math.trunc((3 * Math.trunc((year + 4900 + a) / 100)) / 4) + day - 32075;
    const days = jdn - 1723856;
    const r = ((days % 1461) + 1461) % 1461;
    const n = (r % 365) + 365 * Math.trunc(r / 1460);

    return { year: 4 * Math.floor(days / 1461) + Math.trunc(r / 365) - Math.trunc(r / 1460), month: Math.trunc(n / 30) + 1, day: (n % 30) + 1 };
}

function localParts(iso: string): { year: number; month: number; day: number; time: string; date: string } {
    const parts = new Intl.DateTimeFormat('en-GB', { timeZone: ZONE, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(iso));
    const get = (type: string): string => parts.find((p) => p.type === type)?.value ?? '';

    return { year: Number(get('year')), month: Number(get('month')), day: Number(get('day')), time: `${get('hour')}:${get('minute')}`, date: `${get('year')}-${get('month')}-${get('day')}` };
}

/** The value a `datetime-local` or `date` input needs, in business time. */
export function toLocalInput(iso: string | null | undefined, withTime = true): string {
    if (!iso) return '';
    const p = localParts(iso);

    return withTime ? `${p.date}T${p.time}` : p.date;
}

export function formatBytes(bytes: number | null | undefined): string {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function fill(template: string, params: Record<string, string | number>): string {
    return template.replace(/\{(\w+)\}/g, (match, key: string) => (key in params ? String(params[key]) : match));
}

/**
 * The module's translator and formatters.
 *
 * `rt('register.title')` reads reporting.register.title. Moments are shown in
 * Africa/Addis_Ababa with the zone named (EAT); in Amharic the Ethiopian
 * calendar date comes first and the Gregorian date follows. The calendar a
 * schedule recurs on is the schedule's own setting: switching the interface
 * language changes how a date is written, never the instant.
 */
export function useReporting() {
    const { t, locale } = useLocale();
    const am = locale === 'am';
    const rt = (key: string, params?: Record<string, string | number>): string => {
        const text = t(`reporting.${key}`);

        return params ? fill(text, params) : text;
    };
    const name = (value: Bilingual | null | undefined): string => {
        if (!value) return '—';

        return (am ? value.name_am || value.name_en : value.name_en || value.name_am) ?? '—';
    };
    const label = (value: Labelled | null | undefined): string => {
        if (!value) return '—';

        return (am ? value.label_am || value.label_en : value.label_en || value.label_am) ?? '—';
    };
    /** Who owes a report: the institution, and the work unit inside it when the report is a unit's own. */
    const subject = (row: { organization: Bilingual | null | undefined; unit?: Bilingual | null }): string => (row.unit ? `${name(row.organization)} — ${name(row.unit)}` : name(row.organization));
    const pair = (en: string | null | undefined, amharic: string | null | undefined): string => (am ? amharic || en : en || amharic) ?? '';
    const date = (iso: string | null | undefined): string => {
        if (!iso) return '—';
        const p = localParts(iso);
        const e = toEthiopian(p.year, p.month, p.day);

        return am ? `${ETHIOPIAN_MONTHS_AM[e.month - 1]} ${e.day} ቀን ${e.year} ዓ.ም (${p.date})` : `${p.date} (${e.day} ${ETHIOPIAN_MONTHS_EN[e.month - 1]} ${e.year} EC)`;
    };
    const moment = (iso: string | null | undefined): string => {
        if (!iso) return '—';
        const p = localParts(iso);
        const e = toEthiopian(p.year, p.month, p.day);

        return am ? `${ETHIOPIAN_MONTHS_AM[e.month - 1]} ${e.day} ቀን ${e.year} ዓ.ም ${p.time} EAT (${p.date})` : `${p.date} ${p.time} EAT`;
    };
    /**
     * A moment in two short lines for tables and cards: the date in the reader's calendar first,
     * then the time with its zone and the other calendar. The zone is always named.
     */
    const whenParts = (iso: string | null | undefined, withTime = true): { primary: string; secondary: string } | null => {
        if (!iso) return null;
        const p = localParts(iso);
        const e = toEthiopian(p.year, p.month, p.day);
        const time = withTime ? `${p.time} EAT · ` : '';

        return am
            ? { primary: `${ETHIOPIAN_MONTHS_AM[e.month - 1]} ${e.day} ቀን ${e.year}`, secondary: `${time}${p.date}` }
            : { primary: p.date, secondary: `${time}${e.day} ${ETHIOPIAN_MONTHS_EN[e.month - 1]} ${e.year} EC` };
    };
    /** "N/A" for a zero denominator: never 0 % or 100 %. */
    const percent = (rate: number | null | undefined): string => (rate === null || rate === undefined ? rt('common.notApplicable') : `${(rate * 100).toFixed(1)}%`);
    /** Whole days from now to a moment; negative once it has passed. */
    const daysUntil = (iso: string): number => Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000);
    const dueHint = (iso: string): string => {
        const days = daysUntil(iso);
        if (days > 1) return rt('common.dueInDays', { days });
        if (days === 1) return rt('common.dueTomorrow');
        if (days === 0) return rt('common.dueToday');

        return rt('common.overdueByDays', { days: Math.abs(days) });
    };

    return { rt, t, locale, am, name, subject, label, pair, date, moment, whenParts, percent, daysUntil, dueHint };
}

export function workflowTone(status: string): Tone {
    switch (status) {
        case 'approved':
            return 'success';
        case 'submitted':
        case 'under_review':
            return 'info';
        case 'returned':
        case 'draft':
            return 'warning';
        case 'rejected':
            return 'danger';
        default:
            return 'neutral';
    }
}

export function timelinessTone(value: string | null): Tone {
    switch (value) {
        case 'on_time':
        case 'early':
            return 'success';
        case 'late':
            return 'warning';
        case 'overdue':
            return 'danger';
        default:
            return 'neutral';
    }
}

export function priorityTone(priority: string): Tone {
    return priority === 'urgent' ? 'danger' : priority === 'high' ? 'warning' : 'neutral';
}

/** Reads an XSRF-protected JSON endpoint's refusal as the message the server sent. */
export function serverMessage(error: unknown, fallback: string): string {
    const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response;
    const first = response?.data?.errors ? Object.values(response.data.errors)[0]?.[0] : undefined;

    return first ?? response?.data?.message ?? fallback;
}
