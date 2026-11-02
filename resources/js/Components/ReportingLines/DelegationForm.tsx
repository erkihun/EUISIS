import { useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { Button, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useEffect, useState } from 'react';
import type { ActionOption, LookupEmployee } from './types';

/**
 * Lend supervisor actions to a colleague for a period (ADR 0025). On My Team
 * the delegator is always the signed-in employee; on the administration page
 * HR picks the delegator from the organization. The server re-checks that
 * the delegator supervises someone on the start date and that the delegate
 * is another active employee of the same organization.
 */
export default function DelegationForm({ organizationId, actions, storeRoute, pickDelegator = false, onDone }: {
    organizationId: string | null;
    actions: ActionOption[];
    storeRoute: string;
    pickDelegator?: boolean;
    onDone?: () => void;
}) {
    const { t, locale } = useLocale();
    const [term, setTerm] = useState('');
    const [employees, setEmployees] = useState<LookupEmployee[]>([]);
    const form = useForm<{ delegator_employee_id: string; delegate_employee_id: string; actions: string[]; starts_on: string; ends_on: string; reason: string }>({
        delegator_employee_id: '', delegate_employee_id: '', actions: [], starts_on: new Date().toISOString().slice(0, 10), ends_on: '', reason: '',
    });
    const name = (en: string | null, am: string | null): string => (locale === 'am' ? (am ?? en ?? '') : (en ?? ''));

    useEffect(() => {
        if (!organizationId) return;
        const handle = window.setTimeout(() => {
            fetch(route('reporting-lines.lookup.employees', { organization_id: organizationId, q: term }), { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : { data: [] }))
                .then((json: { data: LookupEmployee[] }) => setEmployees(json.data))
                .catch(() => setEmployees([]));
        }, 250);
        return () => window.clearTimeout(handle);
    }, [organizationId, term]);

    function toggle(action: string) {
        form.setData('actions', form.data.actions.includes(action) ? form.data.actions.filter((a) => a !== action) : [...form.data.actions, action]);
    }

    function submit() {
        form.transform((data) => (pickDelegator ? data : { ...data, delegator_employee_id: undefined }));
        form.post(storeRoute, { preserveScroll: true, onSuccess: () => { form.reset(); onDone?.(); } });
    }

    const option = (em: LookupEmployee) => (
        <option key={em.id} value={em.id}>{name(em.full_name, em.name_am)} · {em.employee_number}{em.position_title_en ? ` · ${localizedName(em.position_title_en, em.position_title_am, locale)}` : ''}</option>
    );

    return (
        <form className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5" onSubmit={(e) => { e.preventDefault(); submit(); }}>
            <h3 className="mb-4 text-sm font-semibold text-(--app-foreground)">{t('reportingLines.newDelegation')}</h3>
            {!organizationId && <p className="mb-3 text-sm text-(--app-muted-foreground)">{t('reportingLines.pickOrganization')}</p>}
            <div className="grid gap-4 md:grid-cols-2">
                <FormField label={t('reportingLines.searchEmployee')} className="md:col-span-2">
                    <Input type="search" value={term} onChange={(e) => setTerm(e.target.value)} placeholder={t('reportingLines.searchEmployee')} />
                </FormField>
                {pickDelegator && (
                    <FormField label={t('reportingLines.delegator')} error={form.errors.delegator_employee_id} required>
                        <Select value={form.data.delegator_employee_id} onChange={(e) => form.setData('delegator_employee_id', e.target.value)} required>
                            <option value="">—</option>
                            {employees.map(option)}
                        </Select>
                    </FormField>
                )}
                <FormField label={t('reportingLines.delegate')} error={form.errors.delegate_employee_id} required>
                    <Select value={form.data.delegate_employee_id} onChange={(e) => form.setData('delegate_employee_id', e.target.value)} required>
                        <option value="">—</option>
                        {employees.map(option)}
                    </Select>
                </FormField>
                <FormField label={t('reportingLines.actions')} error={form.errors.actions} className="md:col-span-2" required>
                    <div className="flex flex-wrap gap-3">
                        {actions.map((a) => (
                            <label key={a.value} className="flex items-center gap-2 text-sm text-(--app-foreground)">
                                <input type="checkbox" className="rounded border-(--app-border-strong)" checked={form.data.actions.includes(a.value)} onChange={() => toggle(a.value)} />
                                {a.label}
                            </label>
                        ))}
                    </div>
                </FormField>
                <FormField label={t('reportingLines.startsOn')} error={form.errors.starts_on} required>
                    <Input type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} required />
                </FormField>
                <FormField label={t('reportingLines.endsOn')} error={form.errors.ends_on}>
                    <Input type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} />
                </FormField>
                <FormField label={t('reportingLines.reason')} error={form.errors.reason} className="md:col-span-2">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} rows={2} />
                </FormField>
            </div>
            <div className="mt-4 flex gap-2">
                <Button type="submit" disabled={form.processing || !organizationId}>{t('reportingLines.create')}</Button>
                {onDone && <Button type="button" variant="ghost" onClick={onDone}>{t('confirmations.cancel')}</Button>}
            </div>
        </form>
    );
}
