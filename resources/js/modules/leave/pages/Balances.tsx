import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import EmptyState from '@/Components/EmptyState';
import { CalendarIcon, ClipboardListIcon, EyeIcon, HistoryIcon, PencilIcon, Upload } from '@/Components/Icons';
import PageHeader from '@/Components/PageHeader';
import { AppActionMenu, FilterField, FilterPanel, ListPagination, Table, TableCard, TableTools, TBody, Td, Th, THead, Tr, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BalanceCards } from '@/modules/leave/components/BalanceCards';
import { AdjustForm, LedgerTable, PostponeForm } from '@/modules/leave/components/BalanceForms';
import { ActionErrors, Notice } from '@/modules/leave/components/Common';
import { LeavePopup, PopupLoading } from '@/modules/leave/components/Popup';
import { formatDays, inputCls, labelCls, mutedCls, panelCls, secondaryBtn, sectionTitleCls, useLeave } from '@/modules/leave/lib/helpers';
import type { Bilingual, EmployeeRef, LeaveSummary, LedgerLine, Paginated } from '@/modules/leave/types';
import { Head, router } from '@inertiajs/react';
import { useState, type JSX } from 'react';

type Panel = 'adjust' | 'ledger' | 'postpone';

interface Selected {
    employee: EmployeeRef;
    summary: LeaveSummary;
    ledger: LedgerLine[];
    can: { adjust: boolean; postpone: boolean };
}

interface Props {
    leaveEmployees: Paginated<EmployeeRef & { totals: Record<string, number> }>;
    leaveTypes: (Bilingual & { id: string; years: number[] })[];
    budgetYears: { previous: number; current: number; starts_on: string; ends_on: string };
    filters: { search: string };
    can: { import: boolean; adjust: boolean; postpone: boolean };
    leaveSelected: Selected | null;
}

const PANEL_TITLE: Record<Panel, string> = { adjust: 'employee.adjustTitle', ledger: 'ledger.title', postpone: 'employee.postponeTitle' };

/**
 * Leave balances of the employees in scope, one row each, with what is left
 * of the last two budget years. Each row's actions open in a popup: opening
 * balance or correction, the ledger, postponement.
 */
