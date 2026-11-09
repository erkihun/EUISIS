import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Alert, Button, FormField, Select, Textarea, badgeClassName, buttonClassName, cx, type Tone } from '@/Components/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import type {
    AssistantBlock,
    CreateTicketPayload,
    IncidentBlock,
    StatusBlock,
    TicketCategory,
    TicketOfferBlock,
    TicketPriority,
} from '@/types/assistant';

const TICKET_CATEGORIES: TicketCategory[] = ['access', 'error', 'data', 'how_to', 'performance', 'other'];
const TICKET_PRIORITIES: TicketPriority[] = ['low', 'normal', 'high'];

const SEVERITY_TONE: Record<string, Tone> = { critical: 'danger', high: 'danger', medium: 'warning', low: 'info', info: 'neutral' };
const INCIDENT_STATUS_TONE: Record<string, Tone> = { open: 'danger', investigating: 'warning', mitigated: 'info', resolved: 'success', closed: 'neutral' };
const COMPONENT_STATE_TONE: Record<string, Tone> = { healthy: 'success', degraded: 'warning', unhealthy: 'danger', unknown: 'neutral' };
const COMPONENT_DOT: Record<string, string> = {
    healthy: 'bg-emerald-500',
    degraded: 'bg-amber-500',
    unhealthy: 'bg-red-500',
    unknown: 'bg-(--app-border-strong)',
};
const LABEL_TONE: Record<string, Tone> = { observed: 'success', calculated: 'info', hypothesis: 'warning', recommendation: 'neutral' };
const NOTICE_TONE: Record<string, Tone> = { info: 'info', warning: 'warning', error: 'danger' };

/** Only server-generated, app-relative links are followed. */
function isAppHref(href: unknown): href is string {
    return typeof href === 'string' && href.startsWith('/') && !href.startsWith('//');
}

function knownKey(value: unknown, allowed: readonly string[]): string {
    return typeof value === 'string' && allowed.includes(value) ? value : 'unknown';
}

export interface BlockContext {
    messageId: string;
    pending: boolean;
    usedOffers: string[];
    page: { route: string | null; path: string };
    onCreateTicket: (offerKey: string, payload: Omit<CreateTicketPayload, 'conversation_id'>) => Promise<boolean>;
}

const cardClass = 'rounded-card border border-(--app-border) bg-(--app-surface) p-3 text-sm';

function StatusCard({ block }: { block: StatusBlock }) {
    const { t } = useLocale();
    const overall = knownKey(block.state, ['healthy', 'degraded', 'unhealthy', 'unknown']);

    return (
        <div className={cardClass}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-medium text-(--app-foreground)">{t('assistant.blocks.systemStatus')}</span>
                <span className={badgeClassName(COMPONENT_STATE_TONE[overall])}>{t(`assistant.blocks.componentState.${overall}`)}</span>
            </div>
            {Array.isArray(block.components) && block.components.length > 0 && (
                <ul className="mt-2 space-y-1">
                    {block.components.map((component) => {
                        const stateKey = knownKey(component.state, ['healthy', 'degraded', 'unhealthy', 'unknown']);
                        return (
                            <li key={component.key} className="flex items-center justify-between gap-3">
                                <span className="flex min-w-0 items-center gap-2">
                                    <span aria-hidden="true" className={cx('h-2 w-2 shrink-0 rounded-full', COMPONENT_DOT[stateKey])} />
                                    <span className="truncate">{component.label}</span>
                                </span>
                                <span className="shrink-0 text-xs text-(--app-muted-foreground)">{t(`assistant.blocks.componentState.${stateKey}`)}</span>
                            </li>
                        );
                    })}
                </ul>
            )}
            {block.reason && <p className="mt-2 text-xs text-(--app-muted-foreground)">{block.reason}</p>}
            {block.updated_at && (
                <p className="mt-2 text-xs text-(--app-muted-foreground)">
                    {t('assistant.blocks.lastUpdated')}: <LocalizedDateDisplay value={block.updated_at} withTime />
                </p>
            )}
        </div>
    );
}

