import { type FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ConfidenceBadge } from '@/Components/intelligence/Badges';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { Alert, Button, Card, CardTitle, FormField, Textarea, badgeClassName, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { SrLabels } from '@/types/situationRoom';
import type { AiStatus, KnowledgeAnswer, KnowledgeQuestionRow } from '@/types/intelligenceKnowledge';

type Props = {
    labels: SrLabels;
    answer: KnowledgeAnswer | null;
    questions: KnowledgeQuestionRow[];
    documents_count: number;
    ai: AiStatus;
    knowledge_enabled: boolean;
    knowledge_outbound: boolean;
    can: { manage: boolean };
};

/**
 * The question page (brief §20–22). An answer is either a cited synthesis
 * from the approved passages or those passages quoted as they are; every
 * piece of model or document text is rendered as plain text, never as markup.
 */
export default function KnowledgeIndex({ answer, questions, documents_count, ai, knowledge_enabled, knowledge_outbound, can }: PageProps<Props>) {
    const { t, locale } = useLocale();
    const { sr } = useSituationLabels();
    const title = t('intelligence.areas.knowledge.title');
    const synthesis = knowledge_enabled && ai.enabled && ai.configured && !ai.breaker_open && knowledge_outbound;

    const form = useForm({ question: answer?.question ?? '', locale });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('intelligence.knowledge.ask'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={title}
                    description={t('intelligence.areas.knowledge.description')}
                    backHref={route('intelligence.index')}
                    actions={can.manage ? <Link href={route('intelligence.knowledge.documents.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('intelligenceKnowledge.documents.manage')}</Link> : undefined}
                />
            }
        >
            <Head title={`${title} · ${t('intelligence.title')}`} />

            <div className="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div className="space-y-5">
                    {!knowledge_enabled ? (
                        <Alert tone="warning">{t('intelligenceKnowledge.status.disabled')}</Alert>
                    ) : synthesis ? (
                        <Alert tone="info">{t('intelligenceKnowledge.status.aiOn')}</Alert>
                    ) : (
                        <Alert tone="info">{t('intelligenceKnowledge.status.aiOff')}</Alert>
                    )}

                    <Card>
                        <form onSubmit={submit} className="space-y-4">
                            <FormField id="knowledge-question" label={t('intelligenceKnowledge.ask.label')} description={t('intelligenceKnowledge.ask.help')} error={form.errors.question}>
                                {({ id }) => (
                                    <Textarea
                                        id={id}
                                        value={form.data.question}
                                        onChange={(e) => form.setData('question', e.target.value)}
                                        placeholder={t('intelligenceKnowledge.ask.placeholder')}
                                        maxLength={1000}
                                        className="min-h-24"
                                        disabled={!knowledge_enabled}
                                    />
                                )}
                            </FormField>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <span className="text-xs text-(--app-muted-foreground)">
                                    {documents_count > 0 ? t('intelligenceKnowledge.ask.documentsVisible').replace('{count}', String(documents_count)) : t('intelligenceKnowledge.ask.noDocuments')}
                                </span>
                                <Button type="submit" loading={form.processing} disabled={!knowledge_enabled || form.data.question.trim().length < 5}>{t('intelligenceKnowledge.ask.submit')}</Button>
                            </div>
                        </form>
                    </Card>

                    {answer && <AnswerCard answer={answer} />}
                </div>

                <Card className="p-0">
                    <div className="border-b border-(--app-border) px-(--card-padding) py-3"><CardTitle>{t('intelligenceKnowledge.recent.title')}</CardTitle></div>
                    {questions.length === 0 ? (
                        <p className="px-(--card-padding) py-4 text-sm text-(--app-muted-foreground)">{t('intelligenceKnowledge.recent.none')}</p>
                    ) : (
                        <ul className="divide-y divide-(--app-border)">
                            {questions.map((q) => (
                                <li key={q.id} className="space-y-1 px-(--card-padding) py-3 text-sm">
                                    <p className="text-(--app-foreground)">{q.question}</p>
                                    <div className="flex flex-wrap items-center gap-1.5 text-xs text-(--app-muted-foreground)">
                                        <span className={badgeClassName(q.mode === 'ai' ? 'info' : q.mode === 'extractive' ? 'neutral' : 'warning')}>{sr(`intelligence.modes.${q.mode}`)}</span>
                                        <span className={badgeClassName(q.grounded ? 'success' : 'neutral')}>{q.grounded ? t('intelligenceKnowledge.answer.grounded') : t('intelligenceKnowledge.answer.notGrounded')}</span>
                                        <span>{t('intelligenceKnowledge.recent.citations').replace('{count}', String(q.citations))}</span>
                                        <span>· <LocalizedDateDisplay value={q.asked_at} withTime /></span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function AnswerCard({ answer }: { answer: KnowledgeAnswer }) {
    const { t } = useLocale();
    const { sr } = useSituationLabels();
    const section = (heading: string | null) => (heading ? `${t('intelligenceKnowledge.answer.section')}: ${heading}` : t('intelligenceKnowledge.answer.noSection'));

    return (
        <Card className="p-0">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-(--app-border) px-(--card-padding) py-3">
                <CardTitle>{t('intelligenceKnowledge.answer.title')}</CardTitle>
                <div className="flex flex-wrap gap-1.5">
                    <span className={badgeClassName(answer.mode === 'ai' ? 'info' : 'neutral')}>{sr(`intelligence.modes.${answer.mode}`)}</span>
                    <span className={badgeClassName(answer.grounded ? 'success' : 'warning')}>{answer.grounded ? t('intelligenceKnowledge.answer.grounded') : t('intelligenceKnowledge.answer.notGrounded')}</span>
                    {answer.confidence && <ConfidenceBadge confidence={answer.confidence} />}
                </div>
            </div>
            <div className="space-y-4 p-(--card-padding) text-sm">
                <p className="text-(--app-muted-foreground)"><span className="font-medium text-(--app-foreground)">{t('intelligenceKnowledge.answer.question')}: </span>{answer.question}</p>

                {answer.notice && <Alert tone={answer.mode === 'none' ? 'warning' : 'info'}>{sr(`intelligence.notices.${answer.notice}`)}</Alert>}

                {answer.mode === 'ai' && answer.answer && answer.grounded && (
                    <p className="whitespace-pre-line text-(--app-foreground)">{answer.answer}</p>
                )}
                {!answer.grounded && answer.mode !== 'none' && (
                    <p className="font-medium text-(--app-foreground)">{t('intelligenceKnowledge.answer.unsupported')}</p>
                )}
                {answer.mode === 'ai' && !answer.grounded && answer.answer && (
                    <p className="whitespace-pre-line text-(--app-muted-foreground)">{answer.answer}</p>
                )}

                {answer.mode === 'extractive' && answer.passages.length > 0 && (
                    <div>
                        <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('intelligenceKnowledge.answer.passages')}</h3>
                        <p className="mb-2 text-xs text-(--app-muted-foreground)">{t('intelligenceKnowledge.answer.passageHint')}</p>
                        <ol className="space-y-2">
                            {answer.passages.map((p) => (
                                <li key={p.chunk_id} className="rounded-control border border-(--app-border) p-3">
                                    <div className="mb-1 flex flex-wrap items-center gap-1.5 text-xs text-(--app-muted-foreground)">
                                        <span className="font-mono">[{p.n}]</span>
                                        <span className="font-medium text-(--app-foreground)">{p.title}</span>
                                        <span>· {section(p.heading)}</span>
                                        <span className={badgeClassName('neutral')}>{sr(`intelligence.classifications.${p.classification}`)}</span>
                                    </div>
                                    <blockquote className="whitespace-pre-line border-l-2 border-(--app-border) pl-3 text-(--app-foreground)">{p.content}</blockquote>
                                </li>
                            ))}
                        </ol>
                    </div>
                )}

                {answer.citations.length > 0 && (
                    <div>
                        <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('intelligenceKnowledge.answer.sources')}</h3>
                        <ul className="space-y-1">
                            {answer.citations.map((c) => (
                                <li key={`${c.n}-${c.chunk_id}`} className="flex flex-wrap items-baseline gap-1.5">
                                    <span className="font-mono text-xs text-(--app-muted-foreground)">[{c.n}]</span>
                                    <span className="font-medium text-(--app-foreground)">{c.title}</span>
                                    <span className="text-xs text-(--app-muted-foreground)">· {section(c.heading)}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {answer.run_id && <p className="text-xs text-(--app-muted-foreground)">{t('intelligenceKnowledge.answer.runId')}: <span className="font-mono">{answer.run_id}</span></p>}
            </div>
        </Card>
    );
}
