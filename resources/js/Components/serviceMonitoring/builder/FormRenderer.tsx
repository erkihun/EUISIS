import { Button, FieldError, Input, Select, Table, TBody, Td, Th, THead, Textarea, Tr, cx } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';
import { columnTotals, formatNumber, headerRuns, isTotalled, rowWithSums, toNumber } from './helpers';
import type { Cell, FormColumn, FormSchema, ReportData, Row, TableBlock } from './types';

type Errors = Record<string, string | undefined>;

interface Props {
    schema: FormSchema;
    data: ReportData;
    /** Without onChange the form is read-only (preview, submitted report). */
    onChange?: (data: ReportData) => void;
    errors?: Errors;
    /** The first segment of the server's error keys for the answers (`custom` for a report's additional sections). */
    errorPrefix?: string;
}

/**
 * Renders a monitoring form the way the paper form looks: numbered sections,
 * tables with grouped headings, notes and text answers. Used by the builder's
 * preview, the institution's fill page and the read-only report.
 */
export default function FormRenderer({ schema, data, onChange, errors = {}, errorPrefix = 'data' }: Props): JSX.Element {
    const editable = onChange !== undefined;

    return (
        <div className="space-y-6">
            {schema.sections.map((section) => (
                <section key={section.key} className="space-y-4">
                    <h2 className="border-b border-(--app-border) pb-2 text-base font-semibold text-(--app-foreground)">{section.title}</h2>
                    {section.blocks.map((block) => {
                        if (block.type === 'note') {
                            return <p key={block.key} className="whitespace-pre-line rounded-card border border-(--app-border) bg-(--app-surface-muted) p-3 text-sm text-(--app-muted-foreground)">{block.text}</p>;
                        }
                        if (block.type === 'text') {
                            const value = typeof data[block.key] === 'string' ? (data[block.key] as string) : '';
                            const error = errors[`${errorPrefix}.${block.key}`];
                            return (
                                <div key={block.key} className="space-y-1.5">
                                    <p className="text-sm font-medium text-(--app-foreground)">
                                        {block.label}{block.required && <span className="ms-0.5 text-red-600" aria-hidden="true">*</span>}
                                    </p>
                                    {editable
                                        ? <Textarea aria-label={block.label} aria-invalid={Boolean(error)} value={value} maxLength={10000} onChange={(e) => onChange({ ...data, [block.key]: e.target.value })} />
                                        : <p className="whitespace-pre-wrap text-sm text-(--app-foreground)">{value || '—'}</p>}
                                    {error && <FieldError>{error}</FieldError>}
                                </div>
                            );
                        }
                        return (
                            <TableView
                                key={block.key}
                                block={block}
                                answer={data[block.key]}
                                errors={errors}
                                errorPrefix={errorPrefix}
                                onChange={editable ? (answer) => onChange({ ...data, [block.key]: answer }) : undefined}
                            />
                        );
                    })}
                </section>
            ))}
        </div>
    );
}

