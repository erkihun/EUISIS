import ConfirmationDialog from '@/Components/ConfirmationDialog';
import PageHeader from '@/Components/PageHeader';
import ActionErrors from '@/Components/letters/ActionErrors';
import { errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, useLetters } from '@/Components/letters/helpers';
import { badgeClassName, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { OrganizationOption, RegistryOfficeRow, UnitOption } from '@/types/letters';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

interface Props {
    organizations: OrganizationOption[];
    selected_organization_id: string;
    offices: RegistryOfficeRow[];
    units: UnitOption[];
}

/**
 * Registry offices (መዝገብ ቤት) of one institution: a unit of its structure is
 * designated as the registry, and the page shows who works there and whether
 * they can register and forward letters.
 */
export default function RegistryOffices({ organizations, selected_organization_id, offices, units }: Props): JSX.Element {
    const { lt, pick, locale } = useLetters();
    const form = useForm({ organization_id: selected_organization_id, organization_unit_id: '', note: '' });
    const [removing, setRemoving] = useState<RegistryOfficeRow | null>(null);
    const [busy, setBusy] = useState(false);

    function designate(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, note: d.note || null }));
        form.post(route('letters.registry-offices.store'), { preserveScroll: true, onSuccess: () => form.reset('organization_unit_id', 'note') });
    }

    function setStatus(office: RegistryOfficeRow, status: 'active' | 'inactive') {
        setBusy(true);
        router.put(route('letters.registry-offices.update', office.id), { status, note: office.note }, { preserveScroll: true, onFinish: () => setBusy(false) });
    }

    const unitName = (office: RegistryOfficeRow): string => (office.unit ? pick({ name_en: office.unit.name_en ?? '', name_am: office.unit.name_am, code: office.unit.code }) : '—');

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('registryOffices.title')} description={lt('registryOffices.description')} />}>
            <Head title={lt('registryOffices.title')} />
            <ActionErrors />
            <div className="space-y-5 pb-4">
                {organizations.length > 1 && (
                    <div className={cx(panelCls, 'p-4')}>
                        <label htmlFor="ro-org" className={labelCls}>{lt('registryOffices.institution')}</label>
                        <select id="ro-org" className={cx(inputCls, 'max-w-xl')} value={selected_organization_id}
                            onChange={(e) => router.get(route('letters.registry-offices.index'), { organization_id: e.target.value }, { preserveState: false })}>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{pick(o)}</option>)}
                        </select>
                    </div>
                )}

                {/* Designate: one choice from the structure, nothing typed again */}
                <form onSubmit={designate} className={cx(panelCls, 'space-y-3 p-4')} aria-label={lt('registryOffices.add')}>
                    <h2 className={sectionTitleCls}>{lt('registryOffices.add')}</h2>
                    {units.length === 0 ? <p className={mutedCls}>{lt('registryOffices.noUnits')}</p> : (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label htmlFor="ro-unit" className={labelCls}>{lt('registryOffices.unit')}</label>
                                <select id="ro-unit" className={inputCls} value={form.data.organization_unit_id} onChange={(e) => form.setData('organization_unit_id', e.target.value)} required>
                                    <option value="">{lt('registryOffices.chooseUnit')}</option>
                                    {units.map((u) => <option key={u.id} value={u.id}>{pick(u)}</option>)}
                                </select>
                                {form.errors.organization_unit_id && <p className={errorCls} role="alert">{form.errors.organization_unit_id}</p>}
                            </div>
                            <div>
                                <label htmlFor="ro-note" className={labelCls}>{lt('registryOffices.note')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                                <input id="ro-note" type="text" className={inputCls} value={form.data.note} maxLength={500} placeholder={lt('registryOffices.noteHelp')} onChange={(e) => form.setData('note', e.target.value)} />
                                {form.errors.note && <p className={errorCls} role="alert">{form.errors.note}</p>}
                            </div>
                            <div className="sm:col-span-2">
                                <button type="submit" className={primaryBtn} disabled={form.processing || form.data.organization_unit_id === ''}>{lt('registryOffices.designate')}</button>
                            </div>
                        </div>
                    )}
                </form>

                {offices.length === 0 ? <p className={cx(panelCls, mutedCls, 'p-4')}>{lt('registryOffices.none')}</p> : (
                    <ul className="space-y-4">
                        {offices.map((office) => (
                            <li key={office.id} className={cx(panelCls, 'p-4')}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h2 className="flex flex-wrap items-center gap-2 text-base font-semibold">
                                            {unitName(office)}
                                            <span className={badgeClassName(office.status === 'active' ? 'success' : 'neutral')}>{lt(`registryOffices.${office.status}`)}</span>
                                        </h2>
                                        {office.note && <p className={cx(mutedCls, 'mt-0.5')}>{office.note}</p>}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <button type="button" className={secondaryBtn} disabled={busy} onClick={() => setStatus(office, office.status === 'active' ? 'inactive' : 'active')}>
                                            {office.status === 'active' ? lt('registryOffices.deactivate') : lt('registryOffices.activate')}
                                        </button>
                                        <button type="button" className={secondaryBtn} disabled={busy} onClick={() => setRemoving(office)}>{lt('registryOffices.remove')}</button>
                                    </div>
                                </div>

                                {office.problems.length > 0 && (
                                    <ul className="mt-3 space-y-1 rounded-control border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100" role="status">
                                        {office.problems.map((problem) => <li key={problem}>{lt(`registryOffices.problems.${problem}`)}</li>)}
                                    </ul>
                                )}

                                <div className="mt-3 border-t border-(--app-border) pt-3">
                                    <h3 className="text-sm font-semibold">{lt('registryOffices.staff')} <span className={cx(mutedCls, 'font-normal')}>· {lt('registryOffices.staffHelp')}</span></h3>
                                    {office.staff.length === 0 ? <p className={cx(mutedCls, 'mt-1')}>{lt('registryOffices.noStaff')}</p> : (
                                        <ul className="mt-2 divide-y divide-(--app-border)">
                                            {office.staff.map((person) => (
                                                <li key={person.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                                    <span className="min-w-0">
                                                        <span className="font-medium">{person.name}</span>
                                                        {person.position && <span className={cx(mutedCls, 'ms-2')}>{(locale === 'am' ? person.position.title_am || person.position.title_en : person.position.title_en || person.position.title_am) ?? ''}</span>}
                                                    </span>
                                                    <span className="flex flex-wrap gap-1.5">
                                                        {person.can_register && <span className={badgeClassName('success')}>{lt('registryOffices.canRegister')}</span>}
                                                        {person.can_route && <span className={badgeClassName('info')}>{lt('registryOffices.canRoute')}</span>}
                                                        {!person.can_register && !person.can_route && <span className={badgeClassName('neutral')}>{lt('registryOffices.noAccess')}</span>}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <p className={mutedCls}>{lt('registryOffices.rolesNote')}</p>
            </div>

            <ConfirmationDialog open={removing !== null} onClose={() => setRemoving(null)} loading={busy} variant="warning" title={lt('registryOffices.remove')}
                description={lt('registryOffices.confirmRemove')} confirmLabel={lt('registryOffices.remove')} cancelLabel={lt('common.cancel')}
                onConfirm={() => {
                    if (!removing) return;
                    setBusy(true);
                    router.delete(route('letters.registry-offices.destroy', removing.id), { preserveScroll: true, onFinish: () => { setBusy(false); setRemoving(null); } });
                }} />
        </AuthenticatedLayout>
    );
}
