import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { Alert, badgeClassName, buttonClassName, type Tone } from '@/Components/ui';
import { LinkIcon, MailIcon, PhoneIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import type { PublicAssistantBlock } from '@/types/publicAssistant';
import PublicAssistantSourceCard from './PublicAssistantSourceCard';
import { hrefKind, type SafeHrefKind } from './shared';

const SERVICE_STATE_TONE: Record<string, Tone> = { available: 'success', degraded: 'warning', unavailable: 'danger' };
const NOTICE_TONE: Record<string, Tone> = { info: 'info', warning: 'warning' };

function knownKey(value: unknown, allowed: readonly string[]): string | null {
    return typeof value === 'string' && allowed.includes(value) ? value : null;
}

const cardClass = 'rounded-card border border-(--app-border) bg-(--app-surface) p-3 text-sm';
const linkClass = buttonClassName({ variant: 'outline', size: 'sm' });

/** A safe link as the kit's outline button: Inertia for app paths, a plain anchor for tel: and mailto:. */
function SafeLink({ href, kind, children }: { href: string; kind: SafeHrefKind; children: ReactNode }) {
    if (kind === 'app') {
        return <Link href={href} className={linkClass}>{children}</Link>;
    }
    // A plain anchor: the dialler or mail app opens only when the visitor taps it.
    return <a href={href} className={linkClass}>{children}</a>;
}

const CONTACT_ICON: Record<SafeHrefKind, typeof PhoneIcon> = { tel: PhoneIcon, mailto: MailIcon, app: LinkIcon };

/**
 * The deterministic blocks of a reply (docs/public-assistant/public-capabilities.md).
 * Text blocks are rendered by the message through the strict text renderer;
 * unknown types are skipped; no HTML and no URL auto-linking anywhere.
 */
export default function PublicAssistantBlocks({ blocks, messageId }: { blocks: PublicAssistantBlock[]; messageId: string }) {
    const { t } = useLocale();
    if (!Array.isArray(blocks) || blocks.length === 0) return null;

    return (
        <div className="space-y-2">
            {blocks.map((block, index) => {
                const key = `${messageId}:${index}`;
                switch (block.type) {
                    case 'text':
                        return null;
                    case 'bullets':
                        return Array.isArray(block.items) ? (
                            <ul key={key} className="list-disc space-y-0.5 ps-5">{block.items.map((item, i) => <li key={i}>{String(item)}</li>)}</ul>
                        ) : null;
                    case 'steps':
                        return Array.isArray(block.items) ? (
                            <ol key={key} className="list-decimal space-y-0.5 ps-5">{block.items.map((item, i) => <li key={i}>{String(item)}</li>)}</ol>
                        ) : null;
                    case 'sources':
                        return Array.isArray(block.items) && block.items.length > 0 ? (
                            <ul key={key} className="space-y-0.5">
                                {block.items.filter((item) => item && typeof item.title === 'string').map((item, i) => (
                                    <li key={i}><PublicAssistantSourceCard title={item.title} href={item.href} /></li>
                                ))}
                            </ul>
                        ) : null;
                    case 'actions': {
                        const items = Array.isArray(block.items)
                            ? block.items.flatMap((item) => {
                                const kind = hrefKind(item?.href);
                                return kind && typeof item.label === 'string' ? [{ ...item, kind }] : [];
                            })
                            : [];
                        return items.length > 0 ? (
                            <div key={key} className="flex flex-wrap gap-2">
                                {items.map((item, i) => (
                                    <SafeLink key={`${item.key ?? i}`} href={item.href} kind={item.kind}>{item.label}</SafeLink>
                                ))}
                            </div>
                        ) : null;
                    }
                    case 'contact': {
                        const items = Array.isArray(block.items)
                            ? block.items.flatMap((item) => {
                                const kind = hrefKind(item?.href);
                                if (!kind || typeof item.label !== 'string') return [];
                                // The declared kind must agree with the link: a "phone" that is not tel: is dropped.
                                const declared = item.kind === 'phone' ? 'tel' : item.kind === 'email' ? 'mailto' : item.kind === 'link' ? 'app' : null;
                                return declared === null || declared === kind ? [{ ...item, kind }] : [];
                            })
                            : [];
                        if (items.length === 0) return null;
                        return (
                            <ul key={key} className="flex flex-wrap gap-2">
                                {items.map((item, i) => {
                                    const Icon = CONTACT_ICON[item.kind];
                                    const verb = item.kind === 'tel' ? t('publicAssistant.blocks.call') : item.kind === 'mailto' ? t('publicAssistant.blocks.email') : t('publicAssistant.blocks.open');
                                    return (
                                        <li key={i}>
                                            <SafeLink href={item.href} kind={item.kind}>
                                                <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                                <span><span className="sr-only">{verb}: </span>{item.label}</span>
                                            </SafeLink>
                                        </li>
                                    );
                                })}
                            </ul>
                        );
                    }
                    case 'status': {
                        const stateKey = knownKey(block.state, ['available', 'degraded', 'unavailable']);
                        if (!stateKey) return null;
                        return (
                            <div key={key} className={cardClass}>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium text-(--app-foreground)">{t('publicAssistant.blocks.serviceStatus')}</span>
                                    <span className={badgeClassName(SERVICE_STATE_TONE[stateKey])}>{t(`publicAssistant.blocks.status.${stateKey}`)}</span>
                                </div>
                                {typeof block.text === 'string' && block.text !== '' && (
                                    <p className="mt-2 text-xs text-(--app-muted-foreground)">{block.text}</p>
                                )}
                            </div>
                        );
                    }
                    case 'notice':
                        return typeof block.text === 'string' ? (
                            <Alert key={key} tone={NOTICE_TONE[block.tone] ?? 'info'} className="p-3">{block.text}</Alert>
                        ) : null;
                    case 'disclaimer':
                        return typeof block.text === 'string' ? (
                            <p key={key} className="text-xs text-(--app-muted-foreground)">{block.text}</p>
                        ) : null;
                    default:
                        return null;
                }
            })}
        </div>
    );
}
