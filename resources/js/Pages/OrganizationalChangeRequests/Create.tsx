import RequestForm from './RequestForm';
import type { JSX } from 'react';
import type { FormOptions } from '@/Components/organizationalChange/types';

type Props = {
    options: FormOptions;
    allowedTypes: string[];
    /** Organization and type preset by the link that opened the page (a structure page's "Add" button). */
    initial?: { organization_id: string; request_type: string };
};

/** Raise a new change request. It is saved as a draft until submitted. */
export default function OrganizationalChangeRequestsCreate({ options, allowedTypes, initial }: Props): JSX.Element {
    return <RequestForm options={options} allowedTypes={allowedTypes} initial={initial} />;
}
