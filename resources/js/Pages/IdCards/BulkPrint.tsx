import { useState, type FormEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import CardStatusBadge from '@/Components/IdCards/CardStatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import {
    FilterField, FilterPanel, Input, ListPagination, Select, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName,
    tableHeadClassName, tableHeaderCellClassName, tableRowClassName, type LaravelPageMeta,
} from '@/Components/ui';

type Option = { id: string; name_en: string; name_am?: string | null };
type Unit = Option & { parent_unit_id: string | null };
type Card = {
    id: string;
    card_number: string;
    status: string;
    reprint_required: boolean;
    employee_number: string | null;
    employee_name: string | null;
    organization: string | null;
    unit: string | null;
};
type Filters = {
    organization_id: string | null;
    organization_unit_id: string | null;
    include_sub_units: boolean;
    need: 'pending_print' | 'reprint_required' | 'all';
    search: string;
};
type Props = {
    filters: Filters;
    organizations: Option[];
    units: Unit[];
    total: number;
    cards: LaravelPageMeta & { data: Card[] };
    maxCards: number;
};

const NEEDS = ['pending_print', 'reprint_required', 'all'] as const;

/** Fill `:name` placeholders in a translated string. */
const fill = (text: string, params: Record<string, string | number>) =>
    Object.entries(params).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value)), text);

/** Units in tree order with their depth, so sub-units read as nested in the picker. */
function unitTree(units: Unit[]): Array<Unit & { depth: number }> {
    const ids = new Set(units.map((unit) => unit.id));
    const children = (parent: string | null) =>
        units.filter((unit) => (parent === null ? !unit.parent_unit_id || !ids.has(unit.parent_unit_id) : unit.parent_unit_id === parent));
    const walk = (parent: string | null, depth: number, seen: Set<string>): Array<Unit & { depth: number }> =>
        children(parent).flatMap((unit) => (seen.has(unit.id) ? [] : [
            { ...unit, depth },
            ...walk(unit.id, depth + 1, new Set([...seen, unit.id])),
        ]));

    return walk(null, 0, new Set());
}

