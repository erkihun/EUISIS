import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { Button, ListPagination, TableCard, Table, TBody, Td, Th, THead, Tr, buttonClassName, type LaravelPageMeta } from '@/Components/ui';

type Card = { id: string; employee: string; employee_number: string; card_number: string; status: string; changed_fields: string[]; changed_at: string; can_print: boolean };

export default function ReprintQueue({ cards, labels }: { cards: LaravelPageMeta & { data: Card[] }; labels: Record<string, string> & { fields: Record<string, string> } }) {
    return (
        <AuthenticatedLayout header={<PageHeader title={labels.reprint_queue} />}>
            <Head title={labels.reprint_queue} />
            <TableCard>
                {cards.data.length === 0 ? <EmptyState title={labels.empty} /> : (
                    <Table>
                        <THead>
                            <tr>
                                {[labels.fields.full_name, labels.fields.employee_number, labels.card_number, labels.changed_fields, labels.date, labels.status].map((label, i) => <Th key={i}>{label}</Th>)}
                                <Th align="right">{labels.action}</Th>
                            </tr>
                        </THead>
                        <TBody>
                            {cards.data.map((card) => (
                                <Tr key={card.id}>
                                    <Td className="font-medium">{card.employee}</Td>
                                    <Td muted className="tabular-nums">{card.employee_number}</Td>
                                    <Td className="font-mono text-xs">{card.card_number}</Td>
                                    <Td muted>{card.changed_fields.join(', ')}</Td>
                                    <Td muted className="whitespace-nowrap"><LocalizedDateDisplay value={card.changed_at} /></Td>
                                    <Td>{card.status}</Td>
                                    <Td align="right">
                                        <div className="flex flex-wrap justify-end gap-2">
                                            <Link href={route('id-cards.show', card.id)} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>{labels.review}</Link>
                                            {card.can_print && <Button size="sm" variant="outline" onClick={() => router.post(route('id-cards.prepare-print', card.id))}>{labels.prepare_print}</Button>}
                                        </div>
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                )}
                <ListPagination meta={cards} />
            </TableCard>
        </AuthenticatedLayout>
    );
}
