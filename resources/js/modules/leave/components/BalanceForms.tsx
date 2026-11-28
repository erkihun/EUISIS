import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import EmptyState from '@/Components/EmptyState';
import { Table, TableCard, TBody, Td, Th, THead, Tr, cx } from '@/Components/ui';
import { errorCls, formatDays, inputCls, labelCls, mutedCls, primaryBtn, secondaryBtn, useLeave } from '@/modules/leave/lib/helpers';
import type { LeaveSummary, LedgerLine } from '@/modules/leave/types';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';

const fieldError = (errors: Record<string, string>, field: string) => (errors[field] ? <p className={errorCls}>{errors[field]}</p> : null);

/**
 * An opening balance or a correction for one employee: one ledger line with
 * its reason. Used on the employee's balance page and in the balances popup.
 */
export function AdjustForm({ employeeId, summary, onCancel }: { employeeId: string; summary: LeaveSummary; onCancel?: () => void }): JSX.Element | null {
    const { lt, name } = useLeave();
    const balanceTypes = summary.types.filter((type) => type.uses_balance);
    const form = useForm({ leave_type_id: balanceTypes[0]?.id ?? '', entitlement_year: String(summary.budget_year), days: '', kind: 'opening', reason: '' });
    if (balanceTypes.length === 0) return null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('leave.balances.adjust', employeeId), { preserveScroll: true, onSuccess: () => form.reset('days', 'reason') });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <p className={mutedCls}>{lt('employee.adjustHelp')}</p>
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label htmlFor="lv-adj-kind" className={labelCls}>{lt('employee.kind')}</label>
                    <select id="lv-adj-kind" className={inputCls} value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                        <option value="opening">{lt('ledger.opening')}</option>
                        <option value="adjustment">{lt('ledger.adjustment')}</option>
                    </select>
                </div>
                <div>
                    <label htmlFor="lv-adj-type" className={labelCls}>{lt('table.type')}</label>
                    <select id="lv-adj-type" className={inputCls} value={form.data.leave_type_id} onChange={(e) => form.setData('leave_type_id', e.target.value)}>
                        {balanceTypes.map((type) => <option key={type.id} value={type.id}>{name(type)}</option>)}
                    </select>
                    {fieldError(form.errors, 'leave_type_id')}
                </div>
                <div>
                    <label htmlFor="lv-adj-year" className={labelCls}>{lt('show.budgetYear')}</label>
                    <select id="lv-adj-year" className={inputCls} value={form.data.entitlement_year} onChange={(e) => form.setData('entitlement_year', e.target.value)}>
                        {[summary.budget_year, summary.budget_year - 1, summary.budget_year - 2].map((year) => <option key={year} value={year}>{lt('balance.budgetYear', { year })}</option>)}
                    </select>
                    {fieldError(form.errors, 'entitlement_year')}
                </div>
                <div>
                    <label htmlFor="lv-adj-days" className={labelCls}>{lt('employee.days')}</label>
                    <input id="lv-adj-days" type="number" step="0.5" className={inputCls} value={form.data.days} onChange={(e) => form.setData('days', e.target.value)} required />
                    <p className={cx(mutedCls, 'mt-1 text-xs')}>{lt('employee.daysHelp')}</p>
                    {fieldError(form.errors, 'days')}
                </div>
            </div>
            <div>
                <label htmlFor="lv-adj-reason" className={labelCls}>{lt('form.reason')}</label>
                <input id="lv-adj-reason" type="text" maxLength={1000} className={inputCls} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                {fieldError(form.errors, 'reason')}
            </div>
            <div className="flex flex-wrap justify-end gap-3">
                {onCancel && <button type="button" className={secondaryBtn} onClick={onCancel}>{lt('common.close')}</button>}
                <button type="submit" className={primaryBtn} disabled={form.processing}>{lt('employee.adjust')}</button>
            </div>
        </form>
    );
}

