import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { PriorityBadge, StatusBadge } from '@/Components/letters/LetterBadges';
import { daysUntil, panelCls, useLetters } from '@/Components/letters/helpers';
import { TBody, Table, Td, Th, THead, Tr, cx } from '@/Components/ui';
import type { LetterRow } from '@/types/letters';
import { Link } from '@inertiajs/react';
import type { JSX } from 'react';

/** The registry rows: number, subject, the other party, dates and status. Used by the registry and the dashboard. */
export default function LetterTable({ rows, showInstitution = true, empty }: { rows: LetterRow[]; showInstitution?: boolean; empty: string }): JSX.Element {
    const { lt, name } = useLetters();
    if (rows.length === 0) {
        return <p className={cx(panelCls, 'p-4 text-sm text-(--app-muted-foreground)')}>{empty}</p>;
    }

    return (
        <div className={cx(panelCls, 'overflow-x-auto')}>
            <Table>
                <THead>
                    <Tr>
                        <Th>{lt('common.number')}</Th>
                        <Th>{lt('common.subject')}</Th>
                        <Th>{lt('common.direction')}</Th>
                        <Th>{lt('common.counterpart')}</Th>
                        {showInstitution && <Th>{lt('common.institution')}</Th>}
                        <Th>{lt('common.date')}</Th>
                        <Th>{lt('common.dueOn')}</Th>
                        <Th>{lt('common.status')}</Th>
                    </Tr>
                </THead>
                <TBody>
                    {rows.map((row) => {
                        const overdue = row.due_on !== null && row.registration_status !== 'closed' && daysUntil(row.due_on) < 0;

                        return (
                            <Tr key={row.registration_id ?? row.id}>
                                <Td className="whitespace-nowrap font-medium">
                                    <Link href={route('letters.show', row.id)} className="underline">{row.number ?? lt('show.numberPending')}</Link>
                                </Td>
                                <Td>
                                    <Link href={route('letters.show', row.id)} className={cx('line-clamp-2 hover:underline', row.subject === null && 'italic text-(--app-muted-foreground)')}>{row.subject ?? lt('show.contentsHiddenShort')}</Link>
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        <PriorityBadge value={row.priority} />
                                        {row.classification !== 'internal' && <StatusBadge kind="classification" value={row.classification} />}
                                        {row.file_code && <span className="text-xs text-(--app-muted-foreground)">{lt('common.fileCode')}: {row.file_code}</span>}
                                    </div>
                                </Td>
                                <Td><StatusBadge kind="direction" value={row.direction} /></Td>
                                <Td>{name(row.counterpart)}</Td>
                                {showInstitution && <Td>{name(row.organization)}</Td>}
                                <Td className="whitespace-nowrap"><LocalizedDateDisplay value={row.received_on ?? row.letter_date} /></Td>
                                <Td className={cx('whitespace-nowrap', overdue && 'font-semibold text-red-700 dark:text-red-400')}><LocalizedDateDisplay value={row.due_on} /></Td>
                                <Td>
                                    <div className="flex flex-wrap gap-1">
                                        {row.registration_status ? <StatusBadge kind="registrationStatus" value={row.registration_status} /> : <StatusBadge kind="status" value={row.status} />}
                                        {row.open_routings > 0 && <span className="text-xs text-(--app-muted-foreground)">{lt('index.openRoutings', { count: row.open_routings })}</span>}
                                    </div>
                                </Td>
                            </Tr>
                        );
                    })}
                </TBody>
            </Table>
        </div>
    );
}