export default function Balances({ leaveEmployees, leaveTypes, budgetYears, filters, can, leaveSelected }: Props): JSX.Element {
    const { lt, name } = useLeave();
    const [search, setSearch] = useState(filters.search);
    const [file, setFile] = useState<File | null>(null);
    const [open, setOpen] = useState<{ panel: Panel; employee: EmployeeRef } | null>(null);
    // One column per leave type and budget year: last year's remaining days beside this year's.
    const columns = leaveTypes.flatMap((type) => type.years.map((year) => ({ type, year })));
    const loaded = open !== null && leaveSelected?.employee.id === open.employee.id ? leaveSelected : null;

    // The popup's data is this employee's part of the page: asked for when the popup opens, refreshed by every save.
    const show = (panel: Panel, employee: EmployeeRef) => {
        setOpen({ panel, employee });
        router.reload({ data: { selected: employee.id }, only: ['leaveSelected', 'errors'] });
    };
    const close = () => {
        setOpen(null);
        router.reload({ data: { selected: '' }, only: ['leaveSelected', 'leaveEmployees', 'errors'] });
    };
    const closeButton = <button type="button" className={secondaryBtn} onClick={close}>{lt('common.close')}</button>;

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('balances.title')} description={lt('balances.description')} />}>
            <Head title={lt('balances.title')} />
            {open === null && <ActionErrors />}
            <div className="space-y-4 pb-4">
                <FilterPanel onSubmit={() => router.get(route('leave.balances.index'), search.trim() === '' ? {} : { search: search.trim() }, { preserveState: true })}
                    onReset={() => router.get(route('leave.balances.index'))} hasActiveFilters={filters.search !== ''}>
                    <FilterField label={lt('balances.search')} wide><input type="search" className={inputCls} value={search} onChange={(e) => setSearch(e.target.value)} /></FilterField>
                </FilterPanel>

                <TableCard>
                    <div className="border-b border-(--app-border) px-4 py-3">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{lt('balances.tableTitle')}</h2>
                        <p className={cx(mutedCls, 'mt-0.5 text-xs')}>
                            {lt('balances.yearNote', { year: budgetYears.current })} <LocalizedDateDisplay value={budgetYears.starts_on} /> – <LocalizedDateDisplay value={budgetYears.ends_on} />
                        </p>
                    </div>
                    {leaveEmployees.data.length === 0 ? <EmptyState title={lt('balances.empty')} /> : (
                        <TableTools id="leave-balances"><Table>
                            <THead>
                                <Tr>
                                    <Th>{lt('table.employee')}</Th>
                                    <Th>{lt('balances.serviceStart')}</Th>
                                    {columns.map(({ type, year }) => (
                                        <Th key={`${type.id}:${year}`}>
                                            {name(type)}
                                            <span className={cx('block text-xs font-normal', year === budgetYears.current ? 'text-primary' : 'text-(--app-muted-foreground)')}>{lt('balance.budgetYear', { year })}</span>
                                        </Th>
                                    ))}
                                    <Th className="text-end">{lt('table.actions')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {leaveEmployees.data.map((employee) => (
                                    <Tr key={employee.id}>
                                        <Td>
                                            <button type="button" onClick={() => show('ledger', employee)} className="text-start font-medium text-primary hover:underline">{name(employee)}</button>
                                            <div className={cx(mutedCls, 'text-xs')}>{employee.employee_number}</div>
                                        </Td>
                                        <Td>{employee.service_start_date ? <LocalizedDateDisplay value={employee.service_start_date} /> : <span className="text-amber-700 dark:text-amber-400">{lt('balances.noServiceStart')}</span>}</Td>
                                        {columns.map(({ type, year }) => <Td key={`${type.id}:${year}`}>{formatDays(employee.totals[`${type.id}:${year}`] ?? 0)}</Td>)}
                                        <Td className="whitespace-nowrap text-end">
                                            <div className="flex justify-end">
                                                <AppActionMenu items={[
                                                    { label: lt('balances.actions.opening'), onClick: () => show('adjust', employee), show: can.adjust, icon: <PencilIcon className="h-4 w-4" /> },
                                                    { label: lt('balances.actions.ledger'), onClick: () => show('ledger', employee), icon: <HistoryIcon className="h-4 w-4" /> },
                                                    { label: lt('balances.actions.postpone'), onClick: () => show('postpone', employee), show: can.postpone, icon: <CalendarIcon className="h-4 w-4" /> },
                                                    { label: lt('balances.actions.requests'), href: route('leave.requests.index', { search: employee.employee_number ?? '' }), separatorBefore: true, icon: <ClipboardListIcon className="h-4 w-4" /> },
                                                    { label: lt('balances.actions.open'), href: route('leave.balances.show', employee.id), icon: <EyeIcon className="h-4 w-4" /> },
                                                ]} />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table></TableTools>
                    )}
                </TableCard>
                <ListPagination meta={leaveEmployees} />

                {can.import && (
                    <section className={cx(panelCls, 'space-y-3 p-5')}>
                        <h2 className={sectionTitleCls}>{lt('balances.importTitle')}</h2>
                        <p className={mutedCls}>{lt('balances.importHelp')}</p>
                        <pre className="overflow-x-auto rounded-control border border-(--app-border) px-3 py-2 text-xs">employee_number,leave_type_code,entitlement_year,days{'\n'}EMP-0001,annual,2019,12.5</pre>
                        <div className="flex flex-wrap items-end gap-3">
                            <div>
                                <label htmlFor="lv-import" className={labelCls}>{lt('balances.importFile')}</label>
                                <input id="lv-import" type="file" accept=".csv,text/csv" className="block text-sm" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                            </div>
                            <button type="button" className={cx(secondaryBtn, 'gap-2')} disabled={file === null}
                                onClick={() => file && router.post(route('leave.balances.import'), { file }, { forceFormData: true, preserveScroll: true, onSuccess: () => setFile(null) })}>
                                <Upload className="h-4 w-4" aria-hidden />{lt('balances.importSubmit')}
                            </button>
                        </div>
                    </section>
                )}
            </div>

            <LeavePopup show={open !== null} onClose={close} title={open ? lt(PANEL_TITLE[open.panel]) : ''} subtitle={open ? `${name(open.employee)} · ${open.employee.employee_number ?? ''}` : null}>
                {open && (
                    <>
                        <ActionErrors except={['leave_type_id', 'starts_on', 'ends_on', 'is_half_day', 'expected_delivery_on', 'reason', 'contact_during_leave', 'days', 'until_year', 'entitlement_year']} />
                        {!loaded ? <PopupLoading /> : (
                            <>
                                {!loaded.employee.service_start_date && <Notice tone="warning">{lt('employee.noServiceStart')}</Notice>}
                                {open.panel === 'adjust' && (loaded.can.adjust
                                    ? <AdjustForm key={loaded.employee.id} employeeId={loaded.employee.id} summary={loaded.summary} onCancel={close} />
                                    : <Notice tone="warning">{lt('balances.notAllowed')}</Notice>)}
                                {open.panel === 'postpone' && (loaded.can.postpone
                                    ? <PostponeForm key={loaded.employee.id} employeeId={loaded.employee.id} summary={loaded.summary} onCancel={close} />
                                    : <Notice tone="warning">{lt('balances.notAllowed')}</Notice>)}
                                {open.panel === 'ledger' && <BalanceCards summary={loaded.summary} />}
                                {/* The ledger sits under every popup: a save shows up as its newest line at once. */}
                                {(
                                    <div className="space-y-2">
                                        {open.panel !== 'ledger' && <h3 className="text-sm font-semibold text-(--app-foreground)">{lt('ledger.title')}</h3>}
                                        <LedgerTable ledger={loaded.ledger} />
                                    </div>
                                )}
                                {open.panel === 'ledger' && <div className="flex justify-end">{closeButton}</div>}
                            </>
                        )}
                    </>
                )}
            </LeavePopup>
        </AuthenticatedLayout>
    );
}
