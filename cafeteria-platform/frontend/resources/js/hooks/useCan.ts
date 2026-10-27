import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

type AuthExtra = { permissions: string[]; roles: string[]; isSuperAdmin: boolean };

export function useCan() {
    const { auth } = usePage<PageProps<{ auth: AuthExtra }>>().props;

    const can = (permission: string): boolean => {
        if (auth.isSuperAdmin) return true;
        return auth.permissions.includes(permission);
    };

    const hasRole = (role: string): boolean => auth.roles.includes(role);

    const isSuperAdmin = auth.isSuperAdmin;

    return { can, hasRole, isSuperAdmin };
}
