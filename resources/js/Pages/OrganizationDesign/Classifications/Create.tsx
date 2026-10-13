import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useDesignText } from '@/Components/organizationDesign/ui';
import { Head } from '@inertiajs/react';
import ClassificationForm from './Form';

type Props = { scheme: string; revision: string; revisions: string[] };

export default function ClassificationsCreate({ scheme, revision }: Props) {
    const { tt } = useDesignText();
    const title = `${tt('classifications.add')} · ${tt(`classifications.schemes.${scheme}`)}`;

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={title} description={tt('classifications.description')} backHref={route('organization-design.classifications.index', { scheme, revision })} />}>
            <Head title={title} />
            <ClassificationForm scheme={scheme} revision={revision} record={null} />
        </AuthenticatedLayout>
    );
}
