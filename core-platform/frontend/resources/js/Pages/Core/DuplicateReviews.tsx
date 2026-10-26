import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { AppTabNav, ListPagination, TBody, THead, Table, Td, Th, Tr, actionLinkClassName, laravelPageMeta } from '@/Components/ui';
import { AppDialog, Button, Card, EmptyState, FormField, Select, Textarea, toneSurfaceClassName, type PaginationMeta } from '@euisis/ui';

type Side = {
    employee_number: string;
    full_name: string;
    in_scope: boolean;
    id?: string;
    status?: string;
    date_of_birth?: string | null;
    phone?: string | null;
    email?: string | null;
    organization?: { name_en: string; name_am?: string | null } | null;
    position?: { title_en: string; title_am?: string | null } | null;
    url?: string;
};

type Review = {
    id: string;
    match_level: string;
    reasons: string[];
    decision: string;
    source: string;
    registration_reason: string | null;
    reason: string | null;
    notes: string | null;
    detected_by: string | null;
    detected_at: string | null;
    decided_by: string | null;
    decided_at: string | null;
    national_id_on_both: boolean;
    flagged: Side | null;
    matched: Side | null;
    can_decide: boolean;
    decide_url: string;
};

type Status = 'pending' | 'needs_more_information' | 'confirmed_duplicate' | 'not_duplicate';

const TABS: Status[] = ['pending', 'needs_more_information', 'confirmed_duplicate', 'not_duplicate'];

/**
 * Core › Duplicate reviews: each possible duplicate pair side by side, the
 * matching indicators, and the decision. A record outside the reader's scope
 * shows only its number and name; a national ID is never shown.
 */
