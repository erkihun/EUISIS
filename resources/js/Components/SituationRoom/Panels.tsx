import { useId, useState, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { Button, Card, StatusBadge, TBody, THead, Table, Td, Th, Tr, cx, type Tone } from '@euisis/ui';
import ListPagination from '@/Components/ui/ListPagination';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import SituationChart from './charts/SituationChart';
import type { SrChartRow } from './charts/types';
import { useSituationLabels } from './useSituationLabels';
import type { SrColumn, SrKpiItem, SrLabel, SrPanel, SrReason } from '@/types/situationRoom';

/** What a panel may offer beyond its figures. */
export interface PanelOptions {
    /** Small-group threshold, for "fewer than k". */
    smallCell: number;
    /** Link that records an executive action prompted by this panel; null hides it. */
    actionHref?: (panelKey: string) => string | null;
    wall?: boolean;
    /** The area's colour, drawn as the card's top edge so a category's panels read as one family. */
    accent?: string;
}

/** A horizontal chart longer than this opens on its largest rows; a city has dozens of institutions. */
const LONG_CHART_ROWS = 10;

const SPAN: Record<1 | 2 | 3, string> = { 1: 'lg:col-span-1', 2: 'lg:col-span-2', 3: 'lg:col-span-3' };

/**
 * The panels of one category, in a grid that is one column on a phone, two on
 * a tablet and three on a desktop. Each panel is rendered on its own: a failed
 * or empty one is a card saying so, never a hole in the page.
 */
export function PanelGrid({ panels, options }: { panels: SrPanel[]; options: PanelOptions }) {
    return (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            {panels.map((panel) => (
                <div key={panel.key} className={cx(panel.span >= 2 ? 'md:col-span-2' : '', SPAN[panel.span] ?? '')}>
                    <PanelCard panel={panel} options={options} />
                </div>
            ))}
        </div>
    );
}

/** The charts of one wall screen, filling the height left to them with no hole beside them. */
export function WallPanels({ panels, options }: { panels: SrPanel[]; options: PanelOptions }) {
    const uneven = panels.length === 2 && panels[0].span !== panels[1].span;

    return (
        <div className={cx('grid grid-cols-1 gap-4 lg:h-full', uneven ? 'lg:grid-cols-3' : panels.length > 1 && 'lg:grid-cols-2')}>
            {panels.map((panel) => (
                <div key={panel.key} className={cx('min-h-80 lg:min-h-0', uneven && panel.span >= 2 && 'lg:col-span-2')}>
                    <PanelCard panel={panel} options={options} />
                </div>
            ))}
        </div>
    );
}

export function PanelCard({ panel, options }: { panel: SrPanel; options: PanelOptions }) {
    const { sr } = useSituationLabels();
    const titleId = useId();
    const actionHref = options.actionHref?.(panel.key) ?? null;

    if (panel.type === 'kpis') {
        return (
            <section aria-labelledby={titleId}>
                <h2 id={titleId} className={cx('mb-2 font-semibold text-(--app-foreground)', options.wall ? 'text-xl' : 'text-sm')}>{sr(panel.title)}</h2>
                <KpiTiles items={panel.data.items} />
            </section>
        );
    }

    return (
        <Card
            className={cx('flex h-full flex-col p-4', options.wall && 'min-h-0 p-5', options.accent && 'border-t-[3px]')}
            style={options.accent ? { borderTopColor: options.accent } : undefined}
            aria-labelledby={titleId}
            role="region"
        >
            <header className="mb-3 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id={titleId} className={cx('font-semibold text-(--app-foreground)', options.wall ? 'text-lg' : 'text-sm')}>{sr(panel.title)}</h2>
                    {panel.description && !options.wall && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{sr(panel.description)}</p>}
                </div>
                {panel.href && !options.wall && (
                    <Link href={panel.href} className="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-primary-light px-2.5 py-1 text-xs font-semibold text-primary transition-colors hover:bg-primary-200 focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary dark:hover:bg-primary-600">
                        {sr('ui.open')}<span aria-hidden="true">›</span>
                    </Link>
                )}
            </header>

            <div className="min-h-0 flex-1">
                {panel.type === 'notice' && <PanelNotice reason={panel.reason} />}
                {panel.type === 'table' && <PanelTable columns={panel.data.columns} rows={panel.data.rows} pagination={panel.data.pagination} />}
                {panel.type !== 'notice' && panel.type !== 'table' && <ChartBody panel={panel} options={options} />}
            </div>

            {!options.wall && (
                <footer className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-(--app-border) pt-2 text-xs text-(--app-muted-foreground)">
                    <span>{sr('ui.source', { source: sr(panel.source) })}</span>
                    {actionHref && <Link href={actionHref} className="font-medium text-primary hover:underline">{sr('ui.record_action')}</Link>}
                </footer>
            )}
        </Card>
    );
}

function useCategoryText() {
    const { label, locale } = useSituationLabels();
    const system = useCalendarSystem();

    return (value: SrLabel): string => {
        if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDateDisplay(value, system, locale);
        if (typeof value === 'string' && /^\d{4}-\d{2}$/.test(value)) return formatDateDisplay(`${value}-01`, system, locale);
        return label(value);
    };
}

function ChartBody({ panel, options }: { panel: Extract<SrPanel, { data: { series: unknown } }>; options: PanelOptions }) {
    const { sr, label } = useSituationLabels();
    const text = useCategoryText();
    const [showData, setShowData] = useState(false);
    const [showAll, setShowAll] = useState(false);
    const tableId = useId();
    const series = panel.data.series.map((s) => ({ key: s.key, label: label(s.label) }));
    const rows: SrChartRow[] = panel.data.categories.map((category, i) => {
        const row: SrChartRow = { label: text(category) };
        panel.data.series.forEach((s) => { row[s.key] = s.values[i] ?? null; });
        return row;
    });
    const fewer = sr('ui.fewer_than', { k: options.smallCell });
    const isLong = (panel.type === 'hbar' || panel.type === 'stacked') && rows.length > LONG_CHART_ROWS;
    const rowTotal = (row: SrChartRow) => series.reduce((sum, s) => sum + Number(row[s.key] ?? 0), 0);
    const ranked = isLong ? [...rows].sort((a, b) => rowTotal(b) - rowTotal(a)) : rows;
    // A withheld (null) value is not a zero: its row stays.
    const withFigures = ranked.filter((row) => series.some((s) => row[s.key] === null || Number(row[s.key]) > 0));
    if (isLong && withFigures.length === 0) return <PanelNotice reason="NO_DATA" />;
    const top = withFigures.slice(0, LONG_CHART_ROWS);
    const chartRows = isLong && !showAll ? top : ranked;
    const summary = `${sr(panel.title)}: ${chartRows.slice(0, 6).map((r) => `${r.label} ${series.map((s) => r[s.key] ?? fewer).join('/')}`).join(', ')}${chartRows.length > 6 ? '…' : ''}`;

    return (
        <div className={options.wall ? 'flex h-full min-h-0 flex-col' : undefined}>
            <div className={cx(isLong && showAll && 'max-h-[28rem] overflow-y-auto', options.wall && 'min-h-56 flex-1')} tabIndex={isLong && showAll ? 0 : undefined}>
                <div role="img" aria-label={summary} className={options.wall ? 'h-full' : undefined}>
                    <SituationChart type={panel.type} rows={chartRows} series={series} unit={panel.data.unit} suppressedLabel={fewer} wall={options.wall} palette={panel.data.palette} />
                </div>
            </div>
            {isLong && !showAll && <p className="mt-1 text-xs text-(--app-muted-foreground)">{sr('ui.top_rows', { shown: top.length, total: rows.length })}</p>}
            {!options.wall && (
                <>
                    {isLong && (
                        <Button variant="ghost" size="xs" className="mt-1" aria-expanded={showAll} onClick={() => setShowAll((v) => !v)}>
                            {showAll ? sr('ui.show_top', { shown: top.length }) : sr('ui.show_all', { total: rows.length })}
                        </Button>
                    )}
                    <Button variant="ghost" size="xs" className="mt-1" aria-expanded={showData} aria-controls={tableId} onClick={() => setShowData((v) => !v)}>
                        {sr(showData ? 'ui.hide_data' : 'ui.show_data')}
                    </Button>
                    <div id={tableId} hidden={!showData} className="mt-2 max-h-72 overflow-auto">
                        <Table>
                            <THead><Tr><Th>{sr('export.item')}</Th>{series.map((s) => <Th key={s.key} align="right">{s.label}</Th>)}</Tr></THead>
                            <TBody>
                                {rows.map((row) => (
                                    <Tr key={row.label}><Td>{row.label}</Td>{series.map((s) => <Td key={s.key} numeric>{row[s.key] === null ? fewer : Number(row[s.key]).toLocaleString()}</Td>)}</Tr>
                                ))}
                            </TBody>
                        </Table>
                    </div>
                </>
            )}
        </div>
    );
}

const STATE_TONE: Record<NonNullable<SrKpiItem['state']>, Tone> = { not_configured: 'neutral', attention: 'warning', clear: 'success' };

export function formatKpi(item: Pick<SrKpiItem, 'value' | 'unit'>, sr: (key: string) => string): string {
    if (item.value === null) return '—';
    const value = Number(item.value).toLocaleString(undefined, { maximumFractionDigits: 2 });
    switch (item.unit) {
        case '%': return `${value}%`;
        case '/5': return `${value} / 5`;
        case 'days': return `${value} ${sr('ui.unit_days')}`;
        case 'h': return `${value} ${sr('ui.unit_hours')}`;
        case 'ETB': return `${value} ${sr('ui.unit_birr')}`;
        default: return value;
    }
}

export function KpiTiles({ items }: { items: SrKpiItem[] }) {
    const { sr } = useSituationLabels();

    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
            {items.map((item) => {
                const body: ReactNode = (
                    <>
                        <p className="text-xs font-medium text-(--app-muted-foreground)">{sr(item.label)}</p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums text-(--app-foreground)">
                            {item.state === 'not_configured' ? <span className="text-base font-medium text-(--app-muted-foreground)">{sr('ui.not_configured')}</span> : formatKpi(item, sr)}
                        </p>
                        {item.previous !== undefined && <p className="mt-1 text-xs text-(--app-muted-foreground)">{sr('ui.previous', { value: Number(item.previous).toLocaleString() })}</p>}
                        {item.state && item.state !== 'not_configured' && (
                            <StatusBadge tone={STATE_TONE[item.state]} className="mt-2">{sr(item.state === 'attention' ? 'ui.attention' : 'ui.clear')}</StatusBadge>
                        )}
                        {item.definition && <p className="mt-1 text-[11px] leading-snug text-(--app-muted-foreground)">{sr(item.definition)}</p>}
                    </>
                );
                const tile = 'block h-full rounded-card border border-(--app-border) bg-(--app-surface) p-3';

                return item.href
                    ? <Link key={item.key} href={item.href} className={cx(tile, 'transition-colors hover:border-primary focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary')}>{body}</Link>
                    : <div key={item.key} className={tile}>{body}</div>;
            })}
        </div>
    );
}

