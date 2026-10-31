import { Link, useForm } from '@inertiajs/react';
import { useEffect, useMemo, type FormEvent, type ReactNode } from 'react';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Alert, Button, Card, CardTitle, FieldError, FileUpload, FormField, Input, Select, buttonClassName, cx } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { useAccessForms } from '@/court/i18n/useAccessForms';
import type { EuisisPrefill, RoleOption, UserFormUser } from '@/court/types/people';

interface UserFormData {
    first_name: string;
    middle_name: string;
    last_name: string;
    email: string;
    status: 'active' | 'inactive';
    gender: '' | 'male' | 'female';
    /** Gregorian Y-m-d or ''. */
    date_of_birth: string;
    national_id: string;
    position: string;
    phone: string;
    address: string;
    roles: number[];
    avatar: File | null;
    signature: File | null;
    stamp: File | null;
    password: string;
    password_confirmation: string;
    remove_avatar: boolean;
    remove_signature: boolean;
    remove_stamp: boolean;
    /** Create only, from the EUISIS staff roster: the account is linked to this employee. */
    euisis_employee_number: string;
}

type Props =
    | { mode: 'create'; roles: RoleOption[]; user?: undefined; canManage?: undefined; euisisPrefill?: EuisisPrefill | null }
    | { mode: 'edit'; roles: RoleOption[]; user: UserFormUser; canManage: boolean; euisisPrefill?: undefined };

/** The national ID as typed: 16 letters or digits, upper-cased, in groups of four. */
export function formatNationalId(value: string): string {
    const cleaned = value.replace(/[^A-Za-z0-9]/g, '').slice(0, 16).toUpperCase();
    return cleaned.match(/.{1,4}/g)?.join(' ') ?? '';
}

/** A blob URL for the chosen file, released when the file changes. */
function usePreview(file: File | null): string | null {
    const url = useMemo(() => (file ? URL.createObjectURL(file) : null), [file]);
    useEffect(() => () => { if (url) URL.revokeObjectURL(url); }, [url]);
    return url;
}

/**
 * One image slot — avatar, signature or stamp: the current image or a preview of
 * the chosen file, the file input and, when an image is stored, the box that removes it.
 */
function ImageField({ label, current, file, onFile, error, removeChecked, onRemove, round = false, accept, alt, noImageText }: {
    label: string;
    current: string | null;
    file: File | null;
    onFile: (file: File | null) => void;
    error?: string;
    removeChecked?: boolean;
    onRemove?: (checked: boolean) => void;
    round?: boolean;
    accept: string;
    alt: string;
    noImageText: string;
}) {
    const access = useAccessForms();
    const preview = usePreview(file);
    const shown = preview ?? (removeChecked ? null : current);
    const frame = round
        ? 'grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-full border border-(--app-border) bg-(--app-surface-muted)'
        : 'grid min-h-16 place-items-center overflow-hidden rounded-control border border-(--app-border) bg-(--app-surface-muted) p-2';

    return (
        <div className="space-y-3 rounded-card border border-(--app-border) bg-(--app-surface-muted) p-4">
            <div className={round ? 'flex items-center gap-4' : 'space-y-3'}>
                <div className={frame}>
                    {shown
                        ? <img src={shown} alt={alt} className={round ? 'h-full w-full object-cover' : 'max-h-20 object-contain'} />
                        : <span className="px-2 text-center text-xs text-(--app-muted-foreground)">{noImageText}</span>}
                </div>
                <div className="min-w-0 flex-1">
                    <FileUpload label={label} value={file} accept={accept} maxSizeMb={2} error={error} onChange={onFile} onRemove={() => onFile(null)} />
                </div>
            </div>
            {current && onRemove && (
                <label className="flex items-center gap-2 text-sm text-(--app-foreground)">
                    <input type="checkbox" className="h-4 w-4 rounded border-(--app-border) text-primary focus:ring-primary" checked={Boolean(removeChecked)} onChange={(event) => onRemove(event.target.checked)} />
                    {access('removeCurrent')}
                </label>
            )}
        </div>
    );
}

/**
 * The account form behind the users register, shared by Create and Edit: identity,
 * contact details, status, role assignments and the avatar, signature and stamp
 * images (admin-only uploads). Edit also sets a new password. Files go up as
 * multipart; Inertia turns the PATCH into a POST with `_method` for them.
 * Someone editing only their own record (`canManage` false) gets the identity,
 * contact and avatar fields; the server ignores the rest from them anyway.
 */
