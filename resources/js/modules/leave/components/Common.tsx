import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import EmptyState from '@/Components/EmptyState';
import { ClipboardListIcon, EyeIcon } from '@/Components/Icons';
import { AppActionMenu, Table, TableCard, TableTools, TBody, Td, Th, THead, Tr, cx } from '@/Components/ui';
import { STATUS_TONE, badge, formatDays, mutedCls, useLeave } from '@/modules/leave/lib/helpers';
import type { LeaveRow, LeaveStatus } from '@/modules/leave/types';
import { Link, usePage } from '@inertiajs/react';
import type { JSX, ReactNode } from 'react';

const tones = {
    danger: 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100',
    warning: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100',
    info: 'border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100',
};

export function Notice({ tone = 'info', children, role = 'status' }: { tone?: keyof typeof tones; children: ReactNode; role?: 'status' | 'alert' }): JSX.Element {
    return <div role={role} className={cx('rounded-control border px-3 py-2 text-sm', tones[tone])}>{children}</div>;
}

/**
 * What the server refused: a rule of the leave law, a file, or a field. Shown
 * at the top of the page so a refusal is never silent. Field errors named in
 * `except` are shown beside their fields instead.
 */
export function ActionErrors({ except = [] }: { except?: string[] }): JSX.Element | null {
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const messages = [...new Set(Object.entries(errors).filter(([key]) => !except.includes(key)).map(([, message]) => message))];
    if (messages.length === 0) return null;

    return (
        <div className="mb-4">
            <Notice tone="danger" role="alert">
                {messages.length === 1 ? messages[0] : <ul className="list-disc space-y-1 ps-5">{messages.map((message) => <li key={message}>{message}</li>)}</ul>}
            </Notice>
        </div>
    );
}

export function StatusBadge({ status }: { status: LeaveStatus }): JSX.Element {
    const { lt } = useLeave();

    return <span className={badge(STATUS_TONE[status])}>{lt(`status.${status}`)}</span>;
}

/** "9 Nov – 13 Nov", or one date with the half of the day. */
export function Period({ row }: { row: Pick<LeaveRow, 'starts_on' | 'ends_on' | 'is_half_day' | 'half_day_part'> }): JSX.Element {
    const { lt } = useLeave();
    const single = row.starts_on === row.ends_on;
    const dates = single ? <LocalizedDateDisplay value={row.starts_on} /> : <><LocalizedDateDisplay value={row.starts_on} /> – <LocalizedDateDisplay value={row.ends_on} /></>;
    if (!row.is_half_day) return <span>{dates}</span>;
    // A half-day leave says which half, on its one day or on each day of its period.
    const half = lt(`common.half.${row.half_day_part ?? 'morning'}`);

    return <span>{dates} · {single ? half : lt('common.halfEachDay', { half })}</span>;
}

/**
 * The one list of leave requests, used by the employee, the supervisor, HR
 * and the reports: a titled card, the shared table tools, and a row menu.
 * With `onOpen` a request opens in the page's popup; "open the full page"
 * stays in the menu for a link that can be shared.
 */
export function RequestTable({ rows, title, description, toolsId, hrefFor, onOpen, showEmployee = true, emptyTitle, emptyDescription }: {
    rows: LeaveRow[];
    title: string;
    description?: ReactNode;
    /** Names the table for the saved column and density choices. */
    toolsId: string;
    hrefFor: (row: LeaveRow) => string;
    onOpen?: (row: LeaveRow) => void;
    showEmployee?: boolean;
    emptyTitle: string;
    emptyDescription?: string;
}): JSX.Element {
    const { lt, name, dayCount } = useLeave();

    return (
        <TableCard>
            <div className="border-b border-(--app-border) px-4 py-3">
                <h2 className="text-sm font-semibold text-(--app-foreground)">{title}</h2>
                {description && <p className={cx(mutedCls, 'mt-0.5 text-xs')}>{description}</p>}
            </div>
            {rows.length === 0 ? <EmptyState title={emptyTitle} description={emptyDescription} /> : (
                <TableTools id={toolsId}><Table>
                    <THead>
                        <Tr>
                            <Th>{lt('table.reference')}</Th>
                            {showEmployee && <Th>{lt('table.employee')}</Th>}
                            <Th>{lt('table.type')}</Th>
                            <Th>{lt('table.period')}</Th>
                            <Th>{lt('table.days')}</Th>
                            <Th>{lt('table.status')}</Th>
                            <Th className="text-end">{lt('table.actions')}</Th>
                        </Tr>
                    </THead>
                    <TBody>
                        {rows.map((row) => (
                            <Tr key={row.id}>
                                <Td>
                                    {onOpen
                                        ? <button type="button" onClick={() => onOpen(row)} className="font-medium text-primary hover:underline">{row.reference_number}</button>
                                        : <Link href={hrefFor(row)} className="font-medium text-primary hover:underline">{row.reference_number}</Link>}
                                </Td>
                                {showEmployee && (
                                    <Td>
                                        <div className="font-medium text-(--app-foreground)">{name(row.employee)}</div>
                                        <div className={cx(mutedCls, 'text-xs')}>{row.employee?.employee_number}{row.unit ? ` · ${name(row.unit)}` : ''}</div>
                                    </Td>
                                )}
                                <Td>{name(row.leave_type)}</Td>
                                <Td><Period row={row} /></Td>
                                <Td title={formatDays(row.days_counted)}>{dayCount(row.days_counted, row.leave_type?.day_unit)}</Td>
                                <Td><StatusBadge status={row.status} /></Td>
                                <Td className="whitespace-nowrap text-end">
                                    <div className="flex justify-end">
                                        <AppActionMenu items={[
                                            { label: lt('table.view'), onClick: () => onOpen?.(row), show: onOpen !== undefined, icon: <EyeIcon className="h-4 w-4" /> },
                                            { label: lt('table.openPage'), href: hrefFor(row), icon: <ClipboardListIcon className="h-4 w-4" /> },
                                        ]} />
                                    </div>
                                </Td>
                            </Tr>
                        ))}
                    </TBody>
                </Table></TableTools>
            )}
        </TableCard>
    );
}
