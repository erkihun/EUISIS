import { useState } from 'react';

interface Props {
    src?: string | null;
    name?: string;
    size?: number;
    className?: string;
}

export default function UserAvatar({ src, name = 'U', size = 32, className = '' }: Props) {
    const [imgError, setImgError] = useState(false);

    const initials = name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0].toUpperCase())
        .join('');

    const style = { width: size, height: size, fontSize: Math.max(10, Math.round(size * 0.4)) };

    if (src && !imgError) {
        return (
            <img
                src={src}
                alt={name}
                style={style}
                className={`rounded-full object-cover ${className}`}
                onError={() => setImgError(true)}
            />
        );
    }

    return (
        <span
            aria-label={name}
            style={style}
            className={`inline-flex shrink-0 items-center justify-center rounded-full bg-[color:var(--color-primary)] font-semibold text-white ${className}`}
        >
            {initials || '?'}
        </span>
    );
}
