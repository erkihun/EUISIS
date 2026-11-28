import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import EmptyState from '@/Components/EmptyState';
import { X } from '@/Components/Icons';
import Modal from '@/Components/Modal';
import { Table, TableTools, TBody, Td, Th, THead, Tr, badgeClassName, buttonClassName, cx, inputClassName, textareaClassName } from '@/Components/ui';
import type { Tone } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { router, usePage } from '@inertiajs/react';
import { useState, type JSX, type ReactNode } from 'react';

/* Work experience (ADR 0040): the types, words and building blocks its four pages share. */

export interface Bilingual {
    name_en?: string | null;
    name_am?: string | null;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface EmployeeRef extends Bilingual {
    id: string;
    employee_number: string | null;
    status: string | null;
    has_left: boolean;
}

export interface Duration {
    years: number;
    months: number;
    days: number;
}

export interface ExperienceLine {
    key?: string;
    source?: 'assignment' | 'record';
    record_id?: string | null;
    assignment_id?: string | null;
    kind?: 'internal' | 'external';
    employer_am: string | null;
    employer_en: string | null;
    position_am: string | null;
    position_en: string | null;
    unit_am: string | null;
    unit_en: string | null;
    started_on: string;
    ended_on: string | null;
    monthly_salary: number | null;
    note?: string | null;
    evidence_name?: string | null;
    duration: Duration;
}

export type RequestStatus = 'submitted' | 'accepted' | 'returned' | 'sent' | 'cancelled';

export interface RequestRow {
    id: string;
    reference_number: string;
    employee: EmployeeRef | null;
    organization: (Bilingual & { id: string }) | null;
    addressee: string | null;
    purpose: string;
    language: 'am' | 'en';
    status: RequestStatus;
    registered_by_office: boolean;
    submitted_at: string | null;
    decided_at: string | null;
    decision_comment: string | null;
    certificate_number: string | null;
    print_count: number;
    printed_at: string | null;
    sent_at: string | null;
    delivery_note: string | null;
}

export const inputCls = cx(inputClassName, 'w-full');
export const textareaCls = cx(textareaClassName, 'w-full');
export const primaryBtn = buttonClassName({ variant: 'primary' });
export const secondaryBtn = buttonClassName({ variant: 'outline' });
export const dangerBtn = buttonClassName({ variant: 'destructiveOutline' });
export const labelCls = 'mb-1 block text-xs font-medium text-(--app-muted-foreground)';
export const errorCls = 'mt-1 text-xs text-red-700 dark:text-red-400';
export const mutedCls = 'text-sm text-(--app-muted-foreground)';
export const sectionTitleCls = 'text-base font-semibold text-(--app-foreground)';

const STATUS_TONE: Record<RequestStatus, Tone> = { submitted: 'warning', accepted: 'info', returned: 'danger', sent: 'success', cancelled: 'neutral' };

function fill(template: string, params: Record<string, string | number>): string {
    return template.replace(/\{(\w+)\}/g, (match, key: string) => (key in params ? String(params[key]) : match));
}

/** The module's translator and formatters. `wt('records.title')` reads workExperience.records.title. */
export function useExperience() {
    const { t, locale } = useLocale();
    const am = locale === 'am';
    const wt = (key: string, params?: Record<string, string | number>): string => {
        const text = t(`workExperience.${key}`);

        return params ? fill(text, params) : text;
    };
    const name = (value: Bilingual | null | undefined): string => {
        if (!value) return '—';

        return (am ? value.name_am || value.name_en : value.name_en || value.name_am) ?? '—';
    };
    /** The text of a line in the reader's language: `pick(line, 'position')`. */
    const pick = (line: ExperienceLine, field: 'employer' | 'position' | 'unit'): string => {
        const value = am ? line[`${field}_am`] || line[`${field}_en`] : line[`${field}_en`] || line[`${field}_am`];

        return value ?? '';
    };
    /** "3 years, 2 months, 5 days"; a part that is zero is left out. */
    const length = (duration: Duration | null | undefined): string => {
        if (!duration) return '—';
        const parts = (['years', 'months', 'days'] as const).filter((unit) => duration[unit] > 0).map((unit) => wt(`length.${unit}`, { n: duration[unit] }));

        return parts.length === 0 ? wt('length.days', { n: 0 }) : parts.join(wt('length.joiner'));
    };
    const money = (value: number | null | undefined): string =>
        value === null || value === undefined ? '—' : new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);

    return { wt, name, pick, length, money, am, locale };
}

const tones = {
    danger: 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100',
    warning: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100',
    info: 'border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100',
};

export function Notice({ tone = 'info', children, role = 'status' }: { tone?: keyof typeof tones; children: ReactNode; role?: 'status' | 'alert' }): JSX.Element {
    return <div role={role} className={cx('rounded-control border px-3 py-2 text-sm', tones[tone])}>{children}</div>;
}

