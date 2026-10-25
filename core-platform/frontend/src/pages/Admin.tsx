import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { api } from '../api';
import { useAuth } from '../auth';
import FormDialog, { type FieldValue } from '../components/FormDialog';
import { KeyIcon, UserCircleIcon, UserPlusIcon } from '../components/NavIcons';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useLocale } from '../i18n';
import AuthenticatedLayout from '../layouts/AuthenticatedLayout';
import { useList } from '../lib/useList';
import { nameOptions, useLookups } from '../lib/useLookups';
import { Link } from '../router';
import { Alert, AppDialog, Button, Card, EmptyState, FilterField, FilterPanel, Input, Table, TBody, THead, Td, Th, Tr, actionLinkClassName } from '../ui';
import { ListFrame, useFilterForm } from './Lists';

type UserRow = { id: number; name: string; email: string; role: string; scope_organization_id: string | null; is_active: boolean; scope_organization_name: string | null };

const ROLES = ['core_viewer', 'core_officer', 'core_admin'];

/** Users: Core accounts, their role, organization scope and whether they may sign in (administrators only). */
export function UsersPage() {
    const { t, locale } = useLocale();
    const { user: me } = useAuth();
    const list = useList<UserRow>('/v1/users');
    const { lookups } = useLookups();
    const { form, setForm, set } = useFilterForm({ search: '' });
    const [dialog, setDialog] = useState<{ row: UserRow | null } | null>(null);
    const [password, setPassword] = useState<{ email: string; value: string } | null>(null);
    const hasFilters = Boolean(list.filters.search);

    const fields = [
        { name: 'name', label: t('platform.users.name'), required: true },
        { name: 'email', label: t('platform.users.email'), required: true },
        { name: 'role', label: t('platform.users.role'), type: 'select' as const, required: true, emptyOption: null, options: ROLES.map((role) => ({ value: role, label: t(`platform.roles.${role}`) })) },
        { name: 'scope_organization_id', label: t('platform.users.scope'), type: 'select' as const, emptyOption: t('platform.wholeCity'), options: nameOptions(locale, lookups.organizations) },
        ...(dialog?.row ? [{ name: 'is_active', label: t('platform.users.active'), type: 'checkbox' as const }] : []),
    ];

    const reset = async (row: UserRow) => {
        try {
            const response = await api<{ one_time_password: string }>(`/v1/users/${row.id}/reset-password`, { method: 'POST' });
            setPassword({ email: row.email, value: response.one_time_password });
        } catch {
            toast.error(t('platform.error'));
        }
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.users.title')} description={t('platform.users.description')}
            actions={<Button variant="primary" icon={<UserPlusIcon className="h-4 w-4" />} onClick={() => setDialog({ row: null })}>{t('platform.users.newUser')}</Button>} />}>
            <div className="space-y-4">
                <FilterPanel label={t('platform.users.title')} onSubmit={() => list.apply(form)} onReset={() => { setForm({ search: '' }); list.apply({}); }} hasActiveFilters={hasFilters}>
                    <FilterField label={t('platform.filters.search')} htmlFor="user-search" wide>
                        <Input id="user-search" type="search" value={form.search} onChange={set('search')} />
                    </FilterField>
                </FilterPanel>
                <ListFrame {...list} onPage={list.setPage} hasFilters={hasFilters} icon={UserCircleIcon} empty={t('platform.users.empty')} noMatch={t('platform.users.empty')}>
                    <Table>
                        <THead><Tr><Th>{t('platform.users.name')}</Th><Th>{t('platform.users.role')}</Th><Th>{t('platform.users.scope')}</Th><Th>{t('common.status')}</Th><Th align="right">{t('common.actions')}</Th></Tr></THead>
                        <TBody>
                            {list.result?.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td>
                                        <span className="font-medium">{row.name}</span>
                                        <div className="text-xs text-(--app-muted-foreground)">{row.email}</div>
                                    </Td>
                                    <Td>{t(`platform.roles.${row.role}`)}</Td>
                                    <Td muted>{row.scope_organization_name ?? t('platform.wholeCity')}</Td>
                                    <Td><StatusBadge status={row.is_active ? 'active' : 'inactive'} /></Td>
                                    <Td align="right">
                                        <span className="inline-flex flex-wrap justify-end gap-x-3 gap-y-1">
                                            <button type="button" className={actionLinkClassName()} onClick={() => setDialog({ row })}>{t('platform.actions.edit')}</button>
                                            {row.id !== me?.id && <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => void reset(row)}>{t('platform.users.resetPassword')}</button>}
                                        </span>
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </ListFrame>
            </div>

            <FormDialog
                open={dialog !== null}
                title={dialog?.row ? t('platform.users.editUser') : t('platform.users.newUser')}
                fields={fields}
                initial={(dialog?.row
                    ? { name: dialog.row.name, email: dialog.row.email, role: dialog.row.role, scope_organization_id: dialog.row.scope_organization_id, is_active: dialog.row.is_active }
                    : { name: null, email: null, role: 'core_viewer', scope_organization_id: null }) as Record<string, FieldValue>}
                submitLabel={t('platform.actions.save')}
                onClose={() => setDialog(null)}
                onSubmit={async (values) => {
                    const response = await api<{ one_time_password: string | null }>(dialog?.row ? `/v1/users/${dialog.row.id}` : '/v1/users', { method: dialog?.row ? 'PUT' : 'POST', body: values });
                    toast.success(dialog?.row ? t('platform.users.saved') : t('platform.users.created'));
                    if (response.one_time_password) setPassword({ email: String(values.email), value: response.one_time_password });
                    setDialog(null);
                    list.reload();
                }}
            />

            <AppDialog
                open={password !== null}
                onClose={() => setPassword(null)}
                title={t('platform.users.passwordTitle')}
                description={t('platform.users.passwordHelp')}
                footer={<Button variant="primary" onClick={() => setPassword(null)}>{t('common.confirm')}</Button>}
            >
                <div className="flex items-center gap-3 rounded-card border border-(--app-border) bg-(--app-surface-muted) p-4">
                    <KeyIcon className="h-5 w-5 shrink-0 text-primary" aria-hidden="true" />
                    <div className="min-w-0">
                        <p className="text-xs text-(--app-muted-foreground)">{password?.email}</p>
                        <p className="select-all break-all font-mono text-base font-semibold">{password?.value}</p>
                    </div>
                </div>
            </AppDialog>
        </AuthenticatedLayout>
    );
}

