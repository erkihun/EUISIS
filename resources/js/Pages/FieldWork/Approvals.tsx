import ListPage, { type ListPageProps } from '@/Components/fieldWork/ListPage';
import type { JSX } from 'react';

export default function FieldWorkApprovals(props: ListPageProps): JSX.Element {
    return <ListPage {...props} titleKey="fieldWork.management.approvalsTitle" descriptionKey="fieldWork.management.approvalsDescription" emptyKey="fieldWork.management.emptyApprovals" showStatus={false} />;
}
