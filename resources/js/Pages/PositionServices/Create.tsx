import PositionServiceForm from './Form';
import type { JSX } from 'react';

type Props = {
    organizations: { id: string; name_en: string; name_am: string | null }[];
};

export default function PositionServiceCreate({ organizations, }: Props): JSX.Element {
    return <PositionServiceForm organizations={organizations} />;
}
