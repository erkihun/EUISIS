import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    Button, Card, FilterField, FilterPanel, FormField, Input, ListPagination, Select, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
    type LaravelPageMeta,
} from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

interface LawyerRow {
    id: number;
    name: string;
    email: string;
    phone: string;
    license_number: string | null;
    verified: boolean;
    verified_at: string | null;
    applied_at: string | null;
    refused: boolean;
    refusal_reason: string | null;
    active: boolean;
    has_document: boolean;
    cases: number;
}

interface LawyersIndexProps {
    lawyers: LaravelPageMeta & { data: LawyerRow[] };
    search: string;
    canManage: boolean;
}

interface NewLawyer {
    first_name: string;
    middle_name: string;
    last_name: string;
    gender: string;
    email: string;
    phone: string;
    national_id_number: string;
    address: string;
    license_number: string;
    license_document: File | null;
}

const blank: NewLawyer = {
    first_name: '', middle_name: '', last_name: '', gender: '', email: '', phone: '', national_id_number: '', address: '', license_number: '', license_document: null,
};

/**
 * The court's register of lawyers (Admin\LawyerController, EUISIS ADR 0013 #13).
 * The registry opens a lawyer's account, verified on their licence, and the
 * lawyer chooses a password from the emailed link; only a verified, active
 * lawyer signs in at /lawyer/login and is offered to employees who file.
 * Applications sent at /lawyer/register come first. Each lawyer opens on
 * their own page (Lawyers/Show), where the registry checks the details and
 * the licence before it verifies or refuses.
 */