function TableView({ block, answer, errors, errorPrefix, onChange }: {
    block: TableBlock;
    answer: ReportData[string] | undefined;
    errors: Errors;
    errorPrefix: string;
    onChange?: (answer: Row[] | Record<string, Row>) => void;
}): JSX.Element {
    const { t, locale } = useLocale();
    const editable = onChange !== undefined;
    const runs = headerRuns(block);
    const grouped = runs.some((run) => run.group !== null);
    const fixed = block.row_mode === 'fixed';

    const fixedAnswer: Record<string, Row> = fixed && answer && typeof answer === 'object' && !Array.isArray(answer) ? answer : {};
    const listAnswer: Row[] = !fixed && Array.isArray(answer) ? answer : [];
    const listRows = block.row_mode === 'single' && listAnswer.length === 0 ? [{}] : listAnswer;

    const rows: Array<{ id: string; label: string; row: Row; path: string }> = fixed
        ? (block.rows ?? []).map((r) => ({ id: r.key, label: r.label, row: fixedAnswer[r.key] ?? {}, path: `${errorPrefix}.${block.key}.${r.key}` }))
        : listRows.map((row, index) => ({ id: String(index), label: String(index + 1), row, path: `${errorPrefix}.${block.key}.${index}` }));

    const totals = columnTotals(block, rows.map((r) => r.row));
    const hasTotals = block.show_totals && block.columns.some(isTotalled) && rows.length > 0;
    const leading = block.row_mode !== 'single';
    const canRemove = editable && block.row_mode === 'dynamic';

    function setCell(id: string, column: FormColumn, value: Cell) {
        if (!onChange) return;
        if (fixed) {
            onChange({ ...fixedAnswer, [id]: { ...(fixedAnswer[id] ?? {}), [column.key]: value } });
        } else {
            const next = [...listRows];
            next[Number(id)] = { ...(next[Number(id)] ?? {}), [column.key]: value };
            onChange(next);
        }
    }

    const tableError = errors[`${errorPrefix}.${block.key}`];

    return (
        <div className="space-y-2">
            <div>
                <h3 className="text-sm font-semibold text-(--app-foreground)">{block.title}</h3>
                {block.description && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{block.description}</p>}
            </div>
            <div className="overflow-x-auto rounded-card border border-(--app-border)">
                <Table>
                    <THead>
                        <Tr>
                            {leading && <Th rowSpan={grouped ? 2 : 1}>{fixed ? '' : t('serviceMonitoring.templates.cells.rowNumber')}</Th>}
                            {runs.map((run) => run.group
                                ? <Th key={`g-${run.group}-${run.columns[0].key}`} colSpan={run.columns.length} align="center">{run.label}</Th>
                                : <Th key={run.columns[0].key} rowSpan={grouped ? 2 : 1}><ColumnHeading column={run.columns[0]} /></Th>)}
                            {canRemove && <Th rowSpan={grouped ? 2 : 1}><span className="sr-only">{t('serviceMonitoring.templates.actions.remove')}</span></Th>}
                        </Tr>
                        {grouped && (
                            <Tr>
                                {runs.filter((run) => run.group).flatMap((run) => run.columns).map((column) => <Th key={column.key}><ColumnHeading column={column} /></Th>)}
                            </Tr>
                        )}
                    </THead>
                    <TBody>
                        {rows.length === 0 && (
                            <Tr><Td colSpan={block.columns.length + 2} muted>{t('serviceMonitoring.templates.cells.noRows')}</Td></Tr>
                        )}
                        {rows.map(({ id, label, row, path }) => {
                            const computed = rowWithSums(block, row);
                            return (
                                <Tr key={id}>
                                    {leading && <Td className={cx(fixed ? 'min-w-48 font-medium' : 'w-12')} muted={!fixed}>{label}</Td>}
                                    {block.columns.map((column) => (
                                        <Td key={column.key} numeric={isTotalled(column) && !editable} className="align-top">
                                            {editable && column.type !== 'sum'
                                                ? <CellInput column={column} value={row[column.key] ?? null} label={`${label} ${column.label}`} error={errors[`${path}.${column.key}`]} onChange={(value) => setCell(id, column, value)} />
                                                : <CellValue column={column} value={computed[column.key] ?? null} />}
                                        </Td>
                                    ))}
                                    {canRemove && (
                                        <Td className="w-10">
                                            <Button type="button" variant="ghost" size="xs" aria-label={t('serviceMonitoring.templates.actions.remove')} title={t('serviceMonitoring.templates.actions.remove')}
                                                onClick={() => onChange?.(listRows.filter((_, index) => String(index) !== id))}>×</Button>
                                        </Td>
                                    )}
                                </Tr>
                            );
                        })}
                        {hasTotals && (
                            <Tr className="bg-(--app-surface-muted) font-semibold">
                                {leading && <Td>{t('serviceMonitoring.templates.cells.total')}</Td>}
                                {block.columns.map((column) => (
                                    <Td key={column.key} numeric>{column.key in totals ? formatNumber(totals[column.key], locale) : ''}</Td>
                                ))}
                                {canRemove && <Td />}
                            </Tr>
                        )}
                    </TBody>
                </Table>
            </div>
            {tableError && <FieldError>{tableError}</FieldError>}
            {canRemove && rows.length < (block.max_rows ?? 500) && (
                <Button type="button" variant="outline" size="sm" onClick={() => onChange?.([...listRows, {}])}>{t('serviceMonitoring.templates.actions.addRow')}</Button>
            )}
        </div>
    );
}

function ColumnHeading({ column }: { column: FormColumn }): JSX.Element {
    return <>{column.label}{column.required && <span className="ms-0.5 text-red-600" aria-hidden="true">*</span>}</>;
}

function CellValue({ column, value }: { column: FormColumn; value: Cell }): JSX.Element {
    const { t, locale } = useLocale();
    if (column.type === 'check') return <span aria-label={value === true ? t('serviceMonitoring.templates.cells.marked') : undefined}>{value === true ? 'X' : ''}</span>;
    if (column.type === 'number' || column.type === 'sum') {
        return <>{value === null || value === '' ? '' : formatNumber(toNumber(value), locale)}</>;
    }
    return <span className="whitespace-pre-wrap">{value === null || value === '' ? '' : String(value)}</span>;
}

function CellInput({ column, value, label, error, onChange }: {
    column: FormColumn;
    value: Cell;
    label: string;
    error?: string;
    onChange: (value: Cell) => void;
}): JSX.Element {
    const text = value === null || value === undefined || typeof value === 'boolean' ? '' : String(value);
    const common = { 'aria-label': label, 'aria-invalid': Boolean(error) };

    const control = (() => {
        switch (column.type) {
            case 'check':
                return <input type="checkbox" {...common} className="h-4 w-4 accent-(--color-primary)" checked={value === true} onChange={(e) => onChange(e.target.checked)} />;
            case 'number':
                return <Input type="number" inputMode="decimal" min={0} step="any" {...common} className="min-w-20" value={text} onChange={(e) => onChange(e.target.value)} />;
            case 'select':
                return (
                    <Select {...common} className="min-w-32" value={text} onChange={(e) => onChange(e.target.value)}>
                        <option value="" />
                        {(column.options ?? []).map((option) => <option key={option} value={option}>{option}</option>)}
                    </Select>
                );
            case 'textarea':
                return <Textarea {...common} className="min-h-16 min-w-56" maxLength={5000} value={text} onChange={(e) => onChange(e.target.value)} />;
            default:
                return <Input {...common} className="min-w-40" maxLength={500} value={text} onChange={(e) => onChange(e.target.value)} />;
        }
    })();

    return (
        <div className="space-y-1">
            {control}
            {error && <FieldError>{error}</FieldError>}
        </div>
    );
}
