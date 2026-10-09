import { ReactNode } from 'react';
import { useCan } from '@/hooks/useCan';

interface Props {
    permission: string;
    fallback?: ReactNode;
    children: ReactNode;
}

export default function Can({ permission, fallback = null, children }: Props) {
    const { can } = useCan();
    return can(permission) ? <>{children}</> : <>{fallback}</>;
}
