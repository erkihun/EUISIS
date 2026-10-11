import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Alert, Button, ListPagination, TableCard, Table, TBody, Td, Th, THead, Tr, buttonClassName, type LaravelPageMeta } from '@/Components/ui';

type Correction = { id: string; employee: string; employee_id: string; field: string; value: string; can_review: boolean };

export default function CorrectionRequests({ requests, labels }: { requests: LaravelPageMeta & { data: Correction[] }; labels: Record<string, string> & { fields: Record<string, string> } }) {
    const { errors } = usePage().props;
    const review = (id: string, decision: 'approved' | 'rejected') => router.post(route('employee-corrections.review', id), { decision }, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={labels.correction_queue} description={labels.complete_master_edit} />}>
            <Head title={labels.correction_queue} />
            {Object.keys(errors).length > 0 && <Alert tone="danger" className="mb-4">{Object.values(errors).join(' ')}</Alert>}
            <TableCard>
                {requests.data.length === 0 ? <EmptyState title={labels.empty} /> : (
                    <Table>
                        <THead>
                            <tr>
                                <Th>{labels.fields.full_name}</Th>
                                <Th>{labels.changed_fields}</Th>
                                <Th>{labels.requested_value}</Th>
                                <Th align="right">{labels.action}</Th>
                            </tr>
                        </THead>
                        <TBody>
                            {requests.data.map((item) => (
                                <Tr key={item.id}>
                                    <Td className="font-medium">{item.employee}</Td>
                                    <Td muted>{item.field}</Td>
                                    <Td className="break-words">{item.value}</Td>
                                    <Td align="right">
                                        {item.can_review && (
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Link href={route('employees.edit', item.employee_id)} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>{labels.review}</Link>
                                                <Button size="sm" variant="outline" onClick={() => review(item.id, 'approved')}>{labels.approve}</Button>
                                                <Button size="sm" variant="destructiveOutline" onClick={() => review(item.id, 'rejected')}>{labels.reject}</Button>
                                            </div>
                                        )}
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                )}
                <ListPagination meta={requests} />
            </TableCard>
        </AuthenticatedLayout>
    );
}
