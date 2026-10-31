import type { Bilingual, Cell, FormColumn, Row, TableBlock } from './types';

/** The record's name in the page language, falling back to the other one. */
export function nameOf(record: Bilingual | null | undefined, locale: string): string {
    if (!record) return '—';
    return (locale === 'am' ? record.name_am || record.name_en : record.name_en || record.name_am) ?? '—';
}

/** A stable key the server accepts (^[a-z][a-z0-9_]{0,39}$). Administrators never type keys. */
export function newKey(): string {
    return `k${Math.random().toString(36).slice(2, 10)}`;
}

export function toNumber(cell: Cell | undefined): number {
    if (typeof cell === 'number') return cell;
    if (typeof cell === 'string' && cell.trim() !== '' && !Number.isNaN(Number(cell))) return Number(cell);
    return 0;
}

/** Mirrors FormSchema: a row total adds up its number columns. */
export function rowWithSums(block: TableBlock, row: Row): Row {
    const out: Row = { ...row };
    for (const column of block.columns) {
        if (column.type === 'sum') {
            out[column.key] = (column.sum_of ?? []).reduce((sum, source) => sum + toNumber(row[source]), 0);
        }
    }
    return out;
}

export function isTotalled(column: FormColumn): boolean {
    return column.type === 'number' || column.type === 'sum' || column.type === 'check';
}

/** Mirrors FormSchema::totals(): numbers add up, marks are counted. */
export function columnTotals(block: TableBlock, rows: Row[]): Record<string, number> {
    const totals: Record<string, number> = {};
    for (const column of block.columns) {
        if (!isTotalled(column)) continue;
        totals[column.key] = rows.reduce((sum, row) => {
            const cell = rowWithSums(block, row)[column.key];
            return sum + (column.type === 'check' ? (cell === true ? 1 : 0) : toNumber(cell));
        }, 0);
    }
    return totals;
}

export function formatNumber(value: number, locale: string): string {
    return new Intl.NumberFormat(locale === 'am' ? 'am-ET' : 'en-US', { maximumFractionDigits: 2 }).format(value);
}

/**
 * Header layout of a table with column groups: consecutive columns of one
 * group share a heading cell; ungrouped columns span both header rows.
 */
export function headerRuns(block: TableBlock): Array<{ group: string | null; label: string; columns: FormColumn[] }> {
    const groups = new Map((block.column_groups ?? []).map((group) => [group.key, group.label]));
    const runs: Array<{ group: string | null; label: string; columns: FormColumn[] }> = [];
    for (const column of block.columns) {
        const group = column.group && groups.has(column.group) ? column.group : null;
        const last = runs[runs.length - 1];
        if (group && last && last.group === group) {
            last.columns.push(column);
        } else {
            runs.push({ group, label: group ? (groups.get(group) ?? '') : column.label, columns: [column] });
        }
    }
    return runs;
}
