import ListPage, { type ListPageProps } from '@/Components/fieldWork/ListPage';
import type { JSX } from 'react';

export default function FieldWorkTeam(props: ListPageProps): JSX.Element {
    return <ListPage {...props} titleKey="fieldWork.management.teamTitle" descriptionKey="fieldWork.management.teamDescription" emptyKey="fieldWork.management.emptyTeam" showStatus={true} />;
}