/** What the server refused, shown at the top so a refusal is never silent. Fields named in `except` show their own error. */
export function ActionErrors({ except = [] }: { except?: string[] }): JSX.Element | null {
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const messages = [...new Set(Object.entries(errors).filter(([key]) => !except.includes(key)).map(([, message]) => message))];
    if (messages.length === 0) return null;

    return (
        <Notice tone="danger" role="alert">
            {messages.length === 1 ? messages[0] : <ul className="list-disc space-y-1 ps-5">{messages.map((message) => <li key={message}>{message}</li>)}</ul>}
        </Notice>
    );
}

export function StatusBadge({ status }: { status: RequestStatus }): JSX.Element {
    const { wt } = useExperience();

    return <span className={badgeClassName(STATUS_TONE[status])}>{wt(`status.${status}`)}</span>;
}

/** The module's one popup: a wide card with a title bar, a close button and a body that scrolls inside the card. */
export function ExperiencePopup({ show, title, subtitle, onClose, children }: { show: boolean; title: string; subtitle?: string | null; onClose: () => void; children: ReactNode }): JSX.Element {
    const { wt } = useExperience();

    return (
        <Modal show={show} onClose={onClose} maxWidth="6xl">
            <div className="flex max-h-[85vh] flex-col">
                <div className="flex items-start justify-between gap-3 border-b border-(--app-border) px-5 py-4">
                    <div className="min-w-0">
                        <h2 className={sectionTitleCls}>{title}</h2>
                        {subtitle && <p className={cx(mutedCls, 'truncate text-xs')}>{subtitle}</p>}
                    </div>
                    <button type="button" onClick={onClose} aria-label={wt('common.close')} className="rounded-control p-1 text-(--app-muted-foreground) hover:bg-primary/10 hover:text-(--app-foreground)">
                        <X className="h-5 w-5" aria-hidden />
                    </button>
                </div>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">{children}</div>
            </div>
        </Modal>
    );
}

/**
 * A popup about one record of the page, named in the query string
 * (`?request=<id>`, `?selected=<id>`): opening asks the server for that
 * record's part of the page only; closing drops it and refreshes the list.
 */
export function usePopupRecord(param: string, prop: string, refresh: string[], initial: string | null = null) {
    const [openId, setOpenId] = useState<string | null>(initial);
    const open = (id: string) => {
        setOpenId(id);
        router.reload({ data: { [param]: id }, only: [prop, 'errors'] });
    };
    const close = () => {
        setOpenId(null);
        router.reload({ data: { [param]: '' }, only: [prop, 'errors', ...refresh] });
    };

    return { openId, open, close };
}

/** The lines of an employee's experience, oldest first, with the total underneath. */
export function LinesTable({ lines, total, toolsId, showSalary = true, actions }: {
    lines: ExperienceLine[];
    total: Duration;
    toolsId: string;
    showSalary?: boolean;
    actions?: (line: ExperienceLine) => ReactNode;
}): JSX.Element {
    const { wt, pick, length, money } = useExperience();
    if (lines.length === 0) return <EmptyState title={wt('lines.emptyTitle')} description={wt('lines.emptyDescription')} />;

    return (
        <>
            <TableTools id={toolsId}><Table>
                <THead>
                    <Tr>
                        <Th>{wt('lines.employer')}</Th><Th>{wt('lines.position')}</Th><Th>{wt('lines.from')}</Th><Th>{wt('lines.until')}</Th><Th>{wt('lines.length')}</Th>
                        {showSalary && <Th className="text-end">{wt('lines.salary')}</Th>}
                        {actions && <Th className="text-end">{wt('common.actions')}</Th>}
                    </Tr>
                </THead>
                <TBody>
                    {lines.map((line, index) => (
                        <Tr key={line.key ?? index}>
                            <Td>
                                <div className="font-medium text-(--app-foreground)">{pick(line, 'employer') || '—'}</div>
                                {line.kind === 'external' && <div className={cx(mutedCls, 'text-xs')}>{wt('lines.external')}</div>}
                            </Td>
                            <Td>
                                <div>{pick(line, 'position') || '—'}</div>
                                {pick(line, 'unit') && <div className={cx(mutedCls, 'text-xs')}>{pick(line, 'unit')}</div>}
                                {line.source === 'assignment' && line.record_id === null && actions && <div className={cx(mutedCls, 'text-xs')}>{wt('lines.fromSystem')}</div>}
                            </Td>
                            <Td className="whitespace-nowrap"><LocalizedDateDisplay value={line.started_on} /></Td>
                            <Td className="whitespace-nowrap">{line.ended_on ? <LocalizedDateDisplay value={line.ended_on} /> : wt('lines.present')}</Td>
                            <Td className="whitespace-nowrap">{length(line.duration)}</Td>
                            {showSalary && <Td className="whitespace-nowrap text-end">{money(line.monthly_salary)}</Td>}
                            {actions && <Td className="whitespace-nowrap text-end"><div className="flex justify-end">{actions(line)}</div></Td>}
                        </Tr>
                    ))}
                </TBody>
            </Table></TableTools>
            <p className="border-t border-(--app-border) px-4 py-3 text-sm font-semibold text-(--app-foreground)">{wt('lines.total', { length: length(total) })}</p>
        </>
    );
}
