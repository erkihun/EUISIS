import { useState, type JSX } from 'react';
import { communicationApi } from '@/lib/communication/api';
import { initials } from '@/lib/communication/format';
import { HashIcon, Users } from '@/Components/NavIcons';
import type { ConversationType } from '@/types/communication';

type Size = 'sm' | 'md' | 'lg' | 'xl';

type Props = {
    name: string | null;
    userId?: number | null;
    hasPhoto?: boolean;
    kind?: ConversationType;
    online?: boolean | null;
    size?: Size;
};

/** 32, 40, 44 and 72 px: message rows, chat header, conversation list, details panel. */
const SIZES: Record<Size, string> = { sm: 'h-8 w-8 text-[11px]', md: 'h-10 w-10 text-[13px]', lg: 'h-11 w-11 text-sm', xl: 'h-18 w-18 text-[22px]' };
const CHANNEL_RADIUS: Record<Size, string> = { sm: 'rounded-[9px]', md: 'rounded-[11px]', lg: 'rounded-xl', xl: 'rounded-[18px]' };
const DOTS: Record<Size, string> = {
    sm: '-bottom-px -inset-e-px h-2.5 w-2.5 ring-2',
    md: '-bottom-px -inset-e-px h-3 w-3 ring-2',
    lg: '-bottom-px -inset-e-px h-[13px] w-[13px] ring-[2.5px]',
    xl: 'bottom-0.5 inset-e-0.5 h-4 w-4 ring-[3px]',
};

/** One of five soft tones, fixed per person, so a colleague looks the same in every list. */
export function toneClass(userId: number | null | undefined): string {
    return `comm-tone-${Math.abs(userId ?? 0) % 5}`;
}

/**
 * A person's photo (served only to people who may see it) or initials on their
 * tone; a group or channel shows its kind (a channel on a rounded square). The
 * online dot is shown only when presence is allowed by the system and by that
 * person (null hides it).
 */
export default function Avatar({ name, userId, hasPhoto = false, kind = 'DIRECT', online = null, size = 'md' }: Props): JSX.Element {
    const [failed, setFailed] = useState(false);
    const shape = kind === 'CHANNEL' ? CHANNEL_RADIUS[size] : 'rounded-full';
    const box = `relative inline-flex shrink-0 select-none items-center justify-center font-semibold ${shape} ${SIZES[size]}`;

    const inner = kind === 'CHANNEL'
        ? <HashIcon className="h-1/2 w-1/2" aria-hidden="true" />
        : kind === 'GROUP'
            ? <Users className="h-1/2 w-1/2" aria-hidden="true" />
            : hasPhoto && userId && !failed
                ? <img src={communicationApi.photoUrl(userId)} alt="" loading="lazy" onError={() => setFailed(true)} className="h-full w-full rounded-full object-cover" />
                : <span aria-hidden="true">{initials(name)}</span>;

    return (
        <span className={`${box} ${kind === 'DIRECT' ? `${toneClass(userId)} bg-(--comm-tone-bg) text-(--comm-tone-fg)` : 'bg-(--app-surface-muted) text-(--app-muted-foreground)'}`}>
            {inner}
            {online !== null && (
                <span
                    aria-hidden="true"
                    className={`absolute rounded-full ring-(--app-surface) ${DOTS[size]} ${online ? 'bg-emerald-500' : 'bg-(--app-border-strong)'}`}
                />
            )}
        </span>
    );
}
