import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Alert, AppFilterBar, AppMetricCard, Card, CardDescription, CardHeader, CardTitle, FilterField, Select, Table, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import { formatNumber, headerRuns, isTotalled, nameOf, toNumber } from '@/Components/serviceMonitoring/helpers';
import type { Cell, FormColumn, FormSchema, Named, TableBlock } from '@/Components/serviceMonitoring/types';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

type Institution = Named & { type_id: string };
type Sums = Record<string, number>;

type SummaryTable = {
    key: string;
    /** fixed tables: row key → type id (or "all") → column → sum */
    fixed: Record<string, Record<string, Sums>>;
    listed: Array<{ institution: Institution; cells: Record<string, Cell> }>;
    listed_count: number;
    totals: Sums;
};

type Summary = {
    types: Named[];
    coverage: { expected: number; reported: number; by_status: Record<string, number>; missing: Institution[] };
    tables: SummaryTable[];
    texts: Array<{ key: string; label: string; answers: Array<{ institution: Institution; text: string }> }>;
};

type Props = {
    filters: { form_code: string | null; period_id: string | null; organization_type_id?: string; accepted_only?: string | boolean };
    forms: Array<Named & { code: string }>;
    periods: Named[];
    organizationTypes: Named[];
    form: (Named & { code: string; version: number; schema: FormSchema }) | null;
    period: Named | null;
    summary: Summary | null;
};

/**
 * The consolidated report of one form for one period. Fixed-row tables are
 * broken down by the organization types the form is assigned to, whatever
 * they are; there is no built-in "sub-city / city" split.
 */
