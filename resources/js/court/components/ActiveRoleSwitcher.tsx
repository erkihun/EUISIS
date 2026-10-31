import { router, usePage } from '@inertiajs/react';
import { useId, useState } from 'react';
import { Select } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';

type RoleOption = { id: number; label: string };
type AuthWithRoles = { activeRoleId?: number | null; roleOptions?: RoleOption[] };

/**
 * The active role of a person who holds several (court-ms ResolveAdminRole):
 * the permissions in force are the chosen role's alone. Sits in the header's
 * account menu, as on the Blade layout; hidden with one role.
 */
export default function ActiveRoleSwitcher() {
    const { t } = useLocale();
    const { auth } = usePage<PageProps<{ auth: AuthWithRoles }>>().props;
    const id = useId();
    const [busy, setBusy] = useState(false);
    const options = auth.roleOptions ?? [];

    if (options.length < 2) return null;

    return (
        <div className="mt-3 border-t border-(--app-border) pt-3">
            <label htmlFor={id} className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.app.active_role')}</label>
            <Select
                id={id}
                value={auth.activeRoleId ?? ''}
                disabled={busy}
                onChange={(event) => {
                    setBusy(true);
                    router.post(route('court.admin.roles.switch'), { role_id: Number(event.target.value) }, { onFinish: () => setBusy(false) });
                }}
            >
                {options.map((role) => <option key={role.id} value={role.id}>{role.label}</option>)}
            </Select>
            <p className="mt-1.5 text-[11px] leading-4 text-(--app-muted-foreground)">{t('cm.app.role_switch_help')}</p>
        </div>
    );
}