export default function DuplicateReviews({ reviews, status, counts, url }: {
    reviews: { data: Review[]; meta: PaginationMeta };
    status: Status;
    counts: Record<Status, number>;
    url: string;
}) {
    const { t, locale } = useLocale();
    const label = (key: string) => t(`core.duplicates.${key}`);
    const [deciding, setDeciding] = useState<Review | null>(null);
    const form = useForm({ decision: 'not_duplicate', reason: '', notes: '' });
    const named = (item?: { name_en: string; name_am?: string | null } | null) => (item ? (locale === 'am' && item.name_am ? item.name_am : item.name_en) : '—');
    const titled = (item?: { title_en: string; title_am?: string | null } | null) => (item ? (locale === 'am' && item.title_am ? item.title_am : item.title_en) : '—');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (deciding) form.post(deciding.decide_url, { preserveScroll: true, onSuccess: () => setDeciding(null) });
    };

    const rows: Array<{ key: string; value: (side: Side) => React.ReactNode; matchOn?: string[] }> = [
        { key: 'employeeNumber', value: (side) => <span className="font-mono">{side.employee_number}</span> },
        { key: 'name', value: (side) => side.full_name, matchOn: ['name', 'name_and_date_of_birth'] },
        { key: 'dateOfBirth', value: (side) => <LocalizedDateDisplay value={side.date_of_birth ?? null} />, matchOn: ['name_and_date_of_birth'] },
        { key: 'phone', value: (side) => side.phone ?? '—', matchOn: ['phone'] },
        { key: 'email', value: (side) => side.email ?? '—', matchOn: ['email'] },
        { key: 'organization', value: (side) => named(side.organization) },
        { key: 'position', value: (side) => titled(side.position) },
        { key: 'status', value: (side) => (side.status ? <StatusBadge status={side.status} /> : '—') },
    ];

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')} />}>
            <Head title={label('title')} />

            <div className="space-y-5">
                <AppTabNav label={label('tabsLabel')} current={status} items={TABS.map((tab) => ({ key: tab, label: label(`tabs.${tab}`), count: counts[tab], href: url, data: { status: tab } }))} />

                {reviews.data.length === 0 ? (
                    <Card className="p-0"><EmptyState title={label('empty')} /></Card>
                ) : reviews.data.map((review) => (
                    <Card key={review.id} className="overflow-hidden p-0">
                        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-(--app-border) px-4 py-3">
                            <div>
                                <h2 className="text-sm font-semibold text-(--app-foreground)">{label(`levels.${review.match_level}`)}</h2>
                                <p className="mt-0.5 text-xs text-(--app-muted-foreground)">
                                    {label(`sources.${review.source}`)} · {review.detected_by ?? '—'} · <LocalizedDateDisplay value={review.detected_at} withTime />
                                </p>
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {review.reasons.map((reason) => <StatusBadge key={reason} status="warning" label={label(`reasons.${reason}`)} />)}
                                    {review.national_id_on_both && <StatusBadge status="info" label={label('nationalIdOnBoth')} />}
                                </div>
                            </div>
                            {review.can_decide && (
                                <Button size="sm" variant="primary" onClick={() => { form.reset(); form.clearErrors(); setDeciding(review); }}>{label('decide')}</Button>
                            )}
                        </div>

                        <Table className="min-w-[32rem]">
                            <THead>
                                <Tr>
                                    <Th className="w-40"><span className="sr-only">{label('field')}</span></Th>
                                    <Th>{label('flagged')}</Th>
                                    <Th>{label('matched')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {rows.map((row) => {
                                    const matching = row.matchOn?.some((reason) => review.reasons.includes(reason)) ?? false;
                                    return (
                                        <Tr key={row.key} className={matching ? toneSurfaceClassName('warning') : undefined}>
                                            <Th scope="row">{label(`fields.${row.key}`)}</Th>
                                            {[review.flagged, review.matched].map((side, index) => (
                                                <Td key={index}>
                                                    {side === null ? '—' : side.in_scope || ['employeeNumber', 'name'].includes(row.key) ? row.value(side)
                                                        : <span className="text-xs text-(--app-muted-foreground)">{label('outOfScope')}</span>}
                                                </Td>
                                            ))}
                                        </Tr>
                                    );
                                })}
                            </TBody>
                        </Table>

                        <div className="space-y-2 border-t border-(--app-border) px-4 py-3">
                            {(review.flagged?.url || review.matched?.url) && (
                                <div className="flex flex-wrap gap-3">
                                    {review.flagged?.url && <Link href={review.flagged.url} className={actionLinkClassName({ size: 'sm' })}>{label('openFlagged')}</Link>}
                                    {review.matched?.url && <Link href={review.matched.url} className={actionLinkClassName({ size: 'sm' })}>{label('openMatched')}</Link>}
                                </div>
                            )}
                            {review.registration_reason && <p className="text-sm"><span className="font-medium">{label('registrationReason')}</span> {review.registration_reason}</p>}
                            {review.decided_by && (
                                <p className="text-sm text-(--app-muted-foreground)">
                                    {label(`tabs.${review.decision}`)} · {review.decided_by} · <LocalizedDateDisplay value={review.decided_at} withTime />
                                    {review.reason && <span className="block text-(--app-foreground)">{review.reason}</span>}
                                    {review.notes && <span className="block">{review.notes}</span>}
                                </p>
                            )}
                        </div>
                    </Card>
                ))}

                <ListPagination bare meta={laravelPageMeta(reviews.meta)} onPageChange={(page) => router.get(url, { status, page }, { preserveScroll: true, preserveState: true })} />
            </div>

            <AppDialog
                open={deciding !== null}
                onClose={() => setDeciding(null)}
                title={label('decideTitle')}
                description={label('decideDescription')}
                footer={(
                    <>
                        <Button variant="outline" onClick={() => setDeciding(null)} disabled={form.processing}>{label('cancel')}</Button>
                        <Button variant="primary" type="submit" form="duplicate-decision-form" loading={form.processing}>{label('record')}</Button>
                    </>
                )}
            >
                <form id="duplicate-decision-form" onSubmit={submit} className="space-y-4">
                    <FormField label={label('decision')} required error={form.errors.decision}>
                        {({ id }) => (
                            <Select id={id} value={form.data.decision} onChange={(event) => form.setData('decision', event.target.value)}>
                                {(['not_duplicate', 'confirmed_duplicate', 'needs_more_information'] as const).map((decision) => <option key={decision} value={decision}>{label(`decisions.${decision}`)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField label={label('reason')} required description={label('reasonHelp')} error={form.errors.reason ?? (form.errors as Record<string, string | undefined>).review}>
                        {({ id, describedBy }) => <Textarea id={id} aria-describedby={describedBy} rows={3} maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />}
                    </FormField>
                    <FormField label={label('notes')} error={form.errors.notes}>
                        {({ id }) => <Textarea id={id} rows={2} maxLength={2000} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} />}
                    </FormField>
                </form>
            </AppDialog>
        </AuthenticatedLayout>
    );
}