/** The head of the institution lets unused annual leave of a budget year be used in a later one. */
export function PostponeForm({ employeeId, summary, onCancel }: { employeeId: string; summary: LeaveSummary; onCancel?: () => void }): JSX.Element {
    const { lt, name } = useLeave();
    const waiting = summary.types.filter((type) => type.uses_balance).flatMap((type) => (type.waiting ?? []).map((bucket) => ({ type, bucket })));
    const form = useForm({ leave_type_id: waiting[0]?.type.id ?? '', entitlement_year: String(waiting[0]?.bucket.year ?? ''), until_year: String(summary.budget_year), reason: '' });

    if (waiting.length === 0) {
        return (
            <div className="space-y-3">
                <p className={mutedCls}>{lt('employee.nothingWaiting')}</p>
                {onCancel && <div className="flex justify-end"><button type="button" className={secondaryBtn} onClick={onCancel}>{lt('common.close')}</button></div>}
            </div>
        );
    }
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('leave.balances.postpone', employeeId), { preserveScroll: true, onSuccess: () => form.reset('reason') });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <p className={mutedCls}>{lt('employee.postponeHelp')}</p>
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label htmlFor="lv-pp-bucket" className={labelCls}>{lt('employee.unusedDays')}</label>
                    <select id="lv-pp-bucket" className={inputCls} value={`${form.data.leave_type_id}|${form.data.entitlement_year}`}
                        onChange={(e) => { const [typeId, year] = e.target.value.split('|'); form.setData((data) => ({ ...data, leave_type_id: typeId, entitlement_year: year })); }}>
                        {waiting.map(({ type, bucket }) => <option key={`${type.id}|${bucket.year}`} value={`${type.id}|${bucket.year}`}>{name(type)} · {bucket.year} · {formatDays(bucket.days)}</option>)}
                    </select>
                    {fieldError(form.errors, 'entitlement_year')}{fieldError(form.errors, 'leave_type_id')}
                </div>
                <div>
                    <label htmlFor="lv-pp-until" className={labelCls}>{lt('employee.untilYear')}</label>
                    <input id="lv-pp-until" type="number" className={inputCls} value={form.data.until_year} onChange={(e) => form.setData('until_year', e.target.value)} required />
                    {fieldError(form.errors, 'until_year')}
                </div>
            </div>
            <div>
                <label htmlFor="lv-pp-reason" className={labelCls}>{lt('form.reason')}</label>
                <input id="lv-pp-reason" type="text" maxLength={1000} className={inputCls} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                {fieldError(form.errors, 'reason')}
            </div>
            <div className="flex flex-wrap justify-end gap-3">
                {onCancel && <button type="button" className={secondaryBtn} onClick={onCancel}>{lt('common.close')}</button>}
                <button type="submit" className={primaryBtn} disabled={form.processing}>{lt('employee.postpone')}</button>
            </div>
        </form>
    );
}

/** Every line that makes up an employee's balances, newest first. */
export function LedgerTable({ ledger }: { ledger: LedgerLine[] }): JSX.Element {
    const { lt, name } = useLeave();

    return (
        <TableCard>
            {ledger.length === 0 ? <EmptyState title={lt('ledger.empty')} /> : (
                <Table>
                    <THead>
                        <Tr>
                            <Th>{lt('ledger.date')}</Th><Th>{lt('table.type')}</Th><Th>{lt('show.budgetYear')}</Th><Th>{lt('ledger.entry')}</Th>
                            <Th>{lt('table.days')}</Th><Th>{lt('ledger.note')}</Th><Th>{lt('ledger.by')}</Th>
                        </Tr>
                    </THead>
                    <TBody>
                        {ledger.map((line) => (
                            <Tr key={line.id}>
                                <Td><LocalizedDateDisplay value={line.created_at} /></Td>
                                <Td>{name(line.leave_type)}</Td>
                                <Td>{line.entitlement_year}</Td>
                                <Td>{lt(`ledger.${line.entry_type}`)}{line.usable_until_year ? ` → ${line.usable_until_year}` : ''}</Td>
                                <Td className={cx('font-medium', line.days < 0 ? 'text-red-700 dark:text-red-400' : line.days > 0 ? 'text-green-700 dark:text-green-400' : '')}>{line.days > 0 ? '+' : ''}{formatDays(line.days)}</Td>
                                <Td>
                                    {line.leave_request_id && line.reference_number
                                        ? <Link href={route('leave.requests.show', line.leave_request_id)} className="text-primary hover:underline">{line.reference_number}</Link>
                                        : null}
                                    {line.note && <span className="block">{line.note}</span>}
                                </Td>
                                <Td>{line.author ?? lt('ledger.system')}</Td>
                            </Tr>
                        ))}
                    </TBody>
                </Table>
            )}
        </TableCard>
    );
}
