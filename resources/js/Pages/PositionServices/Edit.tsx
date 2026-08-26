import PositionServiceForm, { type FormRecord } from './Form';
import type { JSX } from 'react';

type Props = {
    record: FormRecord;
    organizations: { id: string; name_en: string; name_am: string | null }[];
    /** True once clients have rated this service; locks the Service ID. */
    hasFeedback: boolean;
};

export default function PositionServiceEdit({ record, organizations, hasFeedback }: Props): JSX.Element {
    return (
        <PositionServiceForm
            organizations={organizations}
            record={record}
            hasFeedback={hasFeedback}
        />
    );
}