const NOTICE_TONE: Partial<Record<SrReason, Tone>> = {
    ERROR: 'danger', CONFIGURATION_REQUIRED: 'warning', NEEDS_POLICY_DECISION_REPORTING_HIERARCHY: 'warning',
    ALL_CLEAR: 'success', HIERARCHY_PUBLISHED: 'info', SEPARATE_DOMAINS: 'info',
};

/** One distinct message per reason: empty, not configured, not recorded, not permitted, failed. */
export function PanelNotice({ reason }: { reason: SrReason }) {
    const { sr } = useSituationLabels();

    return (
        <div className="flex h-full min-h-24 flex-col justify-center gap-1 rounded-card bg-(--app-surface-muted) p-3" role={reason === 'ERROR' ? 'alert' : undefined}>
            <StatusBadge tone={NOTICE_TONE[reason] ?? 'neutral'} className="self-start">{sr(`reasons.${reason}.title`)}</StatusBadge>
            <p className="text-xs text-(--app-muted-foreground)">{sr(`reasons.${reason}.description`)}</p>
        </div>
    );
}

function PanelTable({ columns, rows, pagination }: { columns: SrColumn[]; rows: Array<Record<string, unknown>>; pagination: { page: number; per_page: number; total: number } | null }) {
    const { label, locale } = useSituationLabels();
    const system = useCalendarSystem();
    const cell = (column: SrColumn, value: unknown): string => {
        if (value === null || value === undefined) return '—';
        switch (column.type) {
            case 'label': return label(value as SrLabel);
            case 'percent': return `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
            case 'number': return Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });
            case 'date': return formatDateDisplay(String(value), system, locale);
            default: return String(value);
        }
    };

    return (
        <div>
            <div className={cx('max-w-full overflow-x-auto', rows.length > LONG_CHART_ROWS && 'max-h-[32rem] overflow-y-auto [&_thead_th]:sticky [&_thead_th]:top-0 [&_thead_th]:z-10 [&_thead_th]:bg-(--app-surface)')}>
                <Table>
                    <THead><Tr>{columns.map((c) => <Th key={c.key} align={c.type === 'number' || c.type === 'percent' ? 'right' : 'left'}>{label(c.label)}</Th>)}</Tr></THead>
                    <TBody>
                        {rows.map((row, i) => (
                            <Tr key={i}>{columns.map((c) => <Td key={c.key} numeric={c.type === 'number' || c.type === 'percent'}>{cell(c, row[c.key])}</Td>)}</Tr>
                        ))}
                    </TBody>
                </Table>
            </div>
            {pagination && pagination.total > pagination.per_page && (
                <ListPagination meta={{ current_page: pagination.page, last_page: Math.ceil(pagination.total / pagination.per_page), per_page: pagination.per_page, total: pagination.total }} />
            )}
        </div>
    );
}
