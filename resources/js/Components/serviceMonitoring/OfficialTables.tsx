import type { JSX } from 'react';
import type { LayoutTable } from '@/types/serviceMonitoring';
import { scrollCls, tableCls, tdCls, thCls } from './helpers';

/**
 * Official form tables exactly as the exports print them (the server's
 * OfficialFormLayout): grouped headings, ተ/ቁ numbering, totals rows and the
 * printed notes. Each table scrolls inside its own container.
 */
export default function OfficialTables({ tables, idPrefix }: { tables: LayoutTable[]; idPrefix: string }): JSX.Element {
    return (
        <div className="space-y-6">
            {tables.map((table, index) => {
                const captionId = `${idPrefix}-${index}`;
                return (
                    <section key={captionId} aria-labelledby={captionId} className="space-y-2">
                        <h3 id={captionId} className="text-sm font-semibold text-(--app-foreground)">
                            {table.title}
                            {table.subtitle && <span className="ms-2 font-normal text-(--app-muted-foreground)">— {table.subtitle}</span>}
                        </h3>
                        <div className={scrollCls}>
                            <table className={tableCls}>
                                {table.header.length > 0 && (
                                    <thead>
                                        {table.header.map((row, r) => (
                                            <tr key={r}>
                                                {row.map((cell, c) => (
                                                    <th key={c} scope="col" colSpan={cell.colspan} rowSpan={cell.rowspan} className={`${thCls} text-center`}>{cell.text}</th>
                                                ))}
                                            </tr>
                                        ))}
                                    </thead>
                                )}
                                <tbody>
                                    {table.body.map((row, r) => {
                                        const total = table.total_rows.includes(r);
                                        return (
                                            <tr key={r} className={total ? 'bg-(--app-surface-muted) font-semibold' : undefined}>
                                                {row.map((value, c) => (
                                                    <td key={c} className={`${tdCls} ${typeof value === 'number' ? 'text-end tabular-nums' : ''}`}>{value === '' ? ' ' : value}</td>
                                                ))}
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        {table.notes.map((note, n) => (
                            <p key={n} className="text-xs text-(--app-muted-foreground)">{note}</p>
                        ))}
                    </section>
                );
            })}
        </div>
    );
}
