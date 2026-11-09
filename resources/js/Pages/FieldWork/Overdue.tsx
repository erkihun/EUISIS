import ListPage, { type ListPageProps } from '@/Components/fieldWork/ListPage';
import type { JSX } from 'react';

export default function FieldWorkOverdue(props: ListPageProps): JSX.Element {
    return <ListPage {...props} titleKey="fieldWork.management.overdueTitle" descriptionKey="fieldWork.management.overdueDescription" emptyKey="fieldWork.management.emptyOverdue" showStatus={true} />;
}