type Check = { key: string; kind: 'position' | 'employee' | 'card'; count: number; records: { id: string; employee_id: string | null }[] };

/** EUISIS Pages/Core/DataQuality.tsx: the Golden Path integrity checks with a link to each finding. */
export function DataQualityPage() {
    const { t } = useLocale();
    const [checks, setChecks] = useState<Check[] | null>(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        api<{ checks: Check[] }>('/v1/data-quality').then((response) => setChecks(response.checks)).catch(() => setError(true));
    }, []);

    const href = (check: Check, record: Check['records'][number]) => {
        if (check.kind === 'position') return `/positions/${record.id}`;
        if (check.kind === 'employee') return `/employees/${record.id}`;
        return record.employee_id ? `/employees/${record.employee_id}` : '/id-cards';
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={t('core.dataQuality.title')} description={t('core.dataQuality.description')} />}>
            {error && <Alert tone="danger">{t('platform.error')}</Alert>}
            {!checks && !error && <Card><EmptyState title={t('common.loading')} /></Card>}
            <div className="grid gap-4 lg:grid-cols-2">
                {checks?.map((check) => (
                    <Card key={check.key} className="flex flex-col gap-3">
                        <div className="flex items-start justify-between gap-3">
                            <h2 className="text-sm font-semibold text-(--app-foreground)">{t(`core.dataQuality.checks.${check.key}`)}</h2>
                            <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold tabular-nums ${check.count === 0
                                ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'}`}>
                                {check.count === 0 ? t('core.dataQuality.clean') : t('core.dataQuality.findings', { count: check.count })}
                            </span>
                        </div>
                        {check.records.length > 0 && (
                            <>
                                <ul className="divide-y divide-(--app-border) text-sm">
                                    {check.records.map((record) => (
                                        <li key={record.id} className="flex items-center justify-between gap-3 py-1.5">
                                            <span className="min-w-0 truncate">
                                                <span className="text-(--app-muted-foreground)">{t(`core.dataQuality.kinds.${check.kind}`)} · </span>
                                                <span className="font-mono text-xs text-(--app-foreground)">{record.id}</span>
                                            </span>
                                            <Link href={href(check, record)} className="shrink-0 text-xs font-medium text-primary hover:underline">{t('core.dataQuality.open')}</Link>
                                        </li>
                                    ))}
                                </ul>
                                {check.count > check.records.length && <p className="text-xs text-(--app-muted-foreground)">{t('core.dataQuality.showing', { count: check.records.length })}</p>}
                            </>
                        )}
                    </Card>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
