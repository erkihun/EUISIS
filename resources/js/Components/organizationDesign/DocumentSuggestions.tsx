import { Button, Card, CardHeader, CardTitle, FormField, Input, Select, Textarea, badgeClassName } from '@/Components/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useDesignText } from './ui';

export type SuggestionItem = { title: string; kind: 'function' | 'power'; legal_reference: string | null; uncertain: boolean; state: 'pending' | 'applied' | 'rejected'; record_id: string | null };
export type Suggestion = { id: string; status: string; items: SuggestionItem[]; notes: string | null; passage_chars: number; legal_source: string | null; created_by: string | null; created_at: string | null };

const STATUS_TONES: Record<string, 'success' | 'warning' | 'danger' | 'neutral'> = { ready: 'success', empty: 'neutral', unavailable: 'warning', failed: 'danger' };

/**
 * Document-assisted capture on a mandate: paste a passage of the source
 * document, receive suggested functions and powers marked when uncertain,
 * and apply each one — with a code and any correction a person makes — or
 * reject it. The assistant only suggests; nothing is created until applied.
 */
export default function DocumentSuggestions({ mandateId, suggestions, legalSources, canSuggest }: {
    mandateId: string;
    suggestions: Suggestion[];
    legalSources: { id: string; code: string; title_en: string; title_am: string | null }[];
    canSuggest: boolean;
}) {
    const { locale, tt } = useDesignText();
    const ask = useForm<{ passage: string; legal_source_id: string }>({ passage: '', legal_source_id: '' });
    const [applying, setApplying] = useState<{ suggestionId: string; index: number } | null>(null);
    const apply = useForm<{ code: string; title_en: string; title_am: string; legal_reference: string }>({ code: '', title_en: '', title_am: '', legal_reference: '' });

    function startApply(suggestionId: string, index: number, item: SuggestionItem) {
        setApplying({ suggestionId, index });
        apply.setData({ code: '', title_en: item.title, title_am: '', legal_reference: item.legal_reference ?? '' });
        apply.clearErrors();
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>{tt('suggestions.title')}</CardTitle>
                <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('suggestions.help')}</p>
            </CardHeader>
            {canSuggest && (
                <form onSubmit={(e) => { e.preventDefault(); ask.transform((d) => ({ ...d, legal_source_id: d.legal_source_id || null })); ask.post(route('organization-design.mandates.suggestions.store', mandateId), { preserveScroll: true, onSuccess: () => ask.reset('passage') }); }} className="space-y-3 border-b border-(--app-border) p-5">
                    <FormField id="suggestion-passage" label={tt('suggestions.passage')} error={ask.errors.passage} description={tt('suggestions.passageHelp')}>
                        {({ id }) => <Textarea id={id} rows={6} value={ask.data.passage} onChange={(e) => ask.setData('passage', e.target.value)} />}
                    </FormField>
                    {legalSources.length > 0 && (
                        <FormField id="suggestion-source" label={tt('suggestions.source')} error={ask.errors.legal_source_id}>
                            {({ id }) => (
                                <Select id={id} value={ask.data.legal_source_id} onChange={(e) => ask.setData('legal_source_id', e.target.value)}>
                                    <option value="">—</option>
                                    {legalSources.map((s) => <option key={s.id} value={s.id}>{s.code} · {locale === 'am' && s.title_am ? s.title_am : s.title_en}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <div className="flex justify-end"><Button type="submit" variant="primary" disabled={!ask.data.passage.trim()} loading={ask.processing}>{tt('suggestions.ask')}</Button></div>
                </form>
            )}
            <div className="divide-y divide-(--app-border)">
                {suggestions.length === 0 && <p className="px-5 py-4 text-sm text-(--app-muted-foreground)">{tt('suggestions.none')}</p>}
                {suggestions.map((s) => (
                    <div key={s.id} className="space-y-2 px-5 py-4">
                        <div className="flex flex-wrap items-center gap-2 text-xs text-(--app-muted-foreground)">
                            <span className={badgeClassName(STATUS_TONES[s.status] ?? 'neutral')}>{tt(`suggestions.status.${s.status}`)}</span>
                            <span>{s.created_by ?? '—'} · <LocalizedDateDisplay value={s.created_at} /> · {s.passage_chars} {tt('suggestions.chars')}{s.legal_source ? ` · ${s.legal_source}` : ''}</span>
                        </div>
                        {s.notes && <p className="text-xs text-(--app-muted-foreground)">{s.notes}</p>}
                        <ul className="space-y-2">
                            {s.items.map((item, index) => (
                                <li key={index} className="rounded-md border border-(--app-border) p-3 text-sm">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="text-(--app-foreground)">{item.title}</p>
                                            <p className="mt-0.5 flex flex-wrap gap-2 text-xs text-(--app-muted-foreground)">
                                                <span>{tt(`suggestions.kind.${item.kind}`)}</span>
                                                {item.legal_reference && <span>{item.legal_reference}</span>}
                                                {item.uncertain && <span className={badgeClassName('warning')}>{tt('suggestions.uncertain')}</span>}
                                                {item.state !== 'pending' && <span className={badgeClassName(item.state === 'applied' ? 'success' : 'neutral')}>{tt(`suggestions.state.${item.state}`)}</span>}
                                            </p>
                                        </div>
                                        {canSuggest && item.state === 'pending' && (
                                            <div className="flex shrink-0 gap-2">
                                                <Button size="sm" variant="outline" onClick={() => startApply(s.id, index, item)}>{tt('suggestions.apply')}</Button>
                                                <Button size="sm" variant="ghost" onClick={() => router.post(route('organization-design.suggestions.reject', [s.id, index]), {}, { preserveScroll: true })}>{tt('suggestions.reject')}</Button>
                                            </div>
                                        )}
                                    </div>
                                    {applying?.suggestionId === s.id && applying.index === index && (
                                        <form onSubmit={(e) => { e.preventDefault(); apply.post(route('organization-design.suggestions.apply', [s.id, index]), { preserveScroll: true, onSuccess: () => setApplying(null) }); }} className="mt-3 grid gap-3 border-t border-(--app-border) pt-3 sm:grid-cols-2">
                                            <FormField id={`apply-code-${index}`} label={tt('fields.code')} required error={apply.errors.code}>
                                                {({ id }) => <Input id={id} value={apply.data.code} maxLength={40} onChange={(e) => apply.setData('code', e.target.value.toUpperCase())} />}
                                            </FormField>
                                            <FormField id={`apply-ref-${index}`} label={tt('fields.legalReference')} error={apply.errors.legal_reference}>
                                                {({ id }) => <Input id={id} value={apply.data.legal_reference} onChange={(e) => apply.setData('legal_reference', e.target.value)} />}
                                            </FormField>
                                            <FormField id={`apply-en-${index}`} label={tt('fields.titleEn')} error={apply.errors.title_en}>
                                                {({ id }) => <Input id={id} value={apply.data.title_en} onChange={(e) => apply.setData('title_en', e.target.value)} />}
                                            </FormField>
                                            <FormField id={`apply-am-${index}`} label={tt('fields.titleAm')} error={apply.errors.title_am}>
                                                {({ id }) => <Input id={id} value={apply.data.title_am} onChange={(e) => apply.setData('title_am', e.target.value)} />}
                                            </FormField>
                                            <div className="flex justify-end gap-2 sm:col-span-2">
                                                <Button type="button" variant="outline" onClick={() => setApplying(null)}>{tt('actions.cancel')}</Button>
                                                <Button type="submit" variant="primary" disabled={!apply.data.code} loading={apply.processing}>{tt('suggestions.applyConfirm')}</Button>
                                            </div>
                                        </form>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </Card>
    );
}
