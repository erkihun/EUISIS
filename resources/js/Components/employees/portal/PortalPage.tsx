import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import '../../../../css/employee-portal.css';

/**
 * The one page frame for every My Portal screen.
 *
 * Same header bar as the rest of the application (title, optional
 * description, actions), full-width content, one vertical rhythm. Pages put
 * their content inside and never draw their own <h1> or menu: the sidebar
 * is the only navigation, and a back link is used only on detail pages that
 * sit under a list (an announcement, an application form).
 */
export default function PortalPage({ title, description, actions, backHref, children }: {
    title: string;
    description?: string;
    actions?: ReactNode;
    backHref?: string;
    children: ReactNode;
}) {
    return (
        <AuthenticatedLayout variant="portal" header={<PageHeader title={title} description={description} actions={actions} backHref={backHref} />}>
            <Head title={title} />
            <div className="portal-content mx-auto w-full max-w-screen-2xl space-y-6">{children}</div>
        </AuthenticatedLayout>
    );
}
