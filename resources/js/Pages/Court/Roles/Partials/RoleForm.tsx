import { Link, useForm } from '@inertiajs/react';
import { useMemo, type FormEvent } from 'react';
import { Button, Card, FormField, Input, Textarea, badgeClassName, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { choice, fill, useAccessForms } from '@/court/i18n/useAccessForms';
import type { PermissionGroup, RoleFormRole } from '@/court/types/access';

interface RoleFormData {
    name: string;
    description: string;
    permissions: number[];
}

type Props =
    | { mode: 'create'; permissionsCount: number; permissionGroups: PermissionGroup[]; role?: undefined }
    | { mode: 'edit'; permissionsCount: number; permissionGroups: PermissionGroup[]; role: RoleFormRole };

/**
 * A role's name, description and permissions, the permissions grouped by module
 * with a select-all toggle; posts to roles.store or roles.update.
 */
export default function RoleForm(props: Props) {
    const { t } = useLocale();
    const access = useAccessForms();
    const { mode, permissionsCount, permissionGroups } = props;
    const role = mode === 'edit' ? props.role : null;
    const k = mode === 'create' ? 'cm.roles.create' : 'cm.roles.edit';

    const form = useForm<RoleFormData>({
        name: role?.name ?? '',
        description: role?.description ?? '',
        permissions: role?.permission_ids ?? [],
    });

    const allIds = useMemo(() => permissionGroups.flatMap((group) => group.permissions.map((perm) => perm.id)), [permissionGroups]);
    const allChecked = allIds.length > 0 && allIds.every((id) => form.data.permissions.includes(id));

    const toggle = (id: number, checked: boolean) => {
        form.setData('permissions', checked ? [...form.data.permissions.filter((p) => p !== id), id] : form.data.permissions.filter((p) => p !== id));
    };
    const toggleAll = () => form.setData('permissions', allChecked ? [] : allIds);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, description: data.description === '' ? null : data.description }));
        if (mode === 'create') form.post(route('court.roles.store'));
        else form.patch(route('court.roles.update', role!.id));
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div className="space-y-6">
                <Card className="space-y-3">
                    <FormField id="role-name" label={mode === 'create' ? t('cm.roles.fields.name_unique') : t('cm.roles.fields.name')} error={form.errors.name} description={t(`${k}.name_hint`)}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} value={form.data.name} aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(event) => form.setData('name', event.target.value)} />
                        )}
                    </FormField>
                    <FormField id="role-description" label={t('cm.roles.fields.description')} error={form.errors.description} description={t(`${k}.description_hint`)}>
                        {({ id, describedBy, invalid }) => (
                            <Textarea id={id} rows={3} value={form.data.description} aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(event) => form.setData('description', event.target.value)} />
                        )}
                    </FormField>
                </Card>

                <Card className="space-y-3 bg-(--app-surface-muted)">
                    <h3 className="text-sm font-semibold text-(--app-foreground)">{t(`${k}.summary_title`)}</h3>
                    <ul className="space-y-2 text-sm text-(--app-foreground)">
                        <li className="flex items-center gap-2">
                            <span className="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true" />
                            {fill(t(`${k}.summary_permissions`), { count: permissionsCount })}
                        </li>
                        <li className="flex items-center gap-2">
                            <span className="h-2 w-2 rounded-full bg-blue-500" aria-hidden="true" />
                            {mode === 'create' ? t('cm.roles.create.summary_roles_total') : fill(t('cm.roles.edit.summary_assigned'), { count: role!.users_count })}
                        </li>
                    </ul>
                </Card>
            </div>

            <Card className="space-y-4 lg:col-span-2">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h3 className="text-sm font-semibold text-(--app-foreground)">{t('cm.roles.fields.permissions')}</h3>
                        <p className="text-xs text-(--app-muted-foreground)">{t(`${k}.permissions_hint`)}</p>
                    </div>
                    <Button type="button" variant="outline" size="xs" onClick={toggleAll} disabled={allIds.length === 0}>
                        {allChecked ? t(`${k}.deselect_all`) : t(`${k}.select_all`)}
                    </Button>
                </div>

                <div className="max-h-[28rem] space-y-5 overflow-auto pr-2">
                    {permissionGroups.length === 0 && <p className="text-sm text-(--app-muted-foreground)">{access('noPermissionsDefined')}</p>}
                    {permissionGroups.map((group) => (
                        <div key={group.key} className="rounded-card border border-(--app-border) bg-(--app-surface-muted) p-4">
                            <div className="mb-3 flex items-center justify-between gap-3">
                                <div>
                                    <p className="text-sm font-semibold text-(--app-foreground)">{group.label}</p>
                                    <p className="text-xs text-(--app-muted-foreground)">{choice(t('cm.roles.groups.perms_count'), group.permissions.length)}</p>
                                </div>
                                <span className={badgeClassName('success')}>{group.permissions.length}</span>
                            </div>
                            <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                                {group.permissions.map((perm) => (
                                    <label key={perm.id} className="flex items-start gap-3 rounded-control border border-(--app-border) bg-(--app-surface) px-3 py-2 hover:bg-(--app-surface-muted)">
                                        <input type="checkbox" className="mt-0.5 h-4 w-4 rounded border-(--app-border) text-primary focus:ring-primary"
                                            checked={form.data.permissions.includes(perm.id)} onChange={(event) => toggle(perm.id, event.target.checked)} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-sm font-medium text-(--app-foreground)">{perm.name}</span>
                                            {perm.label && <span className="block text-xs text-(--app-muted-foreground)">{perm.label}</span>}
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
                <p className="text-xs text-(--app-muted-foreground)">{access('selectedCount', { count: form.data.permissions.length })}</p>
                {form.errors.permissions && <p role="alert" className="text-xs font-medium text-red-600">{form.errors.permissions}</p>}

                <div className="flex items-center justify-end gap-2 pt-2">
                    <Link href={route('court.roles.index')} className={buttonClassName({ variant: 'outline' })}>{t(`${k}.cancel`)}</Link>
                    <Button type="submit" variant="primary" loading={form.processing}>{mode === 'create' ? t('cm.roles.create.submit') : t('cm.roles.edit.save')}</Button>
                </div>
            </Card>
        </form>
    );
}
