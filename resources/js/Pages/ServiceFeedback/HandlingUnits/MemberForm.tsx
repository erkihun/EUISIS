import { useLocale } from '@/hooks/useLocale';
import { Button, Card, FormField, Input, SearchableSelect, Select, type SearchableOption } from '@euisis/ui';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export type CaseType = 'suggestion' | 'complaint';
export type Responsibility = 'head' | 'officer';

export type HandlingMember = {
    id: string;
    employee_id: string;
    name: string | null;
    employee_number: string | null;
    responsibility: Responsibility;
    effective_from: string | null;
    effective_to: string | null;
    appointment_reference: string | null;
    placed: boolean;
    has_account: boolean;
    signature_url: string;
    signature_recorded_at: string | null;
};

export type HandlingUnitRef = { id: string; unit: string | null; organization: string | null; case_type: CaseType; has_head: boolean };

const SIGNATURE_ACCEPT = 'image/png,image/jpeg,image/webp';

function today(): string {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

/**
 * Registering a member of a handling unit (ADR 0034), or editing one: the
 * member's responsibility and their signature image. One form for the create
 * and the edit page; saving returns to the list.
 */
export default function MemberForm({ unit, member, responsibilities }: { unit: HandlingUnitRef; member: HandlingMember | null; responsibilities: Responsibility[] }) {
    const { t } = useLocale();
    const h = (key: string) => t(`serviceFeedback.handling.${key}`);
    const form = useForm<{ employee_id: string; responsibility: Responsibility; effective_from: string; effective_to: string; appointment_reference: string; signature: File | null }>({
        employee_id: member?.employee_id ?? '',
        responsibility: member?.responsibility ?? (unit.has_head ? 'officer' : 'head'),
        effective_from: member?.effective_from ?? today(),
        effective_to: member?.effective_to ?? '',
        appointment_reference: member?.appointment_reference ?? '',
        signature: null,
    });

    async function searchEmployees(query: string, signal: AbortSignal): Promise<SearchableOption[]> {
        const response = await fetch(route('lookup.employees', { q: query }), { signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) return [];
        const body = (await response.json()) as { data: { id: string; full_name: string | null; employee_number: string | null; organization: { name_en: string | null } | null }[] };
        return body.data.map((row) => ({ value: row.id, label: row.full_name ?? row.employee_number ?? row.id, description: [row.employee_number, row.organization?.name_en].filter(Boolean).join(' · ') }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        if (member === null) {
            form.post(route('service-feedback.handling-units.members.store', unit.id), { forceFormData: true });
        } else {
            form.post(route('service-feedback.handling-units.members.update', { unit: unit.id, member: member.id }), { forceFormData: true });
        }
    }

    return (
        <form onSubmit={submit}>
            <Card className="overflow-hidden">
                <div className="grid gap-4 p-6">
                    <p className="text-sm text-(--app-muted-foreground)">{[unit.unit, unit.organization, h(`caseTypes.${unit.case_type}`)].filter(Boolean).join(' · ')}</p>
                    {member === null ? (
                        <FormField id="hu-member-employee" label={h('employee')} description={h('memberHint')} error={form.errors.employee_id} required>
                            {({ id }) => <SearchableSelect id={id} label={h('employee')} value={form.data.employee_id} loadOptions={searchEmployees} onChange={(value) => form.setData('employee_id', value)} placeholder={t('serviceFeedback.searchEmployee')} />}
                        </FormField>
                    ) : <div><div className="text-sm text-(--app-muted-foreground)">{h('employee')}</div><div className="font-medium">{member.name ?? member.employee_number}</div></div>}
                    <FormField id="hu-member-responsibility" label={h('responsibilityLabel')} error={form.errors.responsibility} required>
                        {({ id, describedBy, invalid }) => <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.responsibility} onChange={(e) => form.setData('responsibility', e.target.value as Responsibility)}>
                            {responsibilities.map((r) => <option key={r} value={r}>{h(`responsibility.${r}`)}</option>)}
                        </Select>}
                    </FormField>
                    {member === null && <div className="grid gap-4 sm:grid-cols-2">
                        <FormField id="hu-member-from" label={h('from')} error={form.errors.effective_from} required>
                            {({ id }) => <Input id={id} type="date" value={form.data.effective_from} onChange={(e) => form.setData('effective_from', e.target.value)} />}
                        </FormField>
                        <FormField id="hu-member-to" label={h('to')} error={form.errors.effective_to}>
                            {({ id }) => <Input id={id} type="date" value={form.data.effective_to} min={form.data.effective_from || undefined} onChange={(e) => form.setData('effective_to', e.target.value)} />}
                        </FormField>
                    </div>}
                    <FormField id="hu-member-ref" label={h('appointmentReference')} error={form.errors.appointment_reference}>
                        {({ id }) => <Input id={id} maxLength={255} value={form.data.appointment_reference} onChange={(e) => form.setData('appointment_reference', e.target.value)} />}
                    </FormField>
                    {member !== null && <figure>
                        <img src={member.signature_url} alt={h('signatureOf').replace('{name}', member.name ?? '')} className="h-16 w-40 rounded-control border border-(--app-border) bg-white object-contain p-1" />
                        <figcaption className="mt-1 text-xs text-(--app-muted-foreground)">{h('currentSignature')}</figcaption>
                    </figure>}
                    <FormField id="hu-member-signature" label={h('signature')} description={h(member === null ? 'signatureHint' : 'signatureReplaceHint')} error={form.errors.signature} required={member === null}>
                        {({ id, describedBy, invalid }) => <input id={id} type="file" accept={SIGNATURE_ACCEPT} aria-describedby={describedBy} aria-invalid={invalid} required={member === null}
                            className="block w-full text-sm file:mr-3 file:rounded-control file:border file:border-(--app-border) file:bg-(--app-surface-muted) file:px-3 file:py-1.5 file:text-sm"
                            onChange={(e) => form.setData('signature', e.target.files?.[0] ?? null)} />}
                    </FormField>
                </div>
                <div className="flex justify-end gap-2 border-t border-(--app-border) px-6 py-4">
                    <Button as={Link} href={route('service-feedback.handling-units.index', { case_type: unit.case_type })} variant="outline">{t('common.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{t('common.save')}</Button>
                </div>
            </Card>
        </form>
    );
}
