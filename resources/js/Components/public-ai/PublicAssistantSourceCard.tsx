import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { isAppHref } from './shared';

/** One cited public document or FAQ: "Source: title", a link only when the server gave a safe one. */
export default function PublicAssistantSourceCard({ title, href }: { title: string; href?: string | null }) {
    const { t } = useLocale();

    return (
        <span className="text-xs text-(--app-muted-foreground)">
            {t('publicAssistant.blocks.source')}:{' '}
            {isAppHref(href) ? (
                <Link href={href} className="font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary">
                    {title}
                </Link>
            ) : (
                <span className="text-(--app-foreground)">{title}</span>
            )}
        </span>
    );
}
