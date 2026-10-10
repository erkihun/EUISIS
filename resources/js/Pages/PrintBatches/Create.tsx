import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import CardStatusBadge from '@/Components/IdCards/CardStatusBadge';
import { Head, useForm, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState, FormEvent } from 'react';

import { buttonClassName } from '@/Components/ui';
type CardRow = {
    id: string;
    card_number: string;
    status: string;
    employee?: {
        employee_number: string;
        full_name: string;
        current_assignment?: { organization?: { name_en: string } | null } | null;
    } | null;
};

type PageProps = {
    pendingCards: CardRow[];
};

export default function PrintBatchCreate({ pendingCards }: PageProps) {
    const { t } = useLocale();
    const idCards = new Proxy({} as Record<string, string>, { get: (_, k) => t(`idCards.${String(k)}`) });
    const [selected, setSelected] = useState<string[]>([]);

    const form = useForm<{ card_ids: string[] }>({ card_ids: [] });

    function toggleCard(id: string) {
        setSelected((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
        );
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        form.setData('card_ids', selected);
        form.post(route('print-batches.store'));
    }

    return (
        <AuthenticatedLayout
            header={<PageHeader title={idCards.createPrintBatch ?? 'Create Print Batch'} description="" />}
        >
            <Head title={idCards.createPrintBatch ?? 'Create Print Batch'} />

            <div className="max-w-3xl">
                {pendingCards.length === 0 ? (
                    <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-8">
                        <EmptyState title={idCards.noPendingPrintCards ?? 'No approved cards pending print'} />
                    </div>
                ) : (
                    <form onSubmit={handleSubmit}>
                        <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                            <div className="border-b border-[color:var(--app-border)] p-4">
                                <p className="text-sm text-[color:var(--app-muted-foreground)]">
                                    {idCards.selectCardsForBatch ?? 'Select approved cards to add to this print batch'}
                                    {' '}({selected.length} selected)
                                </p>
                            </div>
                            <div className="divide-y divide-[color:var(--app-border)]">
                                {pendingCards.map((card) => (
                                    <label
                                        key={card.id}
                                        className="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-[color:var(--app-surface-muted)]"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={selected.includes(card.id)}
                                            onChange={() => toggleCard(card.id)}
                                            className="h-4 w-4 rounded border-gray-300 text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <p className="font-mono text-sm font-medium text-[color:var(--app-foreground)]">{card.card_number}</p>
                                            <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                                {card.employee?.employee_number} · {card.employee?.full_name}
                                                {card.employee?.current_assignment?.organization?.name_en
                                                    ? ` · ${card.employee.current_assignment.organization.name_en}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <CardStatusBadge status={card.status} />
                                    </label>
                                ))}
                            </div>
                        </div>

                        {form.errors.card_ids && (
                            <p className="mt-2 text-sm text-red-600">{form.errors.card_ids}</p>
                        )}

                        <div className="mt-4 flex justify-end gap-3">
                            <Link
                                href={route('print-batches.index')}
                                className="rounded-lg border border-[color:var(--app-border-strong)] px-4 py-2 text-sm text-[color:var(--app-foreground)] hover:bg-gray-50"
                            >
                                {t('common.cancel')}
                            </Link>
                            <button
                                type="submit"
                                disabled={selected.length === 0 || form.processing}
                                className={buttonClassName({ variant: 'primary' })}
                            >
                                {form.processing ? t('common.saving') : `${idCards.createPrintBatch} (${selected.length})`}
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