function IncidentCard({ block }: { block: IncidentBlock }) {
    const { t } = useLocale();
    const severity = knownKey(block.severity, ['info', 'low', 'medium', 'high', 'critical']);
    const status = knownKey(block.status, ['open', 'investigating', 'mitigated', 'resolved', 'closed']);

    return (
        <div className={cardClass}>
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-xs text-(--app-muted-foreground)">{block.reference}</span>
                <span className={badgeClassName(SEVERITY_TONE[severity] ?? 'neutral')}>{t(`assistant.blocks.severity.${severity}`)}</span>
                <span className={badgeClassName(INCIDENT_STATUS_TONE[status] ?? 'neutral')}>{t(`assistant.blocks.incidentStatus.${status}`)}</span>
            </div>
            <p className="mt-1.5 font-medium text-(--app-foreground)">{block.title}</p>
            {block.impact && <p className="mt-1 text-(--app-muted-foreground)">{block.impact}</p>}
            <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-(--app-muted-foreground)">
                {block.updated_at ? (
                    <span>{t('assistant.blocks.lastUpdated')}: <LocalizedDateDisplay value={block.updated_at} withTime /></span>
                ) : <span />}
                {isAppHref(block.href) && (
                    <Link href={block.href} className={buttonClassName({ variant: 'outline', size: 'xs' })}>
                        {t('assistant.blocks.viewIncident')}
                    </Link>
                )}
            </div>
        </div>
    );
}

function TicketOfferForm({ block, offerKey, context }: { block: TicketOfferBlock; offerKey: string; context: BlockContext }) {
    const { t } = useLocale();
    const used = context.usedOffers.includes(offerKey);
    const [category, setCategory] = useState<string>(TICKET_CATEGORIES.includes(block.category as TicketCategory) ? block.category : 'other');
    const [priority, setPriority] = useState<TicketPriority>('normal');
    const [description, setDescription] = useState<string>(typeof block.description === 'string' ? block.description : '');
    const [submitting, setSubmitting] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);

    if (used) {
        return <p className="text-xs text-(--app-muted-foreground)">{t('assistant.ticket.created')}</p>;
    }

    const submit = async () => {
        if (description.trim() === '') {
            setLocalError(t('assistant.ticket.descriptionRequired'));
            return;
        }
        setLocalError(null);
        setSubmitting(true);
        await context.onCreateTicket(offerKey, {
            description: description.trim(),
            category,
            priority,
            page: context.page,
            error_reference: block.error_reference ?? null,
            incident_reference: block.incident_reference ?? null,
        });
        setSubmitting(false);
    };

    return (
        <form
            className={cx(cardClass, 'space-y-3')}
            onSubmit={(event) => { event.preventDefault(); void submit(); }}
        >
            <p className="font-medium text-(--app-foreground)">{t('assistant.ticket.title')}</p>
            <p className="text-xs text-(--app-muted-foreground)">{t('assistant.ticket.intro')}</p>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <FormField label={t('assistant.ticket.category')}>
                    {({ id }) => (
                        <Select id={id} value={category} onChange={(event) => setCategory(event.target.value)} disabled={submitting || context.pending}>
                            {TICKET_CATEGORIES.map((key) => <option key={key} value={key}>{t(`assistant.ticket.categories.${key}`)}</option>)}
                        </Select>
                    )}
                </FormField>
                <FormField label={t('assistant.ticket.priority')}>
                    {({ id }) => (
                        <Select id={id} value={priority} onChange={(event) => setPriority(event.target.value as TicketPriority)} disabled={submitting || context.pending}>
                            {TICKET_PRIORITIES.map((key) => <option key={key} value={key}>{t(`assistant.ticket.priorities.${key}`)}</option>)}
                        </Select>
                    )}
                </FormField>
            </div>
            <FormField label={t('assistant.ticket.description')} required error={localError}>
                {({ id, describedBy, invalid }) => (
                    <Textarea
                        id={id}
                        aria-describedby={describedBy}
                        aria-invalid={invalid || undefined}
                        value={description}
                        onChange={(event) => setDescription(event.target.value)}
                        disabled={submitting || context.pending}
                        className="w-full"
                        maxLength={4000}
                    />
                )}
            </FormField>
            {block.incident_reference && (
                <p className="text-xs text-(--app-muted-foreground)">{t('assistant.ticket.linkedIncident')}: <span className="font-mono">{block.incident_reference}</span></p>
            )}
            <div className="flex justify-end">
                <Button type="submit" variant="primary" size="sm" loading={submitting} disabled={context.pending}>
                    {t('assistant.ticket.create')}
                </Button>
            </div>
        </form>
    );
}

