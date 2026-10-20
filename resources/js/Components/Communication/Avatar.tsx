import { useState, type JSX } from 'react';
import { communicationApi } from '@/lib/communication/api';
import { initials } from '@/lib/communication/format';
import { HashIcon, Users } from '@/Components/NavIcons';
import type { ConversationType } from '@/types/communication';

type Props = {
    name: string | null;
    userId?: number | null;
    hasPhoto?: boolean;
    kind?: ConversationType;
    online?: boolean | null;
    size?: 'sm' | 'md' | 'lg';
};

const SIZES = { sm: 'h-8 w-8 text-[11px]', md: 'h-10 w-10 text-xs', lg: 'h-14 w-14 text-base' };

/**
 * A person's photo (served only to people who may see it) or initials; a group
 * or channel shows its kind. The online dot is shown only when presence is
 * allowed by the system and by that person (null hides it).
 */
export default function Avatar({ name, userId, hasPhoto = false, kind = 'DIRECT', online = null, size = 'md' }: Props): JSX.Element {
    const [failed, setFailed] = useState(false);
    const box = `relative inline-flex shrink-0 select-none items-center justify-center rounded-full font-semibold ${SIZES[size]}`;

    const inner = kind === 'CHANNEL'
        ? <HashIcon className="h-1/2 w-1/2" aria-hidden="true" />
        : kind === 'GROUP'
            ? <Users className="h-1/2 w-1/2" aria-hidden="true" />
            : hasPhoto && userId && !failed
                ? <img src={communicationApi.photoUrl(userId)} alt="" loading="lazy" onError={() => setFailed(true)} className="h-full w-full rounded-full object-cover" />
                : <span aria-hidden="true">{initials(name)}</span>;

    return (
        <span className={`${box} ${kind === 'DIRECT' ? 'bg-[color-mix(in_srgb,var(--color-primary)_12%,var(--app-surface))] text-(--app-foreground)' : 'bg-(--app-surface-muted) text-(--app-muted-foreground)'}`}>
            {inner}
            {online !== null && (
                <span
                    aria-hidden="true"
                    className={`absolute -bottom-0.5 -inset-e-0.5 h-3 w-3 rounded-full ring-2 ring-(--app-surface) ${online ? 'bg-emerald-500' : 'bg-(--app-border-strong)'}`}
                />
            )}
        </span>
    );
}
