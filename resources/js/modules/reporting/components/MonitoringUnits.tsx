import { cx } from '@/Components/ui';
import { OrganizationPicker } from '@/modules/reporting/components/Pickers';
import { inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { OrganizationRef, UnitRef } from '@/modules/reporting/types';
import { router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type JSX } from 'react';

export interface MonitoringUnitRow {
    organization: OrganizationRef;
    unit: UnitRef;
}

/**
 * The work unit that follows up reports in each institution. An institution
 * names one of the units already in its structure; the people placed in it
 * then receive and follow up the institution's reports. One form, top to
 * bottom: the institution, its unit, save.
 */
export default function MonitoringUnits({ rows, canManage }: { rows: MonitoringUnitRow[]; canManage: boolean }): JSX.Element {
    const { rt, name } = useReporting();
    const [organization, setOrganization] = useState<OrganizationRef | null>(null);
    const [units, setUnits] = useState<UnitRef[]>([]);
    const [unitId, setUnitId] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setUnits([]);
        setUnitId('');
        if (!organization) return;
        let current = true;
        window.axios.get(route('reporting.lookups.units'), { params: { organization_id: organization.id } })
            .then((response: { data: { items: UnitRef[] } }) => {
                if (!current) return;
                setUnits(response.data.items);
                // Changing an institution's unit starts from the one it has now.
                setUnitId(rows.find((row) => row.organization.id === organization.id)?.unit.id ?? '');
            })
            .catch(() => undefined);

        return () => { current = false; };
    }, [organization?.id]);

    const save = (event: FormEvent) => {
        event.preventDefault();
        if (!organization || unitId === '') return;
        setBusy(true);
        router.post(route('reporting.monitoring-units.store'), { organization_id: organization.id, organization_unit_id: unitId }, {
            preserveScroll: true,
            onSuccess: () => setOrganization(null),
            onFinish: () => setBusy(false),
        });
    };
    const remove = (row: MonitoringUnitRow) => router.delete(route('reporting.monitoring-units.destroy', row.organization.id), { preserveScroll: true });

    return (
        <section className={cx(panelCls, 'space-y-3 p-4')} aria-labelledby="st-units">
            <h2 id="st-units" className={sectionTitleCls}>{rt('settings.unitsTitle')}</h2>
            <p className={mutedCls}>{rt('settings.unitsHelp')}</p>

            {canManage && (
                <form onSubmit={save} className="grid items-end gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                    <OrganizationPicker label={rt('register.col.institution')} value={organization} onChange={setOrganization} />
                    <div>
                        <label htmlFor="st-unit" className={labelCls}>{rt('settings.unitsUnit')}</label>
                        <select id="st-unit" className={inputCls} value={unitId} disabled={organization === null} onChange={(e) => setUnitId(e.target.value)}>
                            <option value="">{rt(organization !== null && units.length === 0 ? 'settings.unitsNone' : 'settings.unitsChoose')}</option>
                            {units.map((unit) => <option key={unit.id} value={unit.id}>{unit.code ? `${unit.code} · ` : ''}{name(unit)}</option>)}
                        </select>
                    </div>
                    <button type="submit" className={primaryBtn} disabled={busy || organization === null || unitId === ''}>{rt('common.save')}</button>
                </form>
            )}

            {rows.length === 0 ? <p className={mutedCls}>{rt('settings.unitsEmpty')}</p> : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <caption className="sr-only">{rt('settings.unitsTitle')}</caption>
                        <thead>
                            <tr className="border-b border-(--app-border) text-xs text-(--app-muted-foreground)">
                                <th scope="col" className="px-2 py-2 text-start font-medium">{rt('register.col.institution')}</th>
                                <th scope="col" className="px-2 py-2 text-start font-medium">{rt('settings.unitsUnit')}</th>
                                {canManage && <th scope="col" className="px-2 py-2"><span className="sr-only">{rt('common.remove')}</span></th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-(--app-border)">
                            {rows.map((row) => (
                                <tr key={row.organization.id}>
                                    <th scope="row" className="px-2 py-2 text-start font-normal">
                                        <span className="font-medium">{name(row.organization)}</span>
                                        <span className={cx(mutedCls, 'block text-xs')}>{row.organization.code}</span>
                                    </th>
                                    <td className="px-2 py-2">
                                        {name(row.unit)}
                                        {row.unit.code && <span className={cx(mutedCls, 'block text-xs')}>{row.unit.code}</span>}
                                    </td>
                                    {canManage && <td className="px-2 py-2 text-end"><button type="button" className={secondaryBtn} onClick={() => remove(row)}>{rt('common.remove')}</button></td>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
