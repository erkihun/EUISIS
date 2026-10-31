import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RangePicker from '@/Components/serviceMonitoring/RangePicker';
import { Alert, AppMetricCard, Card, CardTitle, Table, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import type { TemplateContentData } from '@/Components/serviceMonitoring/builder/TemplateEditor';
import { formatNumber, headerRuns, isTotalled, nameOf, toNumber } from '@/Components/serviceMonitoring/builder/helpers';
import type { Cell, FormColumn, Named, TableBlock } from '@/Components/serviceMonitoring/builder/types';
import { useLocale } from '@/hooks/useLocale';
import type { FiscalYearRow, Selection } from '@/types/serviceMonitoring';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

type Institution = Named & { type_id: string };
type Sums = Record<string, number>;

type SummaryTable = {
    key: string;
    /** fixed tables: row key → organization type id (or "all") → column → figure */
    fixed: Record<string, Record<string, Sums>>;
    listed: Array<{ institution: Institution; quarter: number; cells: Record<string, Cell> }>;
    listed_count: number;
    totals: Sums;
};

type Summary = {
    types: Named[];
    quarters: number[];
    reports: number;
    institutions: number;
    tables: SummaryTable[];
    texts: Array<{ key: string; answers: Array<{ institution: Institution; quarter: number; text: string }> }>;
};

type Props = {
    template: Named & { code: string; version: number; content: TemplateContentData };
    years: FiscalYearRow[];
    selection: Selection | null;
    summary: Summary | null;
};

/**
 * The additional sections of a form template, consolidated over a report
 * range from accepted reports in scope. Fixed-row tables are broken down by
 * the organization types that reported, not by a built-in administrative level.
 */
export default function FormTemplateSummary({ template, years, selection, summary }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const tt = (key: string) => t(`serviceMonitoring.templates.${key}`);
    const typeName = (id: string) => nameOf(summary?.types.find((type) => type.id === id) ?? null, locale);
    const schema = template.content.custom;
    const title = `${tt('summaryTitle')} — ${nameOf(template, locale)}`;

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={tt('summaryDescription')} backHref={route('service-monitoring.forms.show', template.id)} />}>
            <Head title={title} />
            <div className="space-y-6">
                {selection && <RangePicker years={years} selection={selection} routeName="service-monitoring.forms.summary" routeParams={{ template: template.id }} />}

                {!summary || schema.sections.length === 0 ? (
                    <Alert tone="info">{schema.sections.length === 0 ? tt('noCustomSections') : tt('noData')}</Alert>
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <AppMetricCard label={tt('institutionsReported')} value={summary.institutions} />
                            <AppMetricCard label={tt('reportsCounted')} value={summary.reports} variant="success" />
                        </div>

                        {schema.sections.map((section) => (
                            <section key={section.key} className="space-y-4">
                                <h2 className="border-b border-(--app-border) pb-2 text-base font-semibold text-(--app-foreground)">{section.title}</h2>
                                {section.blocks.map((block) => {
                                    if (block.type === 'note') return null;
                                    if (block.type === 'text') {
                                        const answers = summary.texts.find((entry) => entry.key === block.key)?.answers ?? [];
                                        return (
                                            <Card key={block.key} className="space-y-2 p-4">
                                                <CardTitle>{block.label}</CardTitle>
                                                {answers.length === 0
                                                    ? <p className="text-sm text-(--app-muted-foreground)">{tt('noAnswers')}</p>
                                                    : <ul className="space-y-2 text-sm">{answers.map((answer, i) => (
                                                        <li key={i}><span className="font-medium">{nameOf(answer.institution, locale)} (Q{answer.quarter}):</span> <span className="whitespace-pre-wrap">{answer.text}</span></li>
                                                    ))}</ul>}
                                            </Card>
                                        );
                                    }
                                    const table = summary.tables.find((entry) => entry.key === block.key);
                                    if (!table) return null;
                                    return block.row_mode === 'fixed'
                                        ? <FixedSummary key={block.key} block={block} table={table} types={summary.types} />
                                        : <ListedSummary key={block.key} block={block} table={table} typeName={typeName} />;
                                })}
                            </section>
                        ))}
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

/** Fixed rows × (each organization type that reported, then all) × the table's totalled columns. */
function FixedSummary({ block, table, types }: { block: TableBlock; table: SummaryTable; types: Named[] }): JSX.Element {
    const { t, locale } = useLocale();
    const columns = block.columns.filter(isTotalled);
    const scopes = [
        ...(types.length > 1 ? types.map((type) => ({ key: type.id, label: nameOf(type, locale) })) : []),
        { key: 'all', label: t('serviceMonitoring.templates.overall') },
    ];
    const sum = (scope: string, column: string) => (block.rows ?? []).reduce((total, row) => total + (table.fixed[row.key]?.[scope]?.[column] ?? 0), 0);

    return (
        <div className="space-y-2">
            <h3 className="text-sm font-semibold text-(--app-foreground)">{block.title}</h3>
            <div className="overflow-x-auto rounded-card border border-(--app-border)">
                <Table>
                    <THead>
                        <Tr>
                            <Th rowSpan={2} />
                            {scopes.map((scope) => <Th key={scope.key} colSpan={columns.length} align="center">{scope.label}</Th>)}
                        </Tr>
                        <Tr>{scopes.flatMap((scope) => columns.map((column) => <Th key={`${scope.key}-${column.key}`}>{column.label}</Th>))}</Tr>
                    </THead>
                    <TBody>
                        {(block.rows ?? []).map((row) => (
                            <Tr key={row.key}>
                                <Td className="min-w-48 font-medium">{row.label}</Td>
                                {scopes.flatMap((scope) => columns.map((column) => (
                                    <Td key={`${scope.key}-${column.key}`} numeric>{formatNumber(table.fixed[row.key]?.[scope.key]?.[column.key] ?? 0, locale)}</Td>
                                )))}
                            </Tr>
                        ))}
                        {block.show_totals && (
                            <Tr className="bg-(--app-surface-muted) font-semibold">
                                <Td>{t('serviceMonitoring.templates.cells.total')}</Td>
                                {scopes.flatMap((scope) => columns.map((column) => <Td key={`${scope.key}-${column.key}`} numeric>{formatNumber(sum(scope.key, column.key), locale)}</Td>))}
                            </Tr>
                        )}
                    </TBody>
                </Table>
            </div>
        </div>
    );
}

/** Every institution's rows in the range, named, with the totals of all rows. */
function ListedSummary({ block, table, typeName }: { block: TableBlock; table: SummaryTable; typeName: (id: string) => string }): JSX.Element {
    const { t, locale } = useLocale();
    const runs = headerRuns(block);
    const grouped = runs.some((run) => run.group !== null);
    const hasTotals = block.columns.some(isTotalled);

    return (
        <div className="space-y-2">
            <h3 className="text-sm font-semibold text-(--app-foreground)">{block.title} <span className="text-xs font-normal text-(--app-muted-foreground)">({table.listed_count})</span></h3>
            {table.listed.length < table.listed_count && <p className="text-xs text-(--app-muted-foreground)">{t('serviceMonitoring.templates.listedLimit')}</p>}
            <div className="overflow-x-auto rounded-card border border-(--app-border)">
                <Table>
                    <THead>
                        <Tr>
                            <Th rowSpan={grouped ? 2 : 1}>{t('serviceMonitoring.templates.institution')}</Th>
                            {runs.map((run) => run.group
                                ? <Th key={`g-${run.group}-${run.columns[0].key}`} colSpan={run.columns.length} align="center">{run.label}</Th>
                                : <Th key={run.columns[0].key} rowSpan={grouped ? 2 : 1}>{run.columns[0].label}</Th>)}
                        </Tr>
                        {grouped && <Tr>{runs.filter((run) => run.group).flatMap((run) => run.columns).map((column) => <Th key={column.key}>{column.label}</Th>)}</Tr>}
                    </THead>
                    <TBody>
                        {table.listed.length === 0 && <Tr><Td colSpan={block.columns.length + 1} muted>{t('serviceMonitoring.templates.cells.noRows')}</Td></Tr>}
                        {table.listed.map((entry, index) => (
                            <Tr key={`${entry.institution.id}-${entry.quarter}-${index}`}>
                                <Td className="min-w-48">
                                    <span className="font-medium">{nameOf(entry.institution, locale)}</span>
                                    <span className="block text-xs text-(--app-muted-foreground)">{typeName(entry.institution.type_id)} · Q{entry.quarter}</span>
                                </Td>
                                {block.columns.map((column) => <Td key={column.key} numeric={isTotalled(column)} className="align-top">{display(column, entry.cells[column.key] ?? null, locale)}</Td>)}
                            </Tr>
                        ))}
                        {hasTotals && table.listed_count > 0 && (
                            <Tr className="bg-(--app-surface-muted) font-semibold">
                                <Td>{t('serviceMonitoring.templates.cells.total')}</Td>
                                {block.columns.map((column) => <Td key={column.key} numeric>{isTotalled(column) ? formatNumber(table.totals[column.key] ?? 0, locale) : ''}</Td>)}
                            </Tr>
                        )}
                    </TBody>
                </Table>
            </div>
        </div>
    );
}

function display(column: FormColumn, value: Cell, locale: string): string {
    if (column.type === 'check') return value === true ? 'X' : '';
    if (column.type === 'number' || column.type === 'sum') return value === null || value === '' ? '' : formatNumber(toNumber(value), locale);
    return value === null ? '' : String(value);
}
