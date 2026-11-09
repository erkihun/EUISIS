import { useState, type FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ConfidenceBadge, SeverityBadge } from '@/Components/intelligence/Badges';
import { FilterChips, ReviewBadge, RiskBadge } from '@/Components/intelligence/StatusBadges';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { Button, Card, ListPagination, Select, Textarea } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { SrLabels } from '@/types/situationRoom';
import type { Paginated, RecommendationRow } from '@/types/intelligenceSystem';

type Props = {
    labels: SrLabels;
    filters: { review_status: string; risk: string; source_type: string };
    options: { reviews: string[]; risks: string[]; sources: string[] };
    recommendations: Paginated<RecommendationRow>;
    can: { review: boolean };
};

/** Every recommendation ever recorded, with its review (brief §52–53). Nothing is overwritten; a verdict sits beside the text. */
export default function RecommendationsIndex({ filters, options, recommendations, can }: PageProps<Props>) {
    const { t } = useLocale();
    const { sr } = useSituationLabels();
    const title = t('intelligence.areas.recommendations.title');
    const build = (patch: Partial<typeof filters>) => route('intelligence.recommendations.index', Object.fromEntries(Object.entries({ ...filters, ...patch }).filter(([, v]) => v !== '')));

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('intelligence.areas.recommendations.description')} backHref={route('intelligence.index')} />}>
            <Head title={`${title} · ${t('intelligence.title')}`} />

            <div className="space-y-5">
                <div className="space-y-2">
                    <FilterChips label={t('intelligence.recommendations.reviewStatus')} value={filters.review_status}
                        options={[{ key: '', label: t('intelligence.incidents.all') }, ...options.reviews.map((key) => ({ key, label: sr(`intelligence.review.${key}`) }))]} build={(key) => build({ review_status: key })} />
                    <FilterChips label={t('intelligence.recommendations.risk')} value={filters.risk}
                        options={[{ key: '', label: t('intelligence.incidents.all') }, ...options.risks.map((key) => ({ key, label: sr(`intelligence.risk.${key}`) }))]} build={(key) => build({ risk: key })} />
                </div>

                {recommendations.data.length === 0 ? (
                    <Card><p className="text-sm text-(--app-muted-foreground)">{t('intelligence.recommendations.none')}</p></Card>
                ) : (
                    <ul className="space-y-3">
                        {recommendations.data.map((r) => <li key={r.id}><RecommendationCard recommendation={r} canReview={can.review} /></li>)}
                    </ul>
                )}
                <ListPagination meta={recommendations} bare />
            </div>
        </AuthenticatedLayout>
    );
}

function RecommendationCard({ recommendation: r, canReview }: { recommendation: RecommendationRow; canReview: boolean }) {
    const { t } = useLocale();
    const { sr } = useSituationLabels();
    const [open, setOpen] = useState(false);
    const form = useForm({ review_status: 'useful', note: '' });
    const text = (field: 'title' | 'body') => (r.generated_by === 'rule' ? sr(r[field], r.params as Record<string, string | number>) : r[field]);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(route('intelligence.recommendations.review', r.id), { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    return (
        <Card className="space-y-2">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2 text-xs text-(--app-muted-foreground)">
                        <span>{sr(`intelligence.category.${r.category}`)}</span>
                        {r.generated_at && <><span aria-hidden="true">·</span><LocalizedDateDisplay value={r.generated_at} withTime /></>}
                        {r.incident && <><span aria-hidden="true">·</span><Link href={r.incident.href} className="font-medium text-primary hover:underline">{r.incident.reference}</Link><SeverityBadge severity={r.incident.severity} /></>}
                        <span aria-hidden="true">·</span><span>{r.generated_by === 'ai' ? t('intelligence.recommendations.byAi') : t('intelligence.recommendations.byRule')}</span>
                    </div>
                    <h2 className="mt-1 font-semibold text-(--app-foreground)">{text('title')}</h2>
                </div>
                <span className="flex flex-wrap gap-1.5"><RiskBadge risk={r.risk} />{r.confidence && <ConfidenceBadge confidence={r.confidence} />}<ReviewBadge status={r.review_status} /></span>
            </div>
            <p className="text-sm text-(--app-foreground)">{text('body')}</p>
            {r.reviewer && <p className="text-xs text-(--app-muted-foreground)">{r.reviewer}{r.reviewed_at ? ' · ' : ''}{r.reviewed_at && <LocalizedDateDisplay value={r.reviewed_at} withTime />}{r.review_note ? ` · ${r.review_note}` : ''}</p>}
            {canReview && (open ? (
                <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
                    <div className="w-48">
                        <Select aria-label={t('intelligence.recommendations.verdict')} value={form.data.review_status} onChange={(e) => form.setData('review_status', e.target.value)}>
                            {['useful', 'not_useful', 'accepted', 'rejected', 'incorrect_diagnosis'].map((s) => <option key={s} value={s}>{sr(`intelligence.review.${s}`)}</option>)}
                        </Select>
                    </div>
                    <div className="min-w-0 flex-1"><Textarea aria-label={t('intelligence.incidents.note')} className="min-h-10" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} /></div>
                    <Button type="submit" size="sm" loading={form.processing}>{t('intelligence.recommendations.record')}</Button>
                    <Button variant="ghost" size="sm" onClick={() => setOpen(false)}>{t('common.cancel')}</Button>
                </form>
            ) : (
                <div><Button variant="ghost" size="sm" onClick={() => setOpen(true)}>{t('intelligence.recommendations.review')}</Button></div>
            ))}
        </Card>
    );
}