export default function ServiceMonitoringSummary({ filters, forms, periods, organizationTypes, form, period, summary }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const typeName = (id: string) => nameOf(summary?.types.find((type) => type.id === id) ?? null, locale);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('serviceMonitoring.summary.title')} description={t('serviceMonitoring.summary.description')} />}>
            <Head title={t('serviceMonitoring.summary.title')} />
            <div className="space-y-6">
                <AppFilterBar routeName="service-monitoring.summary" filters={{ form_code: filters.form_code, period_id: filters.period_id, organization_type_id: filters.organization_type_id, accepted_only: filters.accepted_only ? '1' : '' }}>
                    <FilterField label={t('serviceMonitoring.summary.form')} wide>
                        <Select name="form_code" defaultValue={filters.form_code ?? ''}>
                            {forms.map((option) => <option key={option.code} value={option.code}>{nameOf(option, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceMonitoring.summary.period')}>
                        <Select name="period_id" defaultValue={filters.period_id ?? ''}>
                            {periods.map((option) => <option key={option.id} value={option.id}>{nameOf(option, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceMonitoring.summary.type')}>
                        <Select name="organization_type_id" defaultValue={filters.organization_type_id ?? ''}>
                            <option value="">{t('serviceMonitoring.summary.allTypes')}</option>
                            {organizationTypes.map((type) => <option key={type.id} value={type.id}>{nameOf(type, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceMonitoring.summary.acceptedOnly')}>
                        <Select name="accepted_only" defaultValue={filters.accepted_only ? '1' : ''}>
                            <option value="">{t('serviceMonitoring.reports.allStatuses')}</option>
                            <option value="1">{t('serviceMonitoring.summary.acceptedOnly')}</option>
                        </Select>
                    </FilterField>
                </AppFilterBar>

                {(!form || !period || !summary) ? (
                    <Alert tone="info">{t('serviceMonitoring.summary.noForm')}</Alert>
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <AppMetricCard label={t('serviceMonitoring.summary.expected')} value={summary.coverage.expected} />
                            <AppMetricCard label={t('serviceMonitoring.summary.reported')} value={summary.coverage.reported} variant="success" />
                            <AppMetricCard label={t('serviceMonitoring.summary.drafts')} value={summary.coverage.by_status.draft ?? 0} variant="warning" />
                            <AppMetricCard label={t('serviceMonitoring.summary.returned')} value={summary.coverage.by_status.returned ?? 0} variant="danger" />
                        </div>

                        {summary.coverage.missing.length > 0 && (
                            <Card className="space-y-2 p-4">
                                <CardHeader className="p-0">
                                    <CardTitle>{t('serviceMonitoring.summary.missing')}</CardTitle>
                                    {summary.coverage.missing.length >= 100 && <CardDescription>{t('serviceMonitoring.summary.missingMore')}</CardDescription>}
                                </CardHeader>
                                <p className="text-sm text-(--app-foreground)">
                                    {summary.coverage.missing.map((institution) => `${nameOf(institution, locale)} (${typeName(institution.type_id)})`).join('، ')}
                                </p>
                            </Card>
                        )}

                        {form.schema.sections.map((section) => (
                            <section key={section.key} className="space-y-4">
                                <h2 className="border-b border-(--app-border) pb-2 text-base font-semibold text-(--app-foreground)">{section.title}</h2>
                                {section.blocks.map((block) => {
                                    if (block.type === 'note') return null;
                                    if (block.type === 'text') {
                                        const text = summary.texts.find((entry) => entry.key === block.key);
                                        return (
                                            <Card key={block.key} className="space-y-2 p-4">
                                                <CardTitle>{block.label}</CardTitle>
                                                {(text?.answers ?? []).length === 0
                                                    ? <p className="text-sm text-(--app-muted-foreground)">{t('serviceMonitoring.summary.noAnswers')}</p>
                                                    : <ul className="space-y-2 text-sm">{text?.answers.map((answer) => (
                                                        <li key={answer.institution.id}><span className="font-medium">{nameOf(answer.institution, locale)}:</span> <span className="whitespace-pre-wrap">{answer.text}</span></li>
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

/** Fixed rows × (each organization type, then all) × the table's totalled columns. */
function FixedSummary({ block, table, types }: { block: TableBlock; table: SummaryTable; types: Named[] }): JSX.Element {
    const { t, locale } = useLocale();
    const columns = block.columns.filter(isTotalled);
    const scopes: Array<{ key: string; label: string }> = [
        ...(types.length > 1 ? types.map((type) => ({ key: type.id, label: nameOf(type, locale) })) : []),
        { key: 'all', label: t('serviceMonitoring.summary.overall') },
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
                        <Tr>
                            {scopes.flatMap((scope) => columns.map((column) => <Th key={`${scope.key}-${column.key}`}>{column.label}</Th>))}
                        </Tr>
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
                                <Td>{t('serviceMonitoring.reports.total')}</Td>
                                {scopes.flatMap((scope) => columns.map((column) => <Td key={`${scope.key}-${column.key}`} numeric>{formatNumber(sum(scope.key, column.key), locale)}</Td>))}
                            </Tr>
                        )}
                    </TBody>
                </Table>
            </div>
        </div>
    );
}

/** Every institution's rows, named, with the totals of all rows. */
function ListedSummary({ block, table, typeName }: { block: TableBlock; table: SummaryTable; typeName: (id: string) => string }): JSX.Element {
    const { t, locale } = useLocale();
    const runs = headerRuns(block);
    const grouped = runs.some((run) => run.group !== null);
    const hasTotals = block.columns.some(isTotalled);

    return (
        <div className="space-y-2">
            <h3 className="text-sm font-semibold text-(--app-foreground)">{block.title} <span className="text-xs font-normal text-(--app-muted-foreground)">({table.listed_count} {t('serviceMonitoring.summary.rowsCount')})</span></h3>
            {table.listed.length < table.listed_count && <p className="text-xs text-(--app-muted-foreground)">{t('serviceMonitoring.summary.listedLimit')}</p>}
            <div className="overflow-x-auto rounded-card border border-(--app-border)">
                <Table>
                    <THead>
                        <Tr>
                            <Th rowSpan={grouped ? 2 : 1}>{t('serviceMonitoring.summary.institution')}</Th>
                            {runs.map((run) => run.group
                                ? <Th key={`g-${run.group}-${run.columns[0].key}`} colSpan={run.columns.length} align="center">{run.label}</Th>
                                : <Th key={run.columns[0].key} rowSpan={grouped ? 2 : 1}>{run.columns[0].label}</Th>)}
                        </Tr>
                        {grouped && <Tr>{runs.filter((run) => run.group).flatMap((run) => run.columns).map((column) => <Th key={column.key}>{column.label}</Th>)}</Tr>}
                    </THead>
                    <TBody>
                        {table.listed.length === 0 && <Tr><Td colSpan={block.columns.length + 1} muted>{t('serviceMonitoring.reports.noRows')}</Td></Tr>}
                        {table.listed.map((entry, index) => (
                            <Tr key={`${entry.institution.id}-${index}`}>
                                <Td className="min-w-48">
                                    <span className="font-medium">{nameOf(entry.institution, locale)}</span>
                                    <span className="block text-xs text-(--app-muted-foreground)">{typeName(entry.institution.type_id)}</span>
                                </Td>
                                {block.columns.map((column) => <Td key={column.key} numeric={isTotalled(column)} className="align-top">{display(column, entry.cells[column.key] ?? null, locale)}</Td>)}
                            </Tr>
                        ))}
                        {hasTotals && table.listed_count > 0 && (
                            <Tr className="bg-(--app-surface-muted) font-semibold">
                                <Td>{t('serviceMonitoring.reports.total')}</Td>
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
