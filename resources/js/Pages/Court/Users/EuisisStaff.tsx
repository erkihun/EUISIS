import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    Alert, Button, Card, CardDescription, CardTitle, FormField, Select, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { useEuisisStatus } from '@/court/components/EuisisStaffCard';
import type { EuisisStaffProps, EuisisStaffUnitRow } from '@/court/types/people';

/**
 * Users > EUISIS staff: the EUISIS units the court counts as its own, their employees as
 * EUISIS lists them, each with the staff account linked to them or "Create account"
 * (name and employee number filled in; the email and the court roles are given here),
 * and the accounts the EUISIS staff check suspended or restored lately.
 */
export default function EuisisStaff({ enabled, units, organizations, organization, unitOptions, roster, rosterError, notices, canManageUnits }: EuisisStaffProps) {
    const { t } = useLocale();
    const statusText = useEuisisStatus();
    const [chosen, setChosen] = useState<EuisisStaffUnitRow[]>(units);
    const [unitUuid, setUnitUuid] = useState('');
    const [withSubUnits, setWithSubUnits] = useState(true);
    const [saving, setSaving] = useState(false);
    const title = t('cm.euisis_staff.title');
    const organizationName = organizations.find((row) => row.uuid === organization)?.name ?? null;
    const changed = JSON.stringify(chosen.map((u) => [u.unit_uuid, u.include_sub_units])) !== JSON.stringify(units.map((u) => [u.unit_uuid, u.include_sub_units]));

    const pickOrganization = (uuid: string) => {
        setUnitUuid('');
        router.get(route('court.users.euisis.index'), uuid ? { organization: uuid } : {}, { preserveState: true, preserveScroll: true, only: ['organization', 'unitOptions'] });
    };
    const add = () => {
        const option = unitOptions.find((row) => row.uuid === unitUuid);
        if (!option || chosen.some((row) => row.unit_uuid === option.uuid)) return;
        setChosen([...chosen, { unit_uuid: option.uuid, organization_uuid: organization, name: option.name, organization: organizationName, include_sub_units: withSubUnits }]);
        setUnitUuid('');
    };
    const save = () => {
        router.put(route('court.users.euisis.units'), { units: chosen.map((row) => ({ unit_uuid: row.unit_uuid, include_sub_units: row.include_sub_units })) }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
        });
    };

    const employees = roster?.employees ?? [];
    const linked = employees.filter((row) => row.account !== null).length;

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={t('cm.euisis_staff.subtitle')} backHref={route('court.users.index')}
                actions={<Link href={route('court.users.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.users.back_to_users')}</Link>} />
        )}>
            <Head title={title} />

            <div className="space-y-6">
                {!enabled && <Alert tone="warning">{t('cm.euisis_staff.roster.disabled')}</Alert>}

                <Card className="space-y-4">
                    <div>
                        <CardTitle className="text-lg">{t('cm.euisis_staff.units.title')}</CardTitle>
                        <CardDescription>{t('cm.euisis_staff.units.help')}</CardDescription>
                    </div>

                    {chosen.length === 0
                        ? <p className="text-sm text-(--app-muted-foreground)">{t('cm.euisis_staff.units.none')}</p>
                        : (
                            <ul className="divide-y divide-(--app-border) rounded-card border border-(--app-border)">
                                {chosen.map((row) => (
                                    <li key={row.unit_uuid} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <div className="font-medium text-(--app-foreground)">{row.name}</div>
                                            {row.organization && <div className="text-xs text-(--app-muted-foreground)">{row.organization}</div>}
                                        </div>
                                        <div className="flex flex-wrap items-center gap-3">
                                            {canManageUnits ? (
                                                <label className="flex items-center gap-2 text-sm text-(--app-foreground)">
                                                    <input type="checkbox" className="h-4 w-4 rounded border-(--app-border) text-primary focus:ring-primary" checked={row.include_sub_units}
                                                        onChange={(event) => setChosen(chosen.map((item) => (item.unit_uuid === row.unit_uuid ? { ...item, include_sub_units: event.target.checked } : item)))} />
                                                    {t('cm.euisis_staff.units.include_sub_units')}
                                                </label>
                                            ) : (
                                                <span className={badgeClassName('neutral')}>{row.include_sub_units ? t('cm.euisis_staff.units.include_sub_units') : t('cm.euisis_staff.units.only_this_unit')}</span>
                                            )}
                                            {canManageUnits && (
                                                <button type="button" onClick={() => setChosen(chosen.filter((item) => item.unit_uuid !== row.unit_uuid))} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>
                                                    {t('cm.euisis_staff.units.remove')}
                                                </button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}

                    {canManageUnits ? (
                        <div className="space-y-3 border-t border-(--app-border) pt-4">
                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <FormField id="staff-organization" label={t('cm.euisis_staff.units.organization')}>
                                    {({ id }) => (
                                        <Select id={id} value={organization} onChange={(event) => pickOrganization(event.target.value)}>
                                            <option value="">{t('cm.euisis_staff.units.choose_organization')}</option>
                                            {organizations.map((row) => <option key={row.uuid} value={row.uuid}>{row.name}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                                <FormField id="staff-unit" label={t('cm.euisis_staff.units.unit')}
                                    description={organization && unitOptions.length === 0 ? t('cm.euisis_staff.units.no_units_mirrored') : undefined}>
                                    {({ id, describedBy }) => (
                                        <Select id={id} value={unitUuid} disabled={unitOptions.length === 0} aria-describedby={describedBy} onChange={(event) => setUnitUuid(event.target.value)}>
                                            <option value="">{t('cm.euisis_staff.units.choose_unit')}</option>
                                            {unitOptions.map((row) => <option key={row.uuid} value={row.uuid}>{row.code ? `${row.name} (${row.code})` : row.name}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <label className="flex items-center gap-2 text-sm text-(--app-foreground)">
                                    <input type="checkbox" className="h-4 w-4 rounded border-(--app-border) text-primary focus:ring-primary" checked={withSubUnits} onChange={(event) => setWithSubUnits(event.target.checked)} />
                                    {t('cm.euisis_staff.units.include_sub_units')}
                                </label>
                                <div className="flex flex-wrap gap-2">
                                    <button type="button" onClick={add} disabled={unitUuid === ''} className={buttonClassName({ variant: 'outline' })}>{t('cm.euisis_staff.units.add')}</button>
                                    <Button type="button" variant="primary" loading={saving} disabled={!changed} onClick={save}>{t('cm.euisis_staff.units.save')}</Button>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <p className="text-xs text-(--app-muted-foreground)">{t('cm.euisis_staff.units.read_only')}</p>
                    )}
                </Card>

                <div className="space-y-3">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="text-lg font-semibold text-(--app-foreground)">{t('cm.euisis_staff.roster.title')}</h2>
                        {roster && employees.length > 0 && (
                            <p className="text-sm text-(--app-muted-foreground)">
                                {t('cm.euisis_staff.roster.summary').replace(':linked', String(linked)).replace(':total', String(employees.length))}
                            </p>
                        )}
                    </div>
                    {rosterError && <Alert tone="danger">{rosterError}</Alert>}
                    {roster && roster.skipped_units.length > 0 && (
                        <Alert tone="warning">{t('cm.euisis_staff.roster.skipped_units').replace(':units', roster.skipped_units.join(', '))}</Alert>
                    )}

                    <div className={tableContainerClassName}>
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        {['employee_number', 'name', 'position', 'unit', 'status', 'account'].map((key) => (
                                            <th key={key} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{t(`cm.euisis_staff.roster.${key}`)}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {employees.map((row) => (
                                        <tr key={row.id} className={tableRowClassName}>
                                            <td className={cx(tableCellClassName, 'whitespace-nowrap font-mono text-sm')}>{row.employee_number}</td>
                                            <td className={tableCellClassName}>
                                                <div className="font-medium text-(--app-foreground)">{row.name}</div>
                                                {row.name_en && row.name_en !== row.name && <div className="text-xs text-(--app-muted-foreground)">{row.name_en}</div>}
                                            </td>
                                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{row.position ?? '—'}</td>
                                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{row.unit ?? '—'}</td>
                                            <td className={tableCellClassName}><span className={badgeClassName(row.status === 'active' ? 'success' : 'warning')}>{statusText(row.status)}</span></td>
                                            <td className={tableCellClassName}>
                                                {row.account ? (
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Link href={route('court.users.show', row.account.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.euisis_staff.roster.open_account')}</Link>
                                                        <span className="text-xs text-(--app-muted-foreground)">{row.account.email}</span>
                                                        {row.account.suspended && <span className={badgeClassName('danger')}>{t('cm.euisis_staff.roster.suspended')}</span>}
                                                    </div>
                                                ) : (
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className={badgeClassName('neutral')}>{t('cm.euisis_staff.roster.not_linked')}</span>
                                                        {row.status === 'active' && (
                                                            <Link href={route('court.users.create', { euisis_employee: row.id })} className={buttonClassName({ size: 'sm' })}>{t('cm.euisis_staff.roster.create_account')}</Link>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {employees.length === 0 && (
                            <EmptyState title={units.length === 0 ? t('cm.euisis_staff.roster.choose_units_first') : t('cm.euisis_staff.roster.empty')} />
                        )}
                    </div>
                </div>

                <Card className="space-y-3">
                    <CardTitle className="text-lg">{t('cm.euisis_staff.notices.title')}</CardTitle>
                    {notices.length === 0
                        ? <p className="text-sm text-(--app-muted-foreground)">{t('cm.euisis_staff.notices.none')}</p>
                        : (
                            <ul className="divide-y divide-(--app-border)">
                                {notices.map((notice) => (
                                    <li key={notice.id} className="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className={badgeClassName(notice.event === 'suspended' ? 'danger' : 'success')}>{t(`cm.euisis_staff.notices.${notice.event}`)}</span>
                                            <Link href={route('court.users.show', notice.user_id)} className="font-medium text-(--app-foreground) hover:underline">{notice.name}</Link>
                                            {notice.reason && <span className="text-sm text-(--app-muted-foreground)">{notice.reason}</span>}
                                        </div>
                                        <span className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={notice.at} withTime /></span>
                                    </li>
                                ))}
                            </ul>
                        )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