export default function AssistantBlocks({ blocks, context }: { blocks: AssistantBlock[]; context: BlockContext }) {
    const { t } = useLocale();
    if (!Array.isArray(blocks) || blocks.length === 0) return null;

    return (
        <div className="space-y-2">
            {blocks.map((block, index) => {
                const key = `${context.messageId}:${index}`;
                switch (block.type) {
                    case 'text':
                        return null; // rendered by AssistantMessage through the strict text renderer
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
                            <ul key={key} className="space-y-0.5 text-xs text-(--app-muted-foreground)">
                                {block.items.map((item, i) => <li key={i}>{t('assistant.blocks.source')}: {item?.title}</li>)}
                            </ul>
                        ) : null;
                    case 'evidence':
                        return (
                            <div key={key} className={cardClass}>
                                <p className="font-medium text-(--app-foreground)">{block.title || t('assistant.blocks.evidence')}</p>
                                {Array.isArray(block.rows) && (
                                    <dl className="mt-2 space-y-1">
                                        {block.rows.map((row, i) => (
                                            <div key={i} className="flex justify-between gap-3">
                                                <dt className="text-(--app-muted-foreground)">{row.label}</dt>
                                                <dd className="text-end font-medium tabular-nums text-(--app-foreground)">{row.value ?? '—'}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                )}
                                {(block.source || block.updated_at) && (
                                    <p className="mt-2 text-xs text-(--app-muted-foreground)">
                                        {block.source && <span>{t('assistant.blocks.source')}: {block.source}</span>}
                                        {block.source && block.updated_at && ' · '}
                                        {block.updated_at && <span>{t('assistant.blocks.lastUpdated')}: <LocalizedDateDisplay value={block.updated_at} withTime /></span>}
                                    </p>
                                )}
                            </div>
                        );
                    case 'status':
                        return <StatusCard key={key} block={block} />;
                    case 'incident':
                        return <IncidentCard key={key} block={block} />;
                    case 'actions': {
                        const items = Array.isArray(block.items) ? block.items.filter((item) => isAppHref(item?.href)) : [];
                        return items.length > 0 ? (
                            <div key={key} className="flex flex-wrap gap-2">
                                {items.map((item) => (
                                    <Link key={item.key} href={item.href} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                        {item.label}
                                    </Link>
                                ))}
                            </div>
                        ) : null;
                    }
                    case 'ticket_offer':
                        return <TicketOfferForm key={key} block={block} offerKey={key} context={context} />;
                    case 'ticket':
                        return (
                            <div key={key} className={cardClass}>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium text-(--app-foreground)">{t('assistant.ticket.reference')}</span>
                                    <span className="font-mono">{block.reference}</span>
                                    <span className={badgeClassName('info')}>{block.status}</span>
                                </div>
                                <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-(--app-muted-foreground)">
                                    <span>{t('assistant.ticket.createdAt')}: <LocalizedDateDisplay value={block.created_at} withTime /></span>
                                    {isAppHref(block.href) && (
                                        <Link href={block.href} className={buttonClassName({ variant: 'outline', size: 'xs' })}>
                                            {t('assistant.blocks.viewTicket')}
                                        </Link>
                                    )}
                                </div>
                            </div>
                        );
                    case 'notice':
                        return (
                            <Alert key={key} tone={NOTICE_TONE[block.tone] ?? 'info'} className="p-3">
                                {block.text}
                            </Alert>
                        );
                    case 'disclaimer':
                        return <p key={key} className="text-xs text-(--app-muted-foreground)">{block.text}</p>;
                    case 'labels': {
                        const kind = knownKey(block.kind, ['observed', 'calculated', 'hypothesis', 'recommendation']);
                        return (
                            <p key={key} className="flex flex-wrap items-start gap-2">
                                <span className={badgeClassName(LABEL_TONE[kind] ?? 'neutral', 'shrink-0')}>
                                    {t(`assistant.blocks.labels.${kind === 'unknown' ? 'observed' : kind}`)}
                                </span>
                                <span>{block.text}</span>
                            </p>
                        );
                    }
                    default:
                        return null;
                }
            })}
        </div>
    );
}
