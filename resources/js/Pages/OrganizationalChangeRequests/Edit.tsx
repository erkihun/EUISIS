import RequestForm from './RequestForm';
import type { JSX } from 'react';
import type { ChangeRequestDetail, FormOptions } from '@/Components/organizationalChange/types';

type Props = { request: ChangeRequestDetail; options: FormOptions; allowedTypes: string[] };

/**
 * Edit a draft, or answer a correction. The server refuses the write unless
 * the request is still in a requester-editable state.
 */
export default function OrganizationalChangeRequestsEdit({ request, options, allowedTypes }: Props): JSX.Element {
    return <RequestForm request={request} options={options} allowedTypes={allowedTypes} />;
}
