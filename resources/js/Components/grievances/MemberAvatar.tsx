import { useState } from 'react';

/**
 * A committee or Team/Directorate member's photo, with the role shown as a
 * coloured ring. Falls back to initials when there is no photo or it fails to load.
 */
const RING: Record<string, string> = {
    lead: 'ring-primary',
    second: 'ring-amber-500',
    member: 'ring-(--app-border-strong)',
};
const FILL: Record<string, string> = {
    lead: 'bg-primary text-white',
    second: 'bg-amber-500 text-white dark:bg-amber-600',
    member: 'bg-(--app-surface-muted) text-(--app-foreground)',
};

function initials(name: string): string {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => Array.from(part)[0] ?? '').join('');
}

export default function MemberAvatar({ name, photoUrl, tier = 'member', size = 'h-12 w-12' }: { name: string; photoUrl?: string | null; tier?: 'lead' | 'second' | 'member'; size?: string }) {
    const [failed, setFailed] = useState(false);
    const ring = `ring-2 ring-offset-2 ring-offset-(--app-surface) ${RING[tier]}`;
    if (photoUrl && !failed) {
        return <img src={photoUrl} alt="" aria-hidden loading="lazy" decoding="async" onError={() => setFailed(true)}
            className={`${size} shrink-0 rounded-full object-cover ${ring}`} />;
    }
    return <span className={`flex ${size} shrink-0 items-center justify-center rounded-full text-sm font-semibold ${FILL[tier]} ${ring}`} aria-hidden>{initials(name)}</span>;
}
