/**
 * The additional-sections contract shared with Modules\ServiceMonitoring\Domain\Forms\FormSchema.
 * Keep the two in step: the server re-validates everything and recomputes every total.
 */
export type ColumnType = 'text' | 'textarea' | 'number' | 'check' | 'select' | 'sum';
export type RowMode = 'dynamic' | 'fixed' | 'single';

export interface FormColumn {
    key: string;
    label: string;
    type: ColumnType;
    group?: string | null;
    required?: boolean;
    options?: string[];
    sum_of?: string[];
    /** Number and total columns: summed over a report range (flow) or the latest quarter (stock). */
    aggregation?: 'flow' | 'stock';
}

export interface ColumnGroup { key: string; label: string }
export interface FixedRow { key: string; label: string }

export interface TableBlock {
    type: 'table';
    key: string;
    title: string;
    description?: string | null;
    row_mode: RowMode;
    rows?: FixedRow[];
    max_rows?: number;
    show_totals: boolean;
    column_groups?: ColumnGroup[];
    columns: FormColumn[];
}

export interface NoteBlock { type: 'note'; key: string; text: string }
export interface TextBlock { type: 'text'; key: string; label: string; required?: boolean }

export type FormBlock = TableBlock | NoteBlock | TextBlock;

export interface FormSection { key: string; title: string; blocks: FormBlock[] }
export interface FormSchema { sections: FormSection[] }

export type Cell = string | number | boolean | null;
export type Row = Record<string, Cell>;
/** fixed tables are keyed by row key; dynamic and single tables are lists. */
export type TableAnswer = Row[] | Record<string, Row>;
export type ReportData = Record<string, TableAnswer | string>;

export interface Bilingual { name_am: string | null; name_en: string | null }
export type Named = Bilingual & { id: string };

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}
