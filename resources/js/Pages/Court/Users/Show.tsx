import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import UserAvatar from '@/Components/UserAvatar';
import { Card, CardTitle, badgeClassName, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { UsersShowProps } from '@/court/types/people';
import EuisisStaffCard from '@/court/components/EuisisStaffCard';

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-[0.16em] text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-1 font-medium text-(--app-foreground)">{children}</dd>
        </div>
    );
}

/** A staff profile: who they are, when the account was made, their signature, stamp and roles, and their EUISIS employee. */
export default function UsersShow({ user, can, euisis }: UsersShowProps) {
    const { t } = useLocale();
    const title = t('cm.users.profile_title');
    const active = user.status === 'active';

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('court.users.index')} />}>
            <Head title={title} />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="flex flex-col items-center text-center">
                    <UserAvatar src={user.avatar_url} name={user.name} size={112} className="border-4 border-(--app-surface) shadow" />
                    <h2 className="mt-5 text-xl font-semibold tracking-tight text-(--app-foreground)">{user.name}</h2>
                    <p className="mt-1 text-sm text-(--app-muted-foreground)">{user.email}</p>
                    <span className={badgeClassName(active ? 'success' : 'neutral', 'mt-4')}>{active ? t('cm.users.active') : t('cm.users.inactive')}</span>
                    <div className="mt-6 flex w-full flex-wrap justify-center gap-2 border-t border-(--app-border) pt-4">
                        {can.update && <Link href={route('court.users.edit', user.id)} className={buttonClassName({ variant: 'primary' })}>{t('cm.users.edit_profile')}</Link>}
                        <Link href={route('court.users.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.users.back')}</Link>
                    </div>
                </Card>

                <Card className="lg:col-span-2">
                    <CardTitle className="mb-4 border-b border-(--app-border) pb-3 text-lg">{t('cm.users.details')}</CardTitle>
                    <dl className="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div className="space-y-4">
                            <Field label={t('cm.users.first_name')}>{user.first_name || '-'}</Field>
                            <Field label={t('cm.users.middle_name')}>{user.middle_name || '-'}</Field>
                            <Field label={t('cm.users.last_name')}>{user.last_name || '-'}</Field>
                            <Field label={t('cm.users.full_name')}>{user.name}</Field>
                            <Field label={t('cm.users.email_address')}>{user.email}</Field>
                        </div>
                        <div className="space-y-4">
                            <Field label={t('cm.users.created_at')}><LocalizedDateDisplay value={user.created_at} withTime /></Field>
                            <Field label={t('cm.users.updated_at')}><LocalizedDateDisplay value={user.updated_at} withTime /></Field>
                        </div>

                        <div className="grid gap-4 md:col-span-2 md:grid-cols-2">
                            <div className="rounded-card border border-(--app-border) bg-(--app-surface-muted) p-4">
                                <dt className="mb-2 text-xs uppercase tracking-[0.16em] text-(--app-muted-foreground)">{t('cm.users.signature')}</dt>
                                <dd>
                                    {user.signature_url
                                        ? <img src={user.signature_url} alt={t('cm.users.signature')} className="mx-auto max-h-20 object-contain" />
                                        : <p className="text-sm italic text-(--app-muted-foreground)">{t('cm.users.no_signature')}</p>}
                                </dd>
                            </div>
                            <div className="rounded-card border border-(--app-border) bg-(--app-surface-muted) p-4">
                                <dt className="mb-2 text-xs uppercase tracking-[0.16em] text-(--app-muted-foreground)">{t('cm.users.stamp')}</dt>
                                <dd>
                                    {user.stamp_url
                                        ? <img src={user.stamp_url} alt={t('cm.users.stamp')} className="mx-auto max-h-20 object-contain" />
                                        : <p className="text-sm italic text-(--app-muted-foreground)">{t('cm.users.no_stamp')}</p>}
                                </dd>
                            </div>
                        </div>

                        <div className="border-t border-(--app-border) pt-4 md:col-span-2">
                            <dt className="mb-2 text-xs uppercase tracking-[0.16em] text-(--app-muted-foreground)">{t('cm.users.assigned_roles')}</dt>
                            <dd className="flex flex-wrap gap-2">
                                {user.roles.length === 0
                                    ? <span className="text-sm text-(--app-muted-foreground)">{t('cm.users.no_roles_assigned')}</span>
                                    : user.roles.map((role) => <span key={role} className={badgeClassName('info')}>{role}</span>)}
                            </dd>
                        </div>
                    </dl>
                </Card>

                <EuisisStaffCard userId={user.id} euisis={euisis} canLink={can.linkEuisis} />
            </div>
        </AuthenticatedLayout>
    );
}
