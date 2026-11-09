import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useDesignText } from '@/Components/organizationDesign/ui';
import { Head } from '@inertiajs/react';
import ClassificationForm, { type ClassificationRecord } from './Form';

type Props = { classification: ClassificationRecord };

export default function ClassificationsEdit({ classification }: Props) {
    const { tt } = useDesignText();
    const title = `${tt('classifications.edit')} · ${tt(`classifications.schemes.${classification.scheme}`)}`;

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={title} description={`${classification.code} · ${classification.revision}`} backHref={route('organization-design.classifications.index', { scheme: classification.scheme, revision: classification.revision })} />}>
            <Head title={title} />
            <ClassificationForm key={classification.id} scheme={classification.scheme} revision={classification.revision} record={classification} />
        </AuthenticatedLayout>
    );
}
