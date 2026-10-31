import { router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Alert, Button, Card, CardTitle, FormField, Input, badgeClassName, buttonClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import type { EuisisStaffPanel } from '@/court/types/people';

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-[0.16em] text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-1 font-medium text-(--app-foreground)">{children || '-'}</dd>
        </div>
    );
}

/** An EUISIS employment status in words, or as EUISIS sent it when the dictionary has no word for it. */
export function useEuisisStatus() {
    const { t } = useLocale();

    return (status: string | null): string => {
        if (!status) return '-';
        const key = `cm.euisis_staff.statuses.${status}`;
        const word = t(key);
        return word === key ? status : word;
    };
}

/**
 * The staff account's EUISIS employee on the profile page: who EUISIS says they are and
 * where they work (read-only), an EUISIS staff suspension, and for managers the link by
 * employee number and the unlink.
 */
export default function EuisisStaffCard({ userId, euisis, canLink }: { userId: number; euisis: EuisisStaffPanel; canLink: boolean }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const statusText = useEuisisStatus();
    const form = useForm({ employee_number: '' });
    const employee = euisis.employee;

    if (!euisis.enabled && !euisis.linked) return null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.users.euisis.link', userId), { preserveScroll: true, onSuccess: () => form.reset() });
    };
    const unlink = async () => {
        const { confirmed } = await confirm({
            title: t('cm.euisis_staff.panel.unlink'),
            description: t('cm.euisis_staff.panel.unlink_confirm'),
            confirmLabel: t('cm.euisis_staff.panel.unlink'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.users.euisis.unlink', userId), { preserveScroll: true });
    };

    return (
        <Card className="space-y-4 lg:col-span-3">
            <CardTitle className="border-b border-(--app-border) pb-3 text-lg">{t('cm.euisis_staff.panel.title')}</CardTitle>

            {euisis.suspended_at && (
                <Alert tone="danger">{t('cm.euisis_staff.panel.suspended').replace(':reason', euisis.suspension_reason ?? '')}</Alert>
            )}

            {euisis.linked && employee ? (
                <>
                    {!employee.in_court && !euisis.suspended_at && <Alert tone="warning">{t('cm.euisis_staff.panel.outside_court')}</Alert>}
                    <dl className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <Field label={t('cm.euisis_staff.panel.employee_number')}>{employee.employee_number}</Field>
                        <Field label={t('cm.euisis_staff.panel.name')}>
                            {employee.name}
                            {employee.name_en && employee.name_en !== employee.name && <span className="block text-sm font-normal text-(--app-muted-foreground)">{employee.name_en}</span>}
                        </Field>
                        <Field label={t('cm.euisis_staff.panel.status')}>
                            <span className={badgeClassName(employee.status === 'active' ? 'success' : 'warning')}>{statusText(employee.status)}</span>
                        </Field>
                        <Field label={t('cm.euisis_staff.panel.position')}>{employee.position}</Field>
                        <Field label={t('cm.euisis_staff.panel.unit')}>{employee.unit}</Field>
                        <Field label={t('cm.euisis_staff.panel.organization')}>{employee.organization}</Field>
                        <Field label={t('cm.euisis_staff.panel.linked_at')}><LocalizedDateDisplay value={euisis.linked_at} withTime /></Field>
                        <Field label={t('cm.euisis_staff.panel.synced_at')}><LocalizedDateDisplay value={employee.synced_at} withTime /></Field>
                    </dl>
                    <p className="text-xs text-(--app-muted-foreground)">{t('cm.euisis_staff.panel.read_only')}</p>
                    {canLink && (
                        <div className="flex justify-end">
                            <button type="button" onClick={() => void unlink()} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>{t('cm.euisis_staff.panel.unlink')}</button>
                        </div>
                    )}
                </>
            ) : (
                <>
                    <p className="text-sm text-(--app-muted-foreground)">{euisis.enabled ? t('cm.euisis_staff.panel.not_linked') : t('cm.euisis_staff.panel.disabled')}</p>
                    {canLink && euisis.enabled && (
                        <form onSubmit={submit} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div className="flex-1">
                                <FormField id="euisis-employee-number" label={t('cm.euisis_staff.panel.employee_number')} error={form.errors.employee_number} description={t('cm.euisis_staff.panel.link_help')}>
                                    {({ id, describedBy, invalid }) => (
                                        <Input id={id} value={form.data.employee_number} maxLength={50} aria-describedby={describedBy} aria-invalid={invalid || undefined}
                                            onChange={(event) => form.setData('employee_number', event.target.value)} />
                                    )}
                                </FormField>
                            </div>
                            <Button type="submit" variant="primary" loading={form.processing} disabled={form.data.employee_number.trim() === ''}>{t('cm.euisis_staff.panel.link')}</Button>
                        </form>
                    )}
                </>
            )}
        </Card>
    );
}
