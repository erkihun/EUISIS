import ListPage, { type ListPageProps } from '@/Components/fieldWork/ListPage';
import type { JSX } from 'react';

export default function FieldWorkRequests(props: ListPageProps): JSX.Element {
    return <ListPage {...props} titleKey="fieldWork.management.requestsTitle" descriptionKey="fieldWork.management.requestsDescription" emptyKey="fieldWork.management.emptyRequests" showStatus={true} />;
}