export default function UserForm(props: Props) {
    const { t } = useLocale();
    const access = useAccessForms();
    const { mode, roles } = props;
    const user = props.mode === 'edit' ? props.user : null;
    const canManage = props.mode === 'create' || props.canManage;
    const prefill = props.euisisPrefill ?? null;

    const form = useForm<UserFormData>({
        first_name: user?.first_name ?? prefill?.first_name ?? '',
        middle_name: user?.middle_name ?? prefill?.middle_name ?? '',
        last_name: user?.last_name ?? prefill?.last_name ?? '',
        email: user?.email ?? '',
        status: user?.status === 'inactive' ? 'inactive' : 'active',
        gender: user?.gender === 'male' || user?.gender === 'female' ? user.gender : '',
        date_of_birth: user?.date_of_birth ?? '',
        national_id: user?.national_id ? formatNationalId(user.national_id) : '',
        position: user?.position ?? '',
        phone: user?.phone ?? '',
        address: user?.address ?? '',
        roles: user?.role_ids ?? [],
        avatar: null,
        signature: null,
        stamp: null,
        password: '',
        password_confirmation: '',
        remove_avatar: false,
        remove_signature: false,
        remove_stamp: false,
        euisis_employee_number: prefill?.employee_number ?? '',
    });
    const toggleRole = (id: number, checked: boolean) => {
        form.setData('roles', checked ? [...form.data.roles, id].filter((v, i, a) => a.indexOf(v) === i) : form.data.roles.filter((r) => r !== id));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (mode === 'create') {
            form.transform((data) => {
                const payload: Partial<UserFormData> = { ...data };
                delete payload.password;
                delete payload.password_confirmation;
                delete payload.remove_avatar;
                delete payload.remove_signature;
                delete payload.remove_stamp;
                if (!payload.euisis_employee_number) delete payload.euisis_employee_number;
                return payload;
            });
            form.post(route('court.users.store'), { forceFormData: true });
            return;
        }
        form.transform((data) => {
            const payload: Record<string, unknown> = {
                ...data,
                password: data.password === '' ? null : data.password,
                password_confirmation: data.password === '' ? null : data.password_confirmation,
                remove_avatar: data.remove_avatar ? 1 : 0,
                remove_signature: data.remove_signature ? 1 : 0,
                remove_stamp: data.remove_stamp ? 1 : 0,
            };
            delete payload.euisis_employee_number;
            if (!canManage) {
                for (const field of ['status', 'roles', 'password', 'password_confirmation', 'signature', 'stamp', 'remove_signature', 'remove_stamp']) {
                    delete payload[field];
                }
            }
            return payload as unknown as UserFormData;
        });
        form.patch(route('court.users.update', user!.id), { forceFormData: true });
    };

    const text = (name: keyof UserFormData, label: string, extra: Partial<{ type: string; description: ReactNode; placeholder: string; maxLength: number }> = {}) => (
        <FormField id={`user-${name}`} label={label} error={form.errors[name]} description={extra.description}>
            {({ id, describedBy, invalid }) => (
                <Input id={id} type={extra.type ?? 'text'} value={form.data[name] as string} placeholder={extra.placeholder} maxLength={extra.maxLength}
                    aria-describedby={describedBy} aria-invalid={invalid || undefined}
                    onChange={(event) => form.setData(name, event.target.value as never)} />
            )}
        </FormField>
    );

    return (
        <form onSubmit={submit} className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Card className="space-y-5 lg:col-span-2">
                {mode === 'create' && prefill && (
                    <FormField id="user-euisis_employee_number" label={t('cm.euisis_staff.create.employee_number')} error={form.errors.euisis_employee_number}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} value={form.data.euisis_employee_number} readOnly aria-describedby={describedBy} aria-invalid={invalid || undefined} />
                        )}
                    </FormField>
                )}

                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                    {text('first_name', t('cm.users.first_name'))}
                    {text('middle_name', t('cm.users.middle_name'))}
                    {text('last_name', t('cm.users.last_name'))}
                </div>

                {text('email', t('cm.users.email'), { type: 'email' })}

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <FormField id="user-gender" label={t('cm.users.gender')} error={form.errors.gender}>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} value={form.data.gender} aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(event) => form.setData('gender', event.target.value as UserFormData['gender'])}>
                                <option value="">{t('cm.users.select')}</option>
                                <option value="male">{t('cm.users.male')}</option>
                                <option value="female">{t('cm.users.female')}</option>
                            </Select>
                        )}
                    </FormField>
                    <FormField id="user-date_of_birth" label={t('cm.users.date_of_birth')} error={form.errors.date_of_birth}>
                        {({ id }) => <LocalizedDatePicker id={id} value={form.data.date_of_birth} onChange={(iso) => form.setData('date_of_birth', iso)} />}
                    </FormField>
                </div>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <FormField id="user-national_id" label={t('cm.users.national_id')} error={form.errors.national_id} description={t('cm.users.national_id_help')}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} value={form.data.national_id} placeholder="XXXX XXXX XXXX XXXX" maxLength={19} inputMode="text"
                                aria-describedby={describedBy} aria-invalid={invalid || undefined}
                                onChange={(event) => form.setData('national_id', formatNationalId(event.target.value))} />
                        )}
                    </FormField>
                    {text('position', t('cm.users.position'))}
                </div>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {text('phone', t('cm.users.phone'))}
                    {text('address', t('cm.users.address'))}
                </div>

                {/* store() sets a random password and emails a link to choose one. */}
                {mode === 'create' && <Alert tone="info">{t('cm.users.reset_link_notice')}</Alert>}

                {!canManage && <Alert tone="info">{t('cm.users.self_edit_notice')}</Alert>}

                {canManage && (
                    <FormField id="user-status" label={t('cm.users.status')} error={form.errors.status}>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} value={form.data.status} aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(event) => form.setData('status', event.target.value as UserFormData['status'])}>
                                <option value="active">{t('cm.users.active')}</option>
                                <option value="inactive">{t('cm.users.inactive')}</option>
                            </Select>
                        )}
                    </FormField>
                )}

                {mode === 'edit' && canManage && (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FormField id="user-password" label={t('cm.users.new_password_optional')} error={form.errors.password}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} type="password" autoComplete="new-password" value={form.data.password} aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(event) => form.setData('password', event.target.value)} />
                            )}
                        </FormField>
                        <FormField id="user-password_confirmation" label={t('cm.users.confirm_password')} error={form.errors.password_confirmation}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} type="password" autoComplete="new-password" value={form.data.password_confirmation} aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(event) => form.setData('password_confirmation', event.target.value)} />
                            )}
                        </FormField>
                    </div>
                )}
            </Card>

            <div className="space-y-6">
                {canManage && (
                    <Card className="space-y-4">
                        <CardTitle className="text-sm">{mode === 'create' ? t('cm.users.assign_roles') : t('cm.users.roles')}</CardTitle>
                        <div className="max-h-80 space-y-2 overflow-auto pr-1">
                            {roles.map((role) => {
                                const checked = form.data.roles.includes(role.id);
                                // The admin role is the administrators' to grant; one already held can still be removed.
                                const locked = Boolean(role.disabled) && !checked;
                                return (
                                    <label key={role.id} className={cx('flex items-center gap-3 rounded-control border border-(--app-border) px-3 py-2 text-sm text-(--app-foreground) hover:bg-(--app-surface-muted)', locked && 'cursor-not-allowed opacity-60')}
                                        title={locked ? t('cm.users.admin_role_admin_only') : undefined}>
                                        <input type="checkbox" className="h-4 w-4 rounded border-(--app-border) text-primary focus:ring-primary" checked={checked} disabled={locked} onChange={(event) => toggleRole(role.id, event.target.checked)} />
                                        <span>{role.name}</span>
                                    </label>
                                );
                            })}
                        </div>
                        <p className="text-xs text-(--app-muted-foreground)">{access('selectedCount', { count: form.data.roles.length })}</p>
                        <FieldError>{form.errors.roles}</FieldError>
                    </Card>
                )}

                <Card className="space-y-4">
                    <ImageField round label={t('cm.users.avatar')} accept="image/*" alt={t('cm.users.avatar_preview')} noImageText={t('cm.users.no_image')}
                        current={user?.avatar_url ?? null} file={form.data.avatar} onFile={(file) => form.setData('avatar', file)} error={form.errors.avatar}
                        removeChecked={form.data.remove_avatar} onRemove={mode === 'edit' ? (checked) => form.setData('remove_avatar', checked) : undefined} />
                    {canManage && (
                        <>
                            <ImageField label={t('cm.users.signature')} accept="image/png,image/webp,image/jpeg" alt={t('cm.users.signature_preview')} noImageText={t('cm.users.no_signature')}
                                current={user?.signature_url ?? null} file={form.data.signature} onFile={(file) => form.setData('signature', file)} error={form.errors.signature}
                                removeChecked={form.data.remove_signature} onRemove={mode === 'edit' ? (checked) => form.setData('remove_signature', checked) : undefined} />
                            <ImageField label={t('cm.users.stamp')} accept="image/png,image/webp,image/jpeg" alt={t('cm.users.stamp_preview')} noImageText={t('cm.users.no_stamp')}
                                current={user?.stamp_url ?? null} file={form.data.stamp} onFile={(file) => form.setData('stamp', file)} error={form.errors.stamp}
                                removeChecked={form.data.remove_stamp} onRemove={mode === 'edit' ? (checked) => form.setData('remove_stamp', checked) : undefined} />
                        </>
                    )}
                </Card>

                <div className="flex flex-wrap items-center justify-end gap-2">
                    <Link href={canManage || !user ? route('court.users.index') : route('court.users.show', user.id)} className={buttonClassName({ variant: 'outline' })}>{t('cm.users.cancel')}</Link>
                    <Button type="submit" variant="primary" loading={form.processing}>{mode === 'create' ? t('cm.users.create_user') : t('cm.users.save')}</Button>
                </div>
            </div>
        </form>
    );
}
