import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
type OrganizationUnit = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
};

type Position = {
    id: string;
    job_position_code: string;
    title_en: string;
    title_am: string | null;
    organization: { name_en: string; name_am: string | null };
    organization_unit: OrganizationUnit;
};

type Props = {
    position: Position;
    targetOrganizationUnits: OrganizationUnit[];
    isOccupied: boolean;
};

export default function MovePosition({ position, targetOrganizationUnits, isOccupied }: Props) {
    const { t, locale } = useLocale();
    const form = useForm({
        target_organization_unit_id: '',
        reason: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route('positions.move.store', position.id));
    }

    const labelClass = 'block text-sm font-medium text-[color:var(--app-foreground)]';
    const inputClass = cx(inputClassName, 'mt-1 block w-full');
    const readOnlyClass = `${inputClass} bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]`;

    return (
        <AuthenticatedLayout
            header={<PageHeader backHref={route('positions.show', position.id)} title={t('positions.movePosition')} />}
        >
            <Head title={t('positions.movePosition')} />

            <form onSubmit={submit} className="mx-auto max-w-3xl space-y-6 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                {isOccupied && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300">
                        {t('positions.occupiedCannotMove')}
                    </div>
                )}

                <div className="grid gap-5 md:grid-cols-2">
                    <label className={labelClass}>
                        {t('positions.currentOrganization')}
                        <input readOnly className={readOnlyClass} value={localizedName(position.organization.name_en, position.organization.name_am, locale)} />
                    </label>

                    <label className={labelClass}>
                        {t('positions.currentOrganizationUnit')}
                        <input readOnly className={readOnlyClass} value={localizedName(position.organization_unit.name_en, position.organization_unit.name_am, locale)} />
                    </label>
                </div>

                <label className={labelClass}>
                    {t('positions.position')}
                    <input readOnly className={readOnlyClass} value={`${position.job_position_code} · ${localizedName(position.title_en, position.title_am, locale)}`} />
                </label>

                <label className={labelClass}>
                    {t('positions.targetOrganizationUnit')}
                    <select
                        className={inputClass}
                        value={form.data.target_organization_unit_id}
                        onChange={(event) => form.setData('target_organization_unit_id', event.target.value)}
                        disabled={isOccupied}
                    >
                        <option value="">{t('positions.selectTargetOrganizationUnit')}</option>
                        {targetOrganizationUnits.map((unit) => (
                            <option key={unit.id} value={unit.id}>
                                {unit.code} · {localizedName(unit.name_en, unit.name_am, locale)}
                            </option>
                        ))}
                    </select>
                    {form.errors.target_organization_unit_id && <span className="mt-1 block text-sm text-red-600">{form.errors.target_organization_unit_id}</span>}
                </label>

                <label className={labelClass}>
                    {t('positions.moveReason')}
                    <textarea
                        className={inputClass}
                        rows={5}
                        maxLength={2000}
                        required
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        disabled={isOccupied}
                    />
                    {form.errors.reason && <span className="mt-1 block text-sm text-red-600">{form.errors.reason}</span>}
                </label>

                <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300">
                    {t('positions.codeRemainsUnchanged')}
                </div>

                <div className="flex justify-end gap-3">
                    <Link href={route('positions.show', position.id)} className="rounded-lg border border-[color:var(--app-border-strong)] px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]">
                        {t('common.cancel')}
                    </Link>
                    <button
                        type="submit"
                        disabled={isOccupied || form.processing || form.data.target_organization_unit_id === ''}
                        className={buttonClassName({ variant: 'primary' })}
                    >
                        {t('positions.confirmMove')}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
