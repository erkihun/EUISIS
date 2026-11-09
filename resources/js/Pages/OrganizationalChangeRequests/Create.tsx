import RequestForm from './RequestForm';
import type { JSX } from 'react';
import type { FormOptions } from '@/Components/organizationalChange/types';

type Props = { options: FormOptions; allowedTypes: string[] };

/** Raise a new change request. It is saved as a draft until submitted. */
export default function OrganizationalChangeRequestsCreate({ options, allowedTypes }: Props): JSX.Element {
    return <RequestForm options={options} allowedTypes={allowedTypes} />;
}