export default function LawyersIndex({ lawyers, search, canManage }: LawyersIndexProps) {
    const { t } = useLocale();
    const r = (key: string) => t(`cm.lawyer.register.${key}`);
    const [q, setQ] = useState(search);
    const [adding, setAdding] = useState(false);
    const form = useForm<NewLawyer>(blank);
    const title = r('title');

    const filter = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(route('court.lawyers.index'), q ? { q } : {}, { preserveState: true });
    };
    const open = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.lawyers.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset(); setAdding(false); } });
    };
    const waiting = (lawyer: LawyerRow) => Boolean(lawyer.applied_at) && !lawyer.verified && !lawyer.refused;

    const field = (key: Exclude<keyof NewLawyer, 'license_document' | 'gender'>, type = 'text') => (
        <FormField label={r(key === 'national_id_number' ? 'national_id' : key)} required error={form.errors[key]}>
            {({ id, describedBy, invalid }) => (
                <Input id={id} type={type} value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)} required aria-describedby={describedBy} aria-invalid={invalid || undefined} />
            )}
        </FormField>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={r('description')}
            actions={canManage && !adding ? <Button type="button" onClick={() => setAdding(true)}>{r('add')}</Button> : undefined} />}>
            <Head title={title} />

            <div className="space-y-4">
                {adding && (
                    <Card>
                        <form onSubmit={open} className="space-y-5" encType="multipart/form-data">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                {field('first_name')}
                                {field('middle_name')}
                                {field('last_name')}
                            </div>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <FormField label={r('gender')} required error={form.errors.gender}>
                                    {({ id, describedBy, invalid }) => (
                                        <Select id={id} value={form.data.gender} onChange={(event) => form.setData('gender', event.target.value)} required aria-describedby={describedBy} aria-invalid={invalid || undefined}>
                                            <option value="">—</option>
                                            <option value="male">{r('male')}</option>
                                            <option value="female">{r('female')}</option>
                                        </Select>
                                    )}
                                </FormField>
                                {field('email', 'email')}
                                {field('phone', 'tel')}
                            </div>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                {field('national_id_number')}
                                {field('license_number')}
                                <FormField label={r('license_document')} error={form.errors.license_document}>
                                    {({ id, describedBy }) => (
                                        <input id={id} type="file" accept="application/pdf" aria-describedby={describedBy} onChange={(event) => form.setData('license_document', event.target.files?.[0] ?? null)}
                                            className="block w-full text-sm text-(--app-muted-foreground) file:mr-3 file:rounded-control file:border-0 file:bg-(--app-surface-muted) file:px-3 file:py-2 file:text-sm file:font-medium" />
                                    )}
                                </FormField>
                            </div>
                            {field('address')}
                            <p className="text-xs text-(--app-muted-foreground)">{r('help')}</p>
                            <div className="flex flex-wrap gap-3 border-t border-(--app-border) pt-4">
                                <Button type="submit" disabled={form.processing}>{r('save')}</Button>
                                <Button type="button" variant="ghost" onClick={() => { form.reset(); form.clearErrors(); setAdding(false); }}>{r('cancel')}</Button>
                            </div>
                        </form>
                    </Card>
                )}

                <FilterPanel onSubmit={filter} onReset={() => router.get(route('court.lawyers.index'))} hasActiveFilters={Boolean(search)} label={title}
                    submitLabel={t('cm.applicants.filters.filter')} resetLabel={t('cm.applicants.filters.reset')}>
                    <FilterField label={t('cm.app.Search')} className="sm:col-span-2">
                        <Input value={q} onChange={(event) => setQ(event.target.value)} placeholder={r('search')} />
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {['name', 'license', 'contact', 'status', 'cases', 'actions'].map((key) => (
                                        <th key={key} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{r(key)}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {lawyers.data.map((lawyer) => (
                                    <tr key={lawyer.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>
                                            <Link href={route('court.lawyers.show', lawyer.id)} className="hover:text-(--app-primary) hover:underline">{lawyer.name}</Link>
                                        </td>
                                        <td className={tableCellClassName}>
                                            <div className="font-mono text-xs">{lawyer.license_number ?? '—'}</div>
                                            {lawyer.verified_at && <div className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={lawyer.verified_at} /></div>}
                                            {lawyer.has_document && (
                                                <a href={route('court.lawyers.license', lawyer.id)} target="_blank" rel="noopener noreferrer" className="text-xs font-medium text-(--app-primary) hover:underline">{r('view_license')}</a>
                                            )}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                            <div>{lawyer.email}</div>
                                            <div className="text-xs">{lawyer.phone}</div>
                                        </td>
                                        <td className={tableCellClassName}>
                                            <div className="flex flex-wrap gap-1.5">
                                                {lawyer.refused
                                                    ? <span className={badgeClassName('danger')}>{r('refused')}</span>
                                                    : <span className={badgeClassName(lawyer.verified ? 'success' : 'warning')}>{lawyer.verified ? r('verified') : waiting(lawyer) ? r('waiting') : r('unverified')}</span>}
                                                {!lawyer.active && !lawyer.refused && <span className={badgeClassName('neutral')}>{r('inactive')}</span>}
                                            </div>
                                            {lawyer.applied_at && !lawyer.verified && (
                                                <div className="mt-1 text-xs text-(--app-muted-foreground)">{r('applied')}: <LocalizedDateDisplay value={lawyer.applied_at} /></div>
                                            )}
                                            {lawyer.refused && lawyer.refusal_reason && <p className="mt-1 max-w-xs text-xs text-(--app-muted-foreground)">{lawyer.refusal_reason}</p>}
                                        </td>
                                        <td className={tableCellClassName}>{lawyer.cases}</td>
                                        <td className={tableCellClassName}>
                                            {/* Verifying or refusing is done on the lawyer's page, with the details and the licence in view. */}
                                            <Link href={route('court.lawyers.show', lawyer.id)}
                                                className={buttonClassName({ variant: canManage && waiting(lawyer) ? 'primary' : 'outline', size: 'sm' })}>
                                                {canManage && waiting(lawyer) ? r('review') : r('details')}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {lawyers.data.length === 0 && <EmptyState title={r('none')} />}
                    <ListPagination meta={lawyers} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