export default function BulkPrint({ filters, organizations, units, total, cards, maxCards }: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const label = (key: string) => t(`cardPrinting.bulk.${key}`);
    const name = (option: Option) => (locale === 'am' && option.name_am) || option.name_en;
    const [values, setValues] = useState<Filters>(filters);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const batch = useForm<{ mode: 'selected' | 'matching'; card_ids: string[] } & Filters>({ mode: 'selected', card_ids: [], ...filters });

    const query = (next: Filters) => Object.fromEntries(
        Object.entries({ ...next, include_sub_units: next.include_sub_units ? 1 : 0 }).filter(([, value]) => value !== null && value !== ''),
    );
    function apply(event?: FormEvent) {
        event?.preventDefault();
        setSelected(new Set());
        router.get(route('print-batches.bulk'), query(values), { preserveState: true, preserveScroll: true, replace: true });
    }
    function reset() {
        const empty: Filters = { organization_id: null, organization_unit_id: null, include_sub_units: true, need: 'pending_print', search: '' };
        setValues(empty);
        setSelected(new Set());
        router.get(route('print-batches.bulk'), query(empty), { preserveScroll: true, replace: true });
    }

    const pageIds = cards.data.map((card) => card.id);
    const pageAllSelected = pageIds.length > 0 && pageIds.every((id) => selected.has(id));
    const toggle = (id: string, on: boolean) => setSelected((current) => {
        const next = new Set(current);
        if (on) next.add(id); else next.delete(id);
        return next;
    });

    // The server re-checks every id against the same filters and scope.
    function submit(mode: 'selected' | 'matching') {
        batch.transform(() => ({ ...filters, include_sub_units: filters.include_sub_units, mode, card_ids: mode === 'selected' ? [...selected] : [] }));
        batch.post(route('print-batches.bulk.store'), { preserveScroll: true });
    }
    async function submitMatching() {
        const count = Math.min(total, maxCards);
        if ((await confirm({ title: fill(label('confirmMatching'), { count }), variant: 'warning' })).confirmed) submit('matching');
    }

    const filtersActive = Boolean(filters.organization_id || filters.search || filters.need !== 'pending_print');

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={label('title')}
                    description={label('description')}
                    actions={<Link href={route('print-batches.index')} className={buttonClassName({ variant: 'outline' })}>{label('openBatches')}</Link>}
                />
            }
        >
            <Head title={label('title')} />

            <FilterPanel onSubmit={apply} onReset={reset} hasActiveFilters={filtersActive} className="mb-4">
                <FilterField label={label('organization')}>
                    <Select
                        value={values.organization_id ?? ''}
                        onChange={(event) => {
                            // Applied at once, so that organization's units load into the next picker.
                            const next = { ...values, organization_id: event.target.value || null, organization_unit_id: null };
                            setValues(next);
                            setSelected(new Set());
                            router.get(route('print-batches.bulk'), query(next), { preserveState: true, preserveScroll: true, replace: true });
                        }}
                    >
                        <option value="">{label('allOrganizations')}</option>
                        {organizations.map((organization) => <option key={organization.id} value={organization.id}>{name(organization)}</option>)}
                    </Select>
                </FilterField>
                <FilterField label={label('unit')}>
                    <Select
                        value={values.organization_unit_id ?? ''}
                        disabled={!filters.organization_id}
                        onChange={(event) => setValues({ ...values, organization_unit_id: event.target.value || null })}
                    >
                        <option value="">{label('allUnits')}</option>
                        {unitTree(units).map((unit) => (
                            <option key={unit.id} value={unit.id}>{`${'— '.repeat(unit.depth)}${name(unit)}`}</option>
                        ))}
                    </Select>
                    <label className="mt-1 flex items-center gap-2 text-xs text-(--app-muted-foreground)">
                        <input
                            type="checkbox"
                            checked={values.include_sub_units}
                            onChange={(event) => setValues({ ...values, include_sub_units: event.target.checked })}
                        />
                        {label('includeSubUnits')}
                    </label>
                </FilterField>
                <FilterField label={label('need')}>
                    <Select value={values.need} onChange={(event) => setValues({ ...values, need: event.target.value as Filters['need'] })}>
                        {NEEDS.map((need) => <option key={need} value={need}>{label(`need_${need}`)}</option>)}
                    </Select>
                </FilterField>
                <FilterField label={t('common.search')} wide>
                    <Input
                        type="search"
                        value={values.search}
                        placeholder={label('search')}
                        onChange={(event) => setValues({ ...values, search: event.target.value })}
                    />
                </FilterField>
            </FilterPanel>

            {/* Selection bar: what will be batched, and the two ways to batch it. */}
            <div className="mb-3 flex flex-wrap items-center gap-3 rounded-panel border border-(--app-border) bg-(--app-surface) px-4 py-3">
                <div className="text-sm">
                    <p className="font-semibold text-(--app-foreground)">{fill(label('matching'), { count: total })}</p>
                    <p className="text-xs text-(--app-muted-foreground)">
                        {fill(label('selected'), { count: selected.size })} · {label('alreadyQueuedNote')}
                    </p>
                </div>
                <div className="ms-auto flex flex-wrap items-center gap-2">
                    {selected.size > 0 && (
                        <button type="button" className={buttonClassName({ variant: 'ghost', size: 'sm' })} onClick={() => setSelected(new Set())}>
                            {label('clearSelection')}
                        </button>
                    )}
                    <button
                        type="button"
                        className={buttonClassName({ variant: 'outline' })}
                        disabled={selected.size === 0 || selected.size > maxCards || batch.processing}
                        onClick={() => submit('selected')}
                    >
                        {fill(label('batchSelected'), { count: selected.size })}
                    </button>
                    <button
                        type="button"
                        className={buttonClassName({ variant: 'primary' })}
                        disabled={total === 0 || batch.processing}
                        onClick={submitMatching}
                    >
                        {total > maxCards
                            ? fill(label('batchMatchingCapped'), { count: maxCards })
                            : fill(label('batchMatching'), { count: total })}
                    </button>
                </div>
                {total > maxCards && (
                    <p className="w-full text-xs text-amber-700 dark:text-amber-300">{fill(label('capNote'), { max: maxCards })}</p>
                )}
                {Object.values(batch.errors).length > 0 && (
                    <p role="alert" className="w-full text-sm text-red-600">{Object.values(batch.errors).join(' ')}</p>
                )}
            </div>

            <div className={tableContainerClassName}>
                {cards.data.length === 0 ? (
                    <EmptyState title={label('empty')} />
                ) : (
                    <table className={tableClassName}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                <th className={cx(tableHeaderCellClassName, 'w-10')}>
                                    <input
                                        type="checkbox"
                                        aria-label={label('selectPage')}
                                        checked={pageAllSelected}
                                        onChange={(event) => setSelected((current) => {
                                            const next = new Set(current);
                                            pageIds.forEach((id) => (event.target.checked ? next.add(id) : next.delete(id)));
                                            return next;
                                        })}
                                    />
                                </th>
                                <th className={tableHeaderCellClassName}>{label('card')}</th>
                                <th className={tableHeaderCellClassName}>{label('employee')}</th>
                                <th className={tableHeaderCellClassName}>{label('placement')}</th>
                                <th className={tableHeaderCellClassName}>{label('status')}</th>
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {cards.data.map((card) => (
                                <tr key={card.id} className={cx(tableRowClassName, selected.has(card.id) && 'bg-blue-500/5')}>
                                    <td className={tableCellClassName}>
                                        <input
                                            type="checkbox"
                                            aria-label={card.card_number}
                                            checked={selected.has(card.id)}
                                            onChange={(event) => toggle(card.id, event.target.checked)}
                                        />
                                    </td>
                                    <td className={cx(tableCellClassName, 'font-mono text-xs')}>{card.card_number}</td>
                                    <td className={tableCellClassName}>
                                        <p className="font-medium">{card.employee_name}</p>
                                        <p className="text-xs text-(--app-muted-foreground)">{card.employee_number}</p>
                                    </td>
                                    <td className={tableCellClassName}>
                                        <p>{card.organization}</p>
                                        {card.unit && <p className="text-xs text-(--app-muted-foreground)">{card.unit}</p>}
                                    </td>
                                    <td className={tableCellClassName}>
                                        <div className="flex flex-wrap items-center gap-1">
                                            <CardStatusBadge status={card.status} />
                                            {card.reprint_required && (
                                                <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">{label('reprint')}</span>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
                <ListPagination meta={cards} />
            </div>
        </AuthenticatedLayout>
    );
}
